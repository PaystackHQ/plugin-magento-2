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

    public function execute() {
        $resultFactory = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_RAW);
        try {

            // Retrieve the request's body and parse it as JSON
            $rawBody = $this->request->getContent();

            $this->logger->info("Paystack Webhook: received request");

            // Validate webhook signature
            $signature = $this->request->getHeader('X-Paystack-Signature') ?: '';
            if (!$signature || !$this->paystackClient->validateWebhookSignature($rawBody, $signature)) {
                $this->logger->warning("Paystack Webhook: signature validation failed");
                $resultFactory->setHttpResponseCode(401);
                $resultFactory->setContents("auth failed");
                return $resultFactory;
            }

            $this->logger->info("Paystack Webhook: signature valid");

            $event = json_decode($rawBody);
            if (!$event) {
                // Malformed JSON cannot be fixed by a retry — permanent, not 503.
                $resultFactory->setHttpResponseCode(200);
                $resultFactory->setContents("invalid payload");
                return $resultFactory;
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
                            $resultFactory->setHttpResponseCode(200);
                            $resultFactory->setContents("invalid payload");
                            return $resultFactory;
                        }

                        $transactionDetails = $this->paystackClient->verifyTransaction($eventReference);

                        $reference = $transactionDetails->data->reference ?? null;
                        if (!is_string($reference) || $reference === '') {
                            // The verify response itself is unreadable — that is the
                            // gateway's answer, not our payload, so a retry may get a
                            // readable one. Transient, matching REASON_MALFORMED below.
                            $this->logger->warning("Paystack Webhook: verify response missing data.reference", ['event_reference' => $eventReference]);
                            $resultFactory->setHttpResponseCode(503);
                            $resultFactory->setContents("unverified");
                            return $resultFactory;
                        }

                        $this->logger->info("Paystack Webhook: verified transaction", ['reference' => $reference]);

                        $order = $this->orderInterface->loadByIncrementId($reference);

                        // In popup mode, reference is generated by Paystack and we provided quoteId instead
                        if ((!$order || !$order->getId()) && isset($event->data->metadata->quoteId)) {
                            $this->logger->info("Paystack Webhook: order not found by reference, searching by quoteId", ['quoteId' => $event->data->metadata->quoteId]);
                            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                            $searchCriteriaBuilder = $objectManager->create('Magento\Framework\Api\SearchCriteriaBuilder');
                            $searchCriteria = $searchCriteriaBuilder->addFilter('quote_id', $event->data->metadata->quoteId, 'eq')->create();
                            $items = $this->orderRepository->getList($searchCriteria);
                            if ($items->getTotalCount() == 1) {
                                $order = $items->getFirstItem();
                            }
                        }

                        if ($order && $order->getId()) {
                            // The webhook payload's own status only got us this far; the
                            // re-verified response is the trustworthy statement of what
                            // actually happened to the money — nothing may advance the
                            // order until it settles this order, in this currency, for at
                            // least this amount.
                            $reason = $this->transactionValidator->settlementFailureReason($transactionDetails, $order);
                            $expectedSubunits = $this->transactionValidator->expectedSubunits($order);

                            if (null === $reason) {
                                $this->logger->info("Paystack Webhook: order found, dispatching verify event", [
                                    'order_id' => $order->getIncrementId(),
                                    'current_status' => $order->getStatus(),
                                ]);

                                // Only reported to Paystack's plugin tracker once the
                                // settlement gate has actually passed — logging it earlier
                                // (as this call used to, before verifyTransaction re-checked
                                // status/amount/currency) told Paystack a charge succeeded
                                // even for rejected settlements.
                                $this->paystackClient->logTransactionSuccess($reference, $this->configProvider->getPublicKey());

                                if ($this->transactionValidator->isOverpayment($transactionDetails, $order)) {
                                    // Normal for Paystack's customer-bears-fee configuration.
                                    // This is the merchant's only visibility into the surplus.
                                    $paidAmount = $transactionDetails->data->amount ?? null;
                                    $this->recordHistory(
                                        $order,
                                        $reference,
                                        'overpaid',
                                        sprintf(
                                            'Paystack: payment overpaid — paid %d, expected %d, reference %s',
                                            (int) $paidAmount,
                                            $expectedSubunits,
                                            (string) $reference
                                        )
                                    );
                                }

                                // dispatch the `payment_verify_after` event to update the order status
                                $this->eventManager->dispatch('paystack_payment_verify_after', [
                                    "paystack_order" => $order,
                                ]);

                                $resultFactory->setHttpResponseCode(200);
                                $resultFactory->setContents("success");
                                return $resultFactory;
                            }

                            $this->logger->warning("Paystack Webhook: settlement check failed", [
                                'reason' => $reason,
                                'order_id' => $order->getIncrementId(),
                            ]);

                            // Permanent allow-list: these reasons describe what the money
                            // actually did, and a retry of the exact same event cannot
                            // change that answer. Everything else (REASON_MALFORMED,
                            // REASON_WRONG_METHOD, and any reason this switch does not
                            // recognise) is transient: it means we could not read the
                            // gateway's answer, or read a lazily-loaded relation
                            // ($order->getPayment()) that might not yet be hydrated — not
                            // that the payment failed. Default to transient — fail safe,
                            // since a permanent 200 destroys the retry window for a real
                            // payment. Owned by TransactionValidator::isPermanentForWebhook()
                            // so this classification has one definition, not a second copy
                            // of the allow-list maintained here.
                            $isPermanentReason = $this->transactionValidator->isPermanentForWebhook($reason);

                            if (TransactionValidator::REASON_IN_FLIGHT !== $reason) {
                                // Deny-list: every non-null reason except IN_FLIGHT gets a
                                // history comment. IN_FLIGHT is a normal transient state
                                // (bank transfer / USSD still settling) that would fire on
                                // every retry, not an incident — recording it would spam
                                // history. MALFORMED and WRONG_METHOD are the reasons most
                                // likely to fire on a payment the customer really made, and
                                // without this they leave zero admin-visible trace. The
                                // lead-in text matches the response actually sent below
                                // ("rejected" only for the permanent allow-list) so the
                                // merchant is never told a payment was rejected while
                                // Paystack is in fact still retrying it.
                                // Signature-verified path: this write is Paystack-authorized,
                                // and every real charge fires the webhook, so this is the
                                // merchant's only visibility into "money moved but the order
                                // did not advance". recordHistory() itself dedupes on
                                // (reference, reason): WRONG_METHOD/MALFORMED are transient,
                                // so this call re-runs on every one of Paystack's ~72h
                                // retries and must not append a duplicate comment each time.
                                $paidAmount = $transactionDetails->data->amount ?? 'unknown';
                                $paidCurrency = $transactionDetails->data->currency ?? 'unknown';
                                $this->recordHistory(
                                    $order,
                                    $reference,
                                    $reason,
                                    sprintf(
                                        'Paystack: payment %s — %s: paid %s %s, expected %s %s, reference %s',
                                        $isPermanentReason ? 'rejected' : 'not applied (retry pending)',
                                        $reason,
                                        $paidAmount,
                                        $paidCurrency,
                                        $expectedSubunits,
                                        $order->getOrderCurrencyCode(),
                                        (string) $reference
                                    )
                                );
                            }

                            if (TransactionValidator::REASON_IN_FLIGHT === $reason) {
                                // Bank transfer / USSD still settling — the webhook is the
                                // only confirmation for those. Let Paystack retry.
                                $resultFactory->setHttpResponseCode(503);
                                $resultFactory->setContents("pending");
                                return $resultFactory;
                            }

                            if ($isPermanentReason) {
                                $resultFactory->setHttpResponseCode(200);
                                $resultFactory->setContents("rejected");
                                return $resultFactory;
                            }

                            $resultFactory->setHttpResponseCode(503);
                            $resultFactory->setContents("unverified");
                            return $resultFactory;
                        }

                        $this->logger->warning("Paystack Webhook: order not found for reference " . $reference);

                        if ($this->isRecentTransaction($transactionDetails)) {
                            // Recent transaction, no matching order yet — could be a
                            // genuine order-save race; let Paystack retry within the window.
                            $resultFactory->setHttpResponseCode(503);
                            $resultFactory->setContents("order not found");
                            return $resultFactory;
                        }

                        // Old transaction, still no order: very likely this will never
                        // have a Magento order at all (see the class constant's comment).
                        $this->logger->warning("Paystack Webhook: order not found and transaction not recent, treating as permanent", ['reference' => $reference]);
                        $resultFactory->setHttpResponseCode(200);
                        $resultFactory->setContents("order not found");
                        return $resultFactory;
                    }
                    break;
            }
        } catch (\Throwable $exc) {
            // \Throwable, not \Exception: PaystackApiClient::verifyTransaction() takes
            // a non-nullable string param, and this file has no
            // declare(strict_types=1) — but userland non-nullable scalar params still
            // reject null with a TypeError, which extends \Error, not \Exception.
            // Left uncaught it would escape as an uncontrolled 500 with a stack trace;
            // Paystack treats 500 as transient anyway, so widening the catch just
            // makes that path controlled and logged instead. The message is not
            // reflected in the body: it can carry internal detail from
            // curl_error()/the raw gateway response. Default to transient so
            // Paystack retries; log the detail instead of leaking it.
            $this->logger->error("Paystack Webhook: exception", ['error' => $exc->getMessage()]);
            $resultFactory->setHttpResponseCode(503);
            $resultFactory->setContents("error");
            return $resultFactory;
        }

        // Every event type this switch does not handle, and a charge.success
        // whose payload status is not itself "success", falls through to here.
        // Neither is an error — Paystack sends many event types this endpoint
        // does not act on — so it is permanently accepted (200), never retried.
        $resultFactory->setHttpResponseCode(200);
        $resultFactory->setContents("ignored");
        return $resultFactory;
    }

    /**
     * Writes a merchant-visible order-history comment, but only once per
     * (reference, reason) pair: WRONG_METHOD and MALFORMED are transient (503)
     * and on the deny-list below, so Paystack's ~72h retry window would otherwise
     * append a near-identical comment on every retry of the same rejection. A
     * failed write, and a failed lookup of the existing history, must not turn a
     * rejection/overpayment note into a 503 retry storm — log and continue.
     *
     * @param OrderInterface $order
     * @param string $reference
     * @param string $reasonKey
     * @param string $comment
     * @return void
     */
    private function recordHistory(OrderInterface $order, string $reference, string $reasonKey, string $comment): void
    {
        try {
            foreach ($order->getStatusHistories() ?? [] as $history) {
                $existingComment = $history->getComment() ?? '';
                if (strpos($existingComment, $reference) !== false && strpos($existingComment, $reasonKey) !== false) {
                    // Already recorded for this (reference, reason) pair — this is a
                    // retry of an event we already wrote a comment for.
                    return;
                }
            }

            $order->addStatusToHistory($order->getStatus(), $comment);
            $this->orderRepository->save($order);
        } catch (\Throwable $exc) {
            // \Throwable, not \Exception: now that the outer catch is also \Throwable,
            // this catch is not about keeping a TypeError from escaping — it is that a
            // failed history write of any kind must not turn a clean, already-decided
            // rejection into a 503 retry. Paystack would then retry the exact same
            // permanent rejection, wasting the retry window on something a retry can
            // never fix.
            $this->logger->error("Paystack Webhook: failed to write order history", ['error' => $exc->getMessage()]);
        }
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
