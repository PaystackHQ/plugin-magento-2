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

    public function __construct(
        PaystackApiClient $paystackClient,
        \Magento\Framework\Event\Manager $eventManager,
        \Magento\Sales\Api\Data\OrderInterface $orderInterface,
        \Magento\Checkout\Model\Session $checkoutSession,
        LoggerInterface $logger
    ) {
        $this->paystackClient = $paystackClient;
        $this->eventManager = $eventManager;
        $this->orderInterface = $orderInterface;
        $this->checkoutSession = $checkoutSession;
        $this->logger = $logger;
    }

    /**
     * @param string $reference
     * @return string
     */
    public function verifyPayment($reference)
    {
        
        // we are appending quoteid
        $ref = explode('_-~-_', $reference);
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

            if ($order && (string)$order->getQuoteId() === (string)$quoteId && (string)$transaction_details->data->metadata->quoteId === (string)$quoteId) {

                // dispatch the `paystack_payment_verify_after` event to update the order status
                $this->eventManager->dispatch('paystack_payment_verify_after', [
                    "paystack_order" => $order,
                ]);

                $this->logger->info('Paystack: verification successful, event dispatched');

                // Return consistent response format
                return json_encode([
                    'status' => true,
                    'message' => 'Verification successful',
                    'data' => $transaction_details->data
                ]);
            }
            $this->logger->warning('Paystack: quoteId mismatch — order not updated');
        } catch (\Throwable $e) {
            $this->logger->error('Paystack: verifyPayment exception', ['error' => $e->getMessage()]);
            return json_encode([
                'status' => false,
                'message' => $e->getMessage()
            ]);
        }
        return json_encode([
            'status' => false,
            'message' => "quoteId doesn't match transaction"
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
