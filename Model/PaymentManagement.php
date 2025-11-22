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
use Magento\Payment\Helper\Data as PaymentHelper;
use Pstk\Paystack\Model\Payment\Paystack as PaystackModel;
use Yabacon\Paystack as PaystackLib;

class PaymentManagement implements \Pstk\Paystack\Api\PaymentManagementInterface
{

    protected $paystackPaymentInstance;

    protected $paystackLib;
    
    protected $orderInterface;
    protected $checkoutSession;

    /**
     * @var \Magento\Framework\Event\Manager
     */
    private $eventManager;

    public function __construct(
        PaymentHelper $paymentHelper,
        \Magento\Framework\Event\Manager $eventManager,
        \Magento\Sales\Api\Data\OrderInterface $orderInterface,
        \Magento\Checkout\Model\Session $checkoutSession
            
    ) {
        $this->eventManager = $eventManager;
        $this->paystackPaymentInstance = $paymentHelper->getMethodInstance(PaystackModel::CODE);
        
        $this->orderInterface = $orderInterface;
        $this->checkoutSession = $checkoutSession;

        $secretKey = $this->paystackPaymentInstance->getConfigData('live_secret_key');
        if ($this->paystackPaymentInstance->getConfigData('test_mode')) {
            $secretKey = $this->paystackPaymentInstance->getConfigData('test_secret_key');
        }

        $this->paystackLib = new PaystackLib($secretKey);
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
        
        try {
            $transaction_details = $this->paystackLib->transaction->verify([
                'reference' => $reference
            ]);
            
            $order = $this->getOrder();
            if ($order && $order->getQuoteId() === $quoteId && $transaction_details->data->metadata->quoteId === $quoteId) {
                
                // dispatch the `paystack_payment_verify_after` event to update the order status
                $this->eventManager->dispatch('paystack_payment_verify_after', [
                    "paystack_order" => $order,
                ]);

                // Return consistent response format
                return json_encode([
                    'status' => true,
                    'message' => 'Verification successful',
                    'data' => $transaction_details->data
                ]);
            }
        } catch (Exception $e) {
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
