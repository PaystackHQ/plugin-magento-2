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
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Pstk\Paystack\Gateway\Validator\TransactionValidator;
use Psr\Log\LoggerInterface;

/**
 * Finds the order a verified `charge.success` webhook is for. Inline (popup)
 * payments use a Paystack-generated reference, so several orders can share one
 * `quote_id` once the customer has cancelled and retried (issue #69) and the
 * quote alone no longer names the order. First match wins, in this order:
 *
 *  1. increment id == reference (redirect flow);
 *  2. a `sales_payment_transaction` already bound to the reference (redelivery
 *     after inline verify / an earlier webhook settled it — register() then
 *     answers idempotently);
 *  3. `metadata.orderId` + `metadata.quoteId` naming one Paystack order —
 *     used in ANY state, so a cancelled attempt's late money is rejected
 *     against the right order rather than moved to a sibling;
 *  4. `metadata.quoteId` alone: a lone order on the quote in any state/method
 *     (the pre-#69 rule), else the single Paystack order that
 *     TransactionValidator::isPayable(); several candidates never pick one.
 *
 * Security: metadata is read from the re-verified Paystack response, never the
 * event payload, but `orderId` and `quoteId` are both payer-controlled. The
 * quote cross-check is a consistency check, not an authorization boundary —
 * the bound on what a tampered id can achieve comes from
 * PaymentSettlement::register()'s amount/currency/mode/status checks and its
 * one-reference-one-order binding. Ids are logged (type-guarded) but never
 * written into order history.
 */
class WebhookOrderResolver
{
    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var SearchCriteriaBuilder */
    private $searchCriteriaBuilder;

    /** @var TransactionRepositoryInterface */
    private $transactionRepository;

    /** @var OrderInterface */
    private $orderInterface;

    /** @var TransactionValidator */
    private $transactionValidator;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        TransactionRepositoryInterface $transactionRepository,
        OrderInterface $orderInterface,
        TransactionValidator $transactionValidator,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->transactionRepository = $transactionRepository;
        $this->orderInterface = $orderInterface;
        $this->transactionValidator = $transactionValidator;
        $this->logger = $logger;
    }

    /**
     * Repository/DB errors deliberately propagate (the webhook answers 503):
     * falling through to a weaker step on a transient failure could settle a
     * sibling order.
     *
     * @param string $reference Paystack's echoed reference from the verify response
     * @param object $transactionDetails Full envelope PaystackApiClient::verifyTransaction() returns
     * @return OrderInterface|null
     */
    public function resolve(string $reference, object $transactionDetails): ?OrderInterface
    {
        // Step 1: increment id (redirect flow).
        $order = $this->orderInterface->loadByIncrementId($reference);
        if ($order && $order->getId()) {
            return $order;
        }

        // Step 2: reference already bound to an order. Loaded via getList(),
        // not get(), so the repository's registry doesn't hand register() this
        // same instance back as its "fresh" re-fetch.
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('txn_id', $reference, 'eq')
            ->create();
        foreach ($this->transactionRepository->getList($searchCriteria)->getItems() as $transaction) {
            $boundOrder = $this->findOrder($transaction->getOrderId());
            // A binding on a non-Paystack order (e.g. another integration
            // reused the reference) is not ours to settle against.
            if (null !== $boundOrder && $this->transactionValidator->isPaystackOrder($boundOrder)) {
                return $boundOrder;
            }
        }

        $metadata = $this->readMetadata($transactionDetails);
        $quoteId = $this->parseId($metadata->quoteId ?? null);
        if (null === $quoteId) {
            $this->logger->info('Paystack Webhook: no usable quoteId in verified metadata', [
                'reference' => $reference,
            ]);
            return null;
        }

        // Step 3: the exact order the popup placed, when the checkout sent it.
        $orderId = $this->parseId($metadata->orderId ?? null);
        if (null !== $orderId) {
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('entity_id', $orderId, 'eq')
                ->addFilter('quote_id', $quoteId, 'eq')
                ->create();
            $matches = array_values($this->orderRepository->getList($searchCriteria)->getItems());
            if (1 === count($matches) && $this->transactionValidator->isPaystackOrder($matches[0])) {
                return $matches[0];
            }
            $this->logger->info('Paystack Webhook: metadata orderId did not match a Paystack order on the quote, using quote lookup', [
                'reference' => $reference,
                'candidates' => count($matches),
            ]);
        } else {
            $this->logger->info('Paystack Webhook: no metadata.orderId, using quote lookup', [
                'reference' => $reference,
            ]);
        }

        // Step 4: quote fallback.
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('quote_id', $quoteId, 'eq')
            ->create();
        $candidates = array_values($this->orderRepository->getList($searchCriteria)->getItems());

        if (1 === count($candidates)) {
            return $candidates[0];
        }

        $payable = array_values(array_filter(
            $candidates,
            function (OrderInterface $candidate): bool {
                return $this->transactionValidator->isPaystackOrder($candidate)
                    && $this->transactionValidator->isPayable($candidate);
            }
        ));

        $this->logger->info('Paystack Webhook: resolved orders by quoteId', [
            'reference' => $reference,
            'candidates' => count($candidates),
            'payable' => count($payable),
        ]);

        if (1 === count($payable)) {
            return $payable[0];
        }

        if (count($candidates) > 0) {
            // No order will be named for this charge, so the webhook
            // acknowledges it later with nothing on any order — this line is
            // the only way to find which orders it could have been for.
            $this->logger->error('Paystack Webhook: quote has orders but none can be chosen for this charge', [
                'reference' => $reference,
                'candidates' => array_map(
                    function (OrderInterface $candidate): array {
                        return [
                            'increment_id' => $candidate->getIncrementId(),
                            'state' => $candidate->getState(),
                        ];
                    },
                    $candidates
                ),
            ]);
        }

        return null;
    }

    /**
     * @param mixed $orderId
     * @return OrderInterface|null
     */
    private function findOrder($orderId): ?OrderInterface
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('entity_id', $orderId, 'eq')
            ->create();
        $items = array_values($this->orderRepository->getList($searchCriteria)->getItems());

        return $items[0] ?? null;
    }

    /**
     * The verify response's metadata as an object, or an empty one. Paystack
     * may hand it back as an object, a JSON string, or "" — anything that is
     * not (or does not decode to) an object carries no ids.
     *
     * @param object $transactionDetails
     * @return object
     */
    private function readMetadata(object $transactionDetails): object
    {
        $data = $transactionDetails->data ?? null;
        $metadata = is_object($data) ? ($data->metadata ?? null) : null;

        if (is_string($metadata)) {
            $metadata = json_decode($metadata);
        }

        return is_object($metadata) ? $metadata : new \stdClass();
    }

    /**
     * Strict positive-integer id as a string, or null: an int > 0, or a
     * digit-only string with no leading zero. Floats, bools, arrays and
     * anything else are refused rather than coerced.
     *
     * @param mixed $value
     * @return string|null
     */
    private function parseId($value): ?string
    {
        if (is_int($value) && $value > 0) {
            return (string) $value;
        }

        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value)) {
            return $value;
        }

        return null;
    }
}
