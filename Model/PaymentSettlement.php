<?php

/**
 * Paystack Magento2 Module using \Magento\Payment\Model\Method\AbstractMethod
 * Copyright (C) 2019 Paystack.com
 *
 * This file is part of Pstk/Paystack.
 *
 * Pstk/Paystack is free software => you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http =>//www.gnu.org/licenses/>.
 */

namespace Pstk\Paystack\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Pstk\Paystack\Gateway\Validator\TransactionValidator;
use Psr\Log\LoggerInterface;

/**
 * Binds a Paystack reference to an order (D7 — closes "one charge settles two
 * orders" to a race window, not fully — see the parent plan's Risks section)
 * and registers the captured payment (D8 — `total_paid`, invoice, transaction
 * row), or rejects and records why.
 *
 * Deliberately does NOT dispatch `paystack_payment_verify_after` — that stays
 * owned by each of the three consumers, which already hold (and pass) their
 * own `$order` instance to the event; duplicating that here would mean this
 * class needs an event manager dependency it otherwise has no reason to hold.
 *
 * Every history comment this class writes directly is a static string built
 * from allow-listed scalars only — never the raw verify response — for the
 * same stored-XSS reason `TransactionValidator`'s own docblock gives: admin's
 * order status-history renderer permits a subset of HTML tags.
 */
class PaymentSettlement
{
    private const HISTORY_APPENDED = 'appended';
    private const HISTORY_PRESENT = 'present';

    /** @var TransactionValidator */
    private $transactionValidator;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var TransactionRepositoryInterface */
    private $transactionRepository;

    /** @var SearchCriteriaBuilder */
    private $searchCriteriaBuilder;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        TransactionValidator $transactionValidator,
        OrderRepositoryInterface $orderRepository,
        TransactionRepositoryInterface $transactionRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        LoggerInterface $logger
    ) {
        $this->transactionValidator = $transactionValidator;
        $this->orderRepository = $orderRepository;
        $this->transactionRepository = $transactionRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->logger = $logger;
    }

    /**
     * @param object         $transactionDetails Full envelope PaystackApiClient::verifyTransaction() returns
     * @param OrderInterface $order Caller's own instance — re-fetched fresh
     *     internally rather than trusted, since the caller only validated
     *     `settlementFailureReason()` against *its* instance, not against
     *     whatever else may have written to this order since.
     * @param bool           $testMode Must come from `PaystackApiClient::isTestMode()`.
     * @return array{reason: ?string, order: OrderInterface, historyRecorded: bool}
     *     `reason` is null once bound/registered (or already idempotently
     *     bound); otherwise the first failing REASON_* constant.
     *     `historyRecorded` is true on success/idempotent paths and when the
     *     rejection's history line is durably on the order; false when that
     *     write failed, or when nothing was recorded at all (early MALFORMED
     *     returns, REASON_IN_FLIGHT) — the webhook only acknowledges a
     *     rejection of a real charge once this is true (see
     *     TransactionValidator::REASON_ORDER_CLOSED). `order` is the settled
     *     instance this method actually mutated and saved (or, when nothing
     *     could safely be validated/registered against, the best available
     *     instance) — callers must dispatch/reference THIS instance, not
     *     their own stale parameter, since it may have `total_paid`, an
     *     invoice, and a transaction row this call just added that the
     *     caller's own instance does not.
     */
    public function register(object $transactionDetails, OrderInterface $order, bool $testMode): array
    {
        // The reference is both the binding key (D7) and the idempotency key,
        // so it is derived here, once, from Paystack's own echoed value —
        // never from a caller-supplied fragment (e.g. the client-supplied
        // REST path segment) — so all three consumers bind against the exact
        // same value. Same guard Webhook.php already applies to this field.
        $data = $transactionDetails->data ?? null;
        $reference = is_object($data) ? ($data->reference ?? null) : null;
        if (!is_string($reference) || $reference === '') {
            $this->logger->warning('Paystack: settlement registration missing readable reference', [
                'order_increment_id' => $order->getIncrementId(),
            ]);
            return ['reason' => TransactionValidator::REASON_MALFORMED, 'order' => $order, 'historyRecorded' => false];
        }

        // Step 1: re-fetch fresh, guard the type, re-verify from scratch —
        // closes the TOCTOU gap: the caller validated settlementFailureReason()
        // against its own $order instance, which this method must not trust.
        try {
            $freshOrder = $this->orderRepository->get($order->getEntityId());
        } catch (NoSuchEntityException | InputException $exc) {
            // A definite negative answer — the repository is certain this
            // order does not exist (or the id is unusable) — not a transient
            // failure, so this must NOT fall back to the caller's own,
            // unvalidated-by-the-repository $order below.
            $this->logger->warning('Paystack: settlement registration order lookup came back definitively negative', [
                'reference' => $reference,
                'order_increment_id' => $order->getIncrementId(),
            ]);
            return ['reason' => TransactionValidator::REASON_MALFORMED, 'order' => $order, 'historyRecorded' => false];
        } catch (\Throwable $exc) {
            // Genuinely transient (e.g. a DB hiccup) — fall through to the
            // type guard below, which falls back to the caller's own concrete
            // instance if it has one.
            $freshOrder = null;
        }

        if (!$freshOrder instanceof Order) {
            $freshOrder = $order instanceof Order ? $order : null;
        }

        if ($freshOrder === null) {
            // No usable concrete order to validate, register, or write a
            // rejection comment against — cannot safely proceed, and cannot
            // record history either (there is nothing to write it to).
            $this->logger->warning('Paystack: settlement registration could not obtain a usable order instance', [
                'reference' => $reference,
            ]);
            return ['reason' => TransactionValidator::REASON_MALFORMED, 'order' => $order, 'historyRecorded' => false];
        }

        $reason = $this->transactionValidator->settlementFailureReason($transactionDetails, $freshOrder, $testMode);
        if ($reason !== null) {
            // REASON_IN_FLIGHT is not a rejection — it means the charge is
            // still settling (bank transfer/USSD) and Paystack (or the
            // customer) will be back. Recording it as merchant-visible
            // history on every retry of an event that resolves on its own
            // would be noise, not signal — carried over from the deny-list
            // behavior Webhook.php's own recordHistory() used to have before
            // this was hoisted here.
            $recorded = false;
            if ($reason !== TransactionValidator::REASON_IN_FLIGHT) {
                $recorded = $this->recordRejection($freshOrder, $reference, $reason, $transactionDetails);
            }
            return ['reason' => $reason, 'order' => $freshOrder, 'historyRecorded' => $recorded];
        }

        // Step 2: cross-order binding check — has this exact reference
        // already been bound to a DIFFERENT order?
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('txn_id', $reference, 'eq')
            ->create();
        $existing = $this->transactionRepository->getList($searchCriteria)->getItems();

        foreach ($existing as $item) {
            if ((int) $item->getOrderId() !== (int) $freshOrder->getEntityId()) {
                $recorded = $this->recordRejection(
                    $freshOrder,
                    $reference,
                    TransactionValidator::REASON_REFERENCE_BOUND_ELSEWHERE,
                    $transactionDetails
                );
                return [
                    'reason' => TransactionValidator::REASON_REFERENCE_BOUND_ELSEWHERE,
                    'order' => $freshOrder,
                    'historyRecorded' => $recorded,
                ];
            }
        }

        // Step 3: same-order idempotent short-circuit — explicit compound key
        // (txn_id === $reference AND order_id === $freshOrder's id), not a
        // vaguer "already has a capture" key: a *different*, new reference
        // against an already-registered order must not silently return
        // "settled" for a charge that was never applied to anything.
        foreach ($existing as $item) {
            if ((int) $item->getOrderId() === (int) $freshOrder->getEntityId()) {
                $this->logger->info('Paystack: idempotent re-verify, reference already bound to this order', [
                    'reference' => $reference,
                    'order_increment_id' => $freshOrder->getIncrementId(),
                ]);
                return ['reason' => null, 'order' => $freshOrder, 'historyRecorded' => true];
            }
        }

        // Step 4: order-state guard — only reached once binding/idempotency is
        // resolved. registerCaptureNotification() flips state as a side
        // effect, so a re-verify of an already-advanced order must hit step
        // 3's no-op before this guard could mistake it for not-payable.
        if (!$this->transactionValidator->isPayable($freshOrder)) {
            // Terminal vs not-yet: see TransactionValidator::REASON_ORDER_CLOSED
            // / REASON_ORDER_NOT_PAYABLE.
            $reason = $this->transactionValidator->isClosedForPayment($freshOrder)
                ? TransactionValidator::REASON_ORDER_CLOSED
                : TransactionValidator::REASON_ORDER_NOT_PAYABLE;
            $recorded = $this->recordRejection($freshOrder, $reference, $reason, $transactionDetails);
            return ['reason' => $reason, 'order' => $freshOrder, 'historyRecorded' => $recorded];
        }

        // Steps 5-7: bind + register + persist. Unguarded, a throw here (e.g.
        // prepareInvoice() raising a LocalizedException on some order shape)
        // would escape uncaught with zero merchant-visible trace of a real
        // charge having come through — wrapped so that can never happen.
        try {
            // setTransactionId() MUST run before registerCaptureNotification():
            // Transaction\Manager::generateTransactionId() returns the
            // already-set transaction id unchanged only when one is already
            // set before the capture call runs — otherwise it gets a
            // `-capture` suffix and the binding lookup above silently stops
            // matching. Load-bearing for D7 — never reorder these two lines.
            $freshOrder->getPayment()->setTransactionId($reference);
            // Base currency, not getGrandTotal() (display currency) — using
            // the display amount silently reopens D8 on any display != base
            // store.
            $freshOrder->getPayment()->registerCaptureNotification($freshOrder->getBaseTotalDue(), true);

            // Overpayment recording (comment only, no separate save — folded
            // into the single save below), hoisted here from Webhook.php so
            // all three consumers get it, reusing the same dedupe-marker logic.
            if ($this->transactionValidator->isOverpayment($transactionDetails, $freshOrder)) {
                $paidAmount = $this->transactionValidator->paidSubunits($transactionDetails);
                $expectedSubunits = $this->transactionValidator->expectedSubunits($freshOrder);
                $this->appendHistoryComment(
                    $freshOrder,
                    $reference,
                    'overpaid',
                    sprintf(
                        'Paystack: payment overpaid — paid %d, expected %d, reference %s',
                        $paidAmount,
                        $expectedSubunits,
                        $reference
                    )
                );
            }

            // Persist — a single save covers both the registration and any
            // overpayment comment above.
            $this->orderRepository->save($freshOrder);
        } catch (\Throwable $exc) {
            // Money may already have moved by this point (setTransactionId()/
            // registerCaptureNotification() may have run), so this is never
            // safe to invite a second payment for — but it IS safe to keep
            // retrying the same webhook event, since a transient DB/invoice
            // issue may clear. Allow-listed context only — the raw exception
            // message is never reflected anywhere customer-facing.
            $this->logger->error('Paystack: settlement registration failed after binding checks passed', [
                'reference' => $reference,
                'order_increment_id' => $freshOrder->getIncrementId(),
            ]);
            $recorded = $this->recordRejection(
                $freshOrder,
                $reference,
                TransactionValidator::REASON_REGISTRATION_FAILED,
                $transactionDetails
            );
            return [
                'reason' => TransactionValidator::REASON_REGISTRATION_FAILED,
                'order' => $freshOrder,
                'historyRecorded' => $recorded,
            ];
        }

        return ['reason' => null, 'order' => $freshOrder, 'historyRecorded' => true];
    }

    /**
     * The stable (reference, reason) marker embedded in a history comment —
     * carried over byte-for-byte from Webhook.php's own historyMarker(),
     * whose 72-hour-retry dedupe depends on this exact format string.
     *
     * @param string $reference
     * @param string $reasonKey
     * @return string
     */
    private function historyMarker(string $reference, string $reasonKey): string
    {
        return sprintf('[paystack:%s:%s]', $reference, $reasonKey);
    }

    /**
     * Writes the merchant-visible rejection history comment (with the dedupe
     * marker) and logs, for every rejection this class produces, on every
     * consumer — not just the webhook, which is where this used to live
     * exclusively.
     *
     * @param Order  $order
     * @param string $reference
     * @param string $reason
     * @param object $transactionDetails Full envelope PaystackApiClient::verifyTransaction() returns
     * @return bool True when the rejection is durably on the order's history
     *     (see writeHistory()).
     */
    private function recordRejection(Order $order, string $reference, string $reason, object $transactionDetails): bool
    {
        $paidAmount = $this->transactionValidator->paidSubunits($transactionDetails) ?? 'unknown';
        // Guarded the same way TransactionValidator::allowListedContext()
        // guards `reference` — a non-scalar `currency` (array/object) must
        // not throw during string conversion inside the sprintf() below,
        // which would turn a settlement rejection into an uncaught 503 loop.
        $rawCurrency = $transactionDetails->data->currency ?? null;
        $paidCurrency = is_scalar($rawCurrency) ? substr((string) $rawCurrency, 0, 100) : 'unknown';
        $expectedSubunits = $this->transactionValidator->expectedSubunits($order);

        $isClosed = TransactionValidator::REASON_ORDER_CLOSED === $reason;

        if ($isClosed) {
            $lead = sprintf('received after this order was closed (%s)', $reason);
            $suffix = '. If this charge is not already reflected on the order, refund or reconcile it.';
        } else {
            // Two wordings, matching Webhook.php's pre-hoist recordHistory():
            // "rejected" for reasons a retry can never fix, "not applied yet
            // (retry pending)" for reasons that may still resolve on their own
            // (e.g. REASON_MODE_MISMATCH) — collapsing both to "rejected"
            // regardless of permanence would misrepresent a still-retrying
            // reason as decided.
            $verdict = $this->transactionValidator->isPermanentForWebhook($reason)
                ? 'rejected'
                : 'not applied (retry pending)';
            $lead = sprintf('%s — %s', $verdict, $reason);
            $suffix = '';
        }

        return $this->writeHistory(
            $order,
            $reference,
            $reason,
            sprintf(
                'Paystack: payment %s: paid %s %s, expected %s %s, reference %s%s',
                $lead,
                $paidAmount,
                $paidCurrency,
                $expectedSubunits,
                $order->getOrderCurrencyCode(),
                $reference,
                $suffix
            ),
            $isClosed
        );
    }

    /**
     * Appends a merchant-visible order-history comment (no save — the caller
     * decides when to persist), but only once per (reference, reason) pair —
     * a retry of an already-recorded rejection (or a re-verify hitting the
     * same overpayment) must not append a near-identical comment every time.
     * A failed lookup of the existing history, or a failed append, must not
     * escape this class — log and continue.
     *
     * @param Order  $order
     * @param string $reference
     * @param string $reasonKey
     * @param string $comment
     * @return string|null HISTORY_APPENDED when a new comment was appended,
     *     HISTORY_PRESENT for a dedupe no-op, null for a failed write —
     *     tells writeHistory() below whether a save is warranted and whether
     *     the record already exists.
     */
    private function appendHistoryComment(Order $order, string $reference, string $reasonKey, string $comment): ?string
    {
        $marker = $this->historyMarker($reference, $reasonKey);
        try {
            foreach ($order->getStatusHistories() ?? [] as $history) {
                $existingComment = $history->getComment() ?? '';
                if (strpos($existingComment, $marker) !== false) {
                    return self::HISTORY_PRESENT;
                }
            }

            $order->addStatusToHistory($order->getStatus(), $comment . ' ' . $marker);
            return self::HISTORY_APPENDED;
        } catch (\Throwable $exc) {
            $this->logger->error('Paystack: failed to write order history', ['error' => $exc->getMessage()]);
            return null;
        }
    }

    /**
     * Appends the history comment, immediately persists it, and logs the
     * rejection — used only by the rejection path, which returns before
     * reaching register()'s own single save. Skips the save entirely when
     * nothing was actually appended (a dedupe no-op or a failed write). A
     * failed save is logged and reported through the return value, never
     * thrown: the caller's `historyRecorded` tells the webhook whether the
     * rejection is durably on the order, and it keeps retrying a real-money
     * permanent rejection until it is (see Webhook::resolveSettlement).
     *
     * The log level is chosen here, once the outcome is known. A failed write
     * is always critical — a real charge acknowledged later would otherwise
     * leave no trace. A closed order that took a real charge is critical the
     * first time it is recorded (the money is with Paystack and no order holds
     * it, so an alert must see it) and info on replays of an already-recorded
     * one; every other reason is a warning.
     *
     * @param Order  $order
     * @param string $reference
     * @param string $reasonKey
     * @param string $comment
     * @param bool   $isClosed Whether the reason is REASON_ORDER_CLOSED — picks the log levels above.
     * @return bool True when the (reference, reason) record is durably on the
     *     order: the marker was already present, or it was appended and saved.
     */
    private function writeHistory(
        Order $order,
        string $reference,
        string $reasonKey,
        string $comment,
        bool $isClosed
    ): bool {
        $context = [
            'reason' => $reasonKey,
            'reference' => $reference,
            'order_increment_id' => $order->getIncrementId(),
        ];
        $message = 'Paystack: settlement registration rejected';

        $appended = $this->appendHistoryComment($order, $reference, $reasonKey, $comment);
        if ($appended === self::HISTORY_PRESENT) {
            $this->logger->{$isClosed ? 'info' : 'warning'}($message, $context);
            return true;
        }
        if ($appended !== self::HISTORY_APPENDED) {
            $this->logger->critical($message, $context);
            return false;
        }

        try {
            $this->orderRepository->save($order);
        } catch (\Throwable $exc) {
            $this->logger->error('Paystack: failed to write order history', ['error' => $exc->getMessage()]);
            $this->logger->critical($message, $context);
            return false;
        }

        $this->logger->{$isClosed ? 'critical' : 'warning'}($message, $context);
        return true;
    }
}
