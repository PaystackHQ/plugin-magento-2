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

namespace Pstk\Paystack\Controller\Payment;

use Magento\Sales\Api\Data\OrderInterface;
use Pstk\Paystack\Gateway\Validator\TransactionValidator;

class Webhook extends AbstractPaystackStandard
{
    /**
     * How long after a transaction's paid_at/created_at an order-not-found result
     * stays transient (503, Paystack retries). Beyond this window it becomes
     * permanent (200): most charge.success events with no matching order will
     * never get one (Payment Pages, dashboard charges, subscription renewals,
     * another integration on the same Paystack account), and 503-ing those for
     * Paystack's full ~72h retry budget is what makes Paystack back off or
     * disable the endpoint, losing legitimate confirmations elsewhere.
     */
    private const ORDER_LOOKUP_RETRY_WINDOW_SECONDS = 900;

    /**
     * Reasons deliberately excluded from TransactionValidator::PERMANENT_FOR_WEBHOOK
     * (see its own comments) that must also never fall back to permanent once
     * ORDER_LOOKUP_RETRY_WINDOW_SECONDS elapses — a swapped key pair, a wrong
     * payment method on this order, an order that is not payable yet (or
     * anymore), or a transient failure while registering a passed settlement
     * is never fixed by the passage of time in the way MALFORMED/unrecognised
     * reasons might be, so these keep retrying (503) indefinitely, the same
     * treatment REASON_IN_FLIGHT gets:
     * - REASON_MODE_MISMATCH / REASON_WRONG_METHOD: the misconfiguration or
     *   method mixup is never fixed by a retry either, but recording it here
     *   (rather than as permanent) keeps the retry window open in case the
     *   underlying relation was simply not yet hydrated.
     * - REASON_ORDER_NOT_PAYABLE: a bank-transfer/USSD charge that is
     *   genuinely `pending` at callback time can settle minutes later via
     *   this webhook — if the customer used `/paystack/payment/recreate` in
     *   the meantime, the order is now `canceled`, and a late but genuine
     *   `charge.success` must not be permanently dropped (money captured
     *   with no order and no refund path). Deliberately NOT
     *   PERMANENT_FOR_WEBHOOK, unlike REASON_REFERENCE_BOUND_ELSEWHERE, which
     *   is not time-dependent.
     * - REASON_REGISTRATION_FAILED: a throw from PaymentSettlement::register()'s
     *   own bind/register/save steps after every check already passed — a
     *   transient DB/invoice issue should keep retrying, since money may
     *   already have moved.
     */
    private const NEVER_RECENCY_BOUNDED = [
        TransactionValidator::REASON_MODE_MISMATCH,
        TransactionValidator::REASON_WRONG_METHOD,
        TransactionValidator::REASON_ORDER_NOT_PAYABLE,
        TransactionValidator::REASON_REGISTRATION_FAILED,
    ];

    public function execute() {
        $result = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_RAW);
        try {

            // Retrieve the request's body and parse it as JSON
            $rawBody = $this->request->getContent();

            $this->logger->info("Paystack Webhook: received request");

            // Validate webhook signature
            $signature = $this->request->getHeader('X-Paystack-Signature') ?: '';
            if (!$signature || !$this->paystackClient->validateWebhookSignature($rawBody, $signature)) {
                $this->logger->warning("Paystack Webhook: signature validation failed");
                return $this->respond($result, 401, "auth failed");
            }

            $this->logger->info("Paystack Webhook: signature valid");

            $event = json_decode($rawBody);
            if (!$event) {
                // Malformed JSON cannot be fixed by a retry — permanent, not 503.
                return $this->respond($result, 200, "invalid payload");
            }

            $eventType = $event->event ?? null;
            $this->logger->info("Paystack Webhook: event type = " . ($eventType ?? 'unknown'));

            switch ($eventType) {
                case 'charge.success':
                    $eventStatus = $event->data->status ?? null;
                    if ('success' === $eventStatus) {
                        $eventReference = $event->data->reference ?? null;
                        if (!is_string($eventReference) || $eventReference === '') {
                            // A signed payload with no reference to verify cannot be
                            // fixed by a retry — Paystack will resend the same body.
                            $this->logger->warning("Paystack Webhook: signed payload missing data.reference");
                            return $this->respond($result, 200, "invalid payload");
                        }

                        $transactionDetails = $this->paystackClient->verifyTransaction($eventReference);

                        $reference = $transactionDetails->data->reference ?? null;
                        if (!is_string($reference) || $reference === '') {
                            // The verify response itself is unreadable — that is the
                            // gateway's answer, not our payload, so a retry may get a
                            // readable one. Transient, matching REASON_MALFORMED below.
                            $this->logger->warning("Paystack Webhook: verify response missing data.reference", ['event_reference' => $eventReference]);
                            return $this->respond($result, 503, "unverified");
                        }

                        $this->logger->info("Paystack Webhook: verified transaction", ['reference' => $reference]);

                        // Increment id, bound reference, then the verified metadata's
                        // orderId/quoteId — see WebhookOrderResolver for the order and why.
                        $order = $this->webhookOrderResolver->resolve($reference, $transactionDetails);

                        if ($order && $order->getId()) {
                            [$code, $body] = $this->resolveSettlement($transactionDetails, $order, $reference);
                            return $this->respond($result, $code, $body);
                        }

                        [$code, $body] = $this->resolveOrderNotFound($transactionDetails, $reference);
                        return $this->respond($result, $code, $body);
                    }
                    break;
            }
        } catch (\Pstk\Paystack\Gateway\Exception\ApiException $e) {
            // ApiException means Paystack itself answered with a permanent
            // gateway rejection (4xx status, body.status === false — e.g.
            // "Transaction reference not found", an auth failure), not a
            // transient network problem — but that is indistinguishable here
            // from a genuine timeout without some recency signal. No verify
            // response exists at this catch site (verifyTransaction() itself
            // threw before returning one), so the only available signal is
            // the webhook payload's own paid_at/created_at: $event already
            // has the same shape (->data->paid_at) isRecentTransaction()
            // reads, so it is reused as-is rather than inventing a new one.
            $this->logger->error("Paystack Webhook: gateway rejected verify call", ['error' => $e->getMessage()]);

            if ($this->isRecentTransaction($event)) {
                return $this->respond($result, 503, "unverified");
            }

            return $this->respond($result, 200, "unverified");
        } catch (\Throwable $exc) {
            // \Throwable, not \Exception: a TypeError (e.g. verifyTransaction()'s
            // non-nullable param) must not escape uncaught. Default transient so
            // Paystack retries; the message stays out of the response body since
            // it can carry curl_error()/gateway detail.
            $this->logger->error("Paystack Webhook: exception", ['error' => $exc->getMessage()]);
            return $this->respond($result, 503, "error");
        }

        // Every event type this switch does not handle, and a charge.success
        // whose payload status is not itself "success", falls through to here.
        // Neither is an error — Paystack sends many event types this endpoint
        // does not act on — so it is permanently accepted (200), never retried.
        return $this->respond($result, 200, "ignored");
    }

    /**
     * Sets $result's code/body and returns it. Mutates the one Raw instance
     * execute() created rather than building a new result, so every exit
     * point shares it.
     *
     * @param \Magento\Framework\Controller\Result\Raw $result
     * @param int $code
     * @param string $body
     * @return \Magento\Framework\Controller\Result\Raw
     */
    private function respond($result, int $code, string $body)
    {
        $result->setHttpResponseCode($code);
        $result->setContents($body);
        return $result;
    }

    /**
     * Decides the webhook response for a matched order: registers the
     * settlement (binding + capture) via PaymentSettlement, dispatches on
     * success. Rejection history is written by PaymentSettlement itself now
     * (uniformly across all three consumers), not here. Needs no result
     * object — a pure decision function.
     *
     * @param object $transactionDetails Full envelope PaystackApiClient::verifyTransaction() returns
     * @param OrderInterface $order
     * @param string $reference
     * @return array{0: int, 1: string}
     */
    private function resolveSettlement(object $transactionDetails, OrderInterface $order, string $reference): array
    {
        // The webhook payload's own status only got us this far; register()
        // re-verifies from scratch against a freshly re-fetched order — nothing
        // may advance the order until it settles this order, in this currency,
        // for at least this amount, and this exact reference isn't already
        // bound to a different order.
        $registration = $this->paymentSettlement->register(
            $transactionDetails,
            $order,
            $this->paystackClient->isTestMode()
        );
        $reason = $registration['reason'];
        // The settled instance register() actually mutated and saved — not
        // the stale $order this method was called with — so every use below
        // (the log, and the event dispatch) reads/reports post-capture state.
        $order = $registration['order'];

        if (null === $reason) {
            $this->logger->info("Paystack Webhook: order found, dispatching verify event", [
                'order_id' => $order->getIncrementId(),
                'current_status' => $order->getStatus(),
            ]);

            // Only reported to Paystack's plugin tracker once the settlement
            // gate has actually passed — logging it earlier told Paystack a
            // charge succeeded even for rejected settlements.
            $this->paystackClient->logTransactionSuccess($reference, $this->configProvider->getPublicKey());

            // dispatch the `payment_verify_after` event to update the order status
            $this->eventManager->dispatch('paystack_payment_verify_after', [
                "paystack_order" => $order,
            ]);

            return [200, "success"];
        }

        $this->logger->warning("Paystack Webhook: settlement check failed", [
            'reason' => $reason,
            'order_id' => $order->getIncrementId(),
        ]);

        if (TransactionValidator::REASON_IN_FLIGHT === $reason) {
            // Bank transfer / USSD still settling — the only reason that
            // genuinely resolves on its own. Retry unconditionally.
            return [503, "pending"];
        }

        // See TransactionValidator::PERMANENT_FOR_WEBHOOK for which reasons
        // are safe to report permanent.
        $isPermanentReason = $this->transactionValidator->isPermanentForWebhook($reason);

        if ($isPermanentReason) {
            return [200, "rejected"];
        }

        if (in_array($reason, self::NEVER_RECENCY_BOUNDED, true)) {
            // Deliberately excluded from PERMANENT_FOR_WEBHOOK (see
            // TransactionValidator's own comments): a swapped key pair or a
            // wrong payment method chosen for this order is never fixed by
            // the passage of time, so — unlike MALFORMED/unrecognised
            // reasons below — these must keep retrying indefinitely rather
            // than falling back to permanent once the recency window (900s)
            // has elapsed.
            return [503, "unverified"];
        }

        // MALFORMED/unrecognised reasons get the same recency bound as
        // order-not-found below: keep retrying while the failure could still
        // be a not-yet-hydrated relation or an unreadable response, stop
        // paying the retry cost once it no longer plausibly is.
        if ($this->isRecentTransaction($transactionDetails)) {
            return [503, "unverified"];
        }

        return [200, "unverified"];
    }

    /**
     * Decides the webhook response when no order matches a verified reference.
     *
     * @param object $transactionDetails Full envelope PaystackApiClient::verifyTransaction() returns
     * @param string $reference
     * @return array{0: int, 1: string}
     */
    private function resolveOrderNotFound(object $transactionDetails, string $reference): array
    {
        $this->logger->warning("Paystack Webhook: order not found for reference " . $reference);

        if ($this->isRecentTransaction($transactionDetails)) {
            // Recent transaction, no matching order yet — could be a
            // genuine order-save race; let Paystack retry within the window.
            return [503, "order not found"];
        }

        // Old transaction, still no order: very likely this will never
        // have a Magento order at all (see the class constant's comment).
        $this->logger->warning("Paystack Webhook: order not found and transaction not recent, treating as permanent", ['reference' => $reference]);
        return [200, "order not found"];
    }

    /**
     * Whether a verify response's transaction is recent enough that "no matching
     * order" should still be treated as transient. Reads data.paid_at, falling back
     * to data.created_at, then to "treat as recent" if neither is present or parses
     * — a missing/unreadable timestamp must not permanently strand a genuine payment.
     *
     * @param object $transactionDetails Full envelope PaystackApiClient::verifyTransaction() returns
     * @return bool
     */
    private function isRecentTransaction(object $transactionDetails): bool
    {
        $data = $transactionDetails->data ?? null;
        $timestamp = (is_object($data) ? ($data->paid_at ?? $data->created_at ?? null) : null);
        if (!is_string($timestamp) || $timestamp === '') {
            return true;
        }

        $parsed = strtotime($timestamp);
        if ($parsed === false) {
            return true;
        }

        return (time() - $parsed) <= self::ORDER_LOOKUP_RETRY_WINDOW_SECONDS;
    }
}
