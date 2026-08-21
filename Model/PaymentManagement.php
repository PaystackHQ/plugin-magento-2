<?php
/**
 * Paystack Magento2 Module using \Magento\Payment\Model\Method\AbstractMethod
 * Copyright (C) 2019 Paystack.com
 * 
 * This file is part of Pstk/Paystack.
 * 
 * Pstk/Paystack is free software: you can redistribute it and/or modify
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Pstk\Paystack\Model;

use Exception;
use Pstk\Paystack\Gateway\PaystackApiClient;
use Pstk\Paystack\Gateway\Validator\TransactionValidator;
use Psr\Log\LoggerInterface;

class PaymentManagement implements \Pstk\Paystack\Api\PaymentManagementInterface
{

    protected $paystackClient;

    protected $orderInterface;
    protected $checkoutSession;

    /**
     * @var \Magento\Framework\Event\Manager
     */
    private $eventManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var TransactionValidator
     */
    private $transactionValidator;

    public function __construct(
        PaystackApiClient $paystackClient,
        \Magento\Framework\Event\Manager $eventManager,
        \Magento\Sales\Api\Data\OrderInterface $orderInterface,
        \Magento\Checkout\Model\Session $checkoutSession,
        LoggerInterface $logger,
        TransactionValidator $transactionValidator
    ) {
        $this->paystackClient = $paystackClient;
        $this->eventManager = $eventManager;
        $this->orderInterface = $orderInterface;
        $this->checkoutSession = $checkoutSession;
        $this->logger = $logger;
        $this->transactionValidator = $transactionValidator;
    }

    /**
     * @param string $reference
     * @return string
     */
    public function verifyPayment($reference)
    {

        // we are appending quoteid
        $ref = explode('_-~-_', $reference);
        if (count($ref) !== 2 || $ref[0] === '' || $ref[1] === '') {
            // Nothing was charged through us yet — the reference the browser sent
            // is unusable before any verify call is even made. Distinct from
            // REASON_MALFORMED (an unreadable *gateway* answer): this one is
            // safe to retry.
            $this->logger->warning('Paystack: malformed verify reference');
            return $this->failureResponse(TransactionValidator::REASON_BAD_REFERENCE);
        }
        $reference = $ref[0];
        $quoteId = $ref[1];

        $this->logger->info('Paystack: verifyPayment called', ['reference' => $reference, 'quoteId' => $quoteId]);

        try {
            $transaction_details = $this->paystackClient->verifyTransaction($reference);
            $this->logger->info('Paystack: transaction verified via API', [
                'tx_status' => $transaction_details->data->status ?? 'unknown',
                'tx_quoteId' => $transaction_details->data->metadata->quoteId ?? 'missing',
            ]);

            $order = $this->getOrder();
            $this->logger->info('Paystack: getOrder result', [
                'order_found' => $order ? 'yes' : 'no',
                'order_quoteId' => $order ? $order->getQuoteId() : 'N/A',
                'url_quoteId' => $quoteId,
                'tx_meta_quoteId' => $transaction_details->data->metadata->quoteId ?? 'missing',
            ]);

            if ($order && (string)$order->getQuoteId() === (string)$quoteId && (string)($transaction_details->data->metadata->quoteId ?? null) === (string)$quoteId) {

                $failureReason = $this->transactionValidator->settlementFailureReason($transaction_details, $order);

                if ($failureReason === null) {
                    // dispatch the `paystack_payment_verify_after` event to update the order status
                    $this->eventManager->dispatch('paystack_payment_verify_after', [
                        "paystack_order" => $order,
                    ]);

                    $this->logger->info('Paystack: verification successful, event dispatched');

                    // Return consistent response format — trimmed to what the JS reads
                    return json_encode([
                        'status' => true,
                        'message' => 'Verification successful',
                        'data' => [
                            'status' => $transaction_details->data->status,
                            'reference' => $transaction_details->data->reference ?? null,
                        ],
                    ]);
                }

                $this->logger->warning('Paystack: settlement check failed on inline verify', [
                    'reference' => $reference,
                    'quoteId' => $quoteId,
                    'reason' => $failureReason,
                ]);
                return $this->failureResponse($failureReason);
            }
            $this->logger->warning('Paystack: quoteId mismatch — order not updated');
        } catch (\Throwable $e) {
            $this->logger->error('Paystack: verifyPayment exception', ['error' => $e->getMessage()]);
            return $this->failureResponse('error');
        }
        return $this->failureResponse('quote_mismatch');
    }

    /**
     * Builds the anonymous-caller failure JSON: never echoes amounts, gateway
     * messages, or other internal detail — only a machine-readable `reason` and
     * a customer-safe `message`. The copy is owned by
     * TransactionValidator::customerMessage(), which is also Webhook's and
     * Callback's single source for the same classification — this is not a
     * fourth, independently maintained policy map.
     *
     * @param string $reason
     * @return string
     */
    private function failureResponse(string $reason)
    {
        return json_encode([
            'status' => false,
            'reason' => $reason,
            // See TransactionValidator::isTerminalForCustomer() for the fail-closed rationale.
            'final' => $this->transactionValidator->isTerminalForCustomer($reason),
            'message' => $this->transactionValidator->customerMessage($reason),
        ]);
    }

    /**
     * Loads the order based on the last real order
     * @return boolean
     */
    private function getOrder()
    {
        // get the last real order id
        $lastOrder = $this->checkoutSession->getLastRealOrder();
        if($lastOrder){
            $lastOrderId = $lastOrder->getIncrementId();
        } else {
            return false;
        }
        
        if ($lastOrderId) {
            // load and return the order instance
            return $this->orderInterface->loadByIncrementId($lastOrderId);
        }
        return false;
    }

}
