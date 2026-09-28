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

class Setup extends AbstractPaystackStandard {

    /**
     * Execute view action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute() {
        
        $message = '';
        $order = $this->orderInterface->loadByIncrementId($this->checkoutSession->getLastRealOrder()->getIncrementId());
        if ($order && $this->getMethod()->getCode() == $order->getPayment()->getMethod()) {

            try {
                return $this->processAuthorization($order);
            } catch (\Pstk\Paystack\Gateway\Exception\ApiException $e) {
                $message = $e->getMessage();
                $order->addStatusToHistory($order->getStatus(), $message);
                $this->orderRepository->save($order);
            }
        }

        $this->redirectToFinal(false, $message);
    }

    protected function processAuthorization(\Magento\Sales\Model\Order $order) {

        // Fail closed on a missing order currency. Paystack accepts a null currency
        // silently and substitutes the integration's own default, so an empty value
        // here would charge in the wrong currency with a success response and no
        // trace. The caller turns this into order history plus the failure page.
        $currency = $order->getOrderCurrencyCode();
        if (!$currency) {
            throw new \Pstk\Paystack\Gateway\Exception\ApiException(
                'Cannot start a Paystack transaction: the order has no currency code.'
            );
        }

        // Fail closed on a missing/malformed order grand total. A non-numeric or
        // zero total would silently send amount:0 to Paystack, which would let a
        // customer "pay" nothing with a success response and no trace. The caller
        // turns this into order history plus the failure page.
        $grandTotal = $order->getGrandTotal();
        $amount = is_numeric($grandTotal) ? $this->transactionValidator->expectedSubunits($order) : 0;
        if ($amount <= 0) {
            throw new \Pstk\Paystack\Gateway\Exception\ApiException(
                'Cannot start a Paystack transaction: the order has no valid grand total.'
            );
        }

        $tranx = $this->paystackClient->initializeTransaction([
            'first_name' => $order->getCustomerFirstname(),
            'last_name' => $order->getCustomerLastname(),
            'amount' => $amount, // in kobo (integer, subunit)
            'email' => $order->getCustomerEmail(), // unique to customers
            'reference' => $order->getIncrementId(), // unique to transactions
            'currency' => $currency,
            'callback_url' => $this->storeManager->getStore()->getBaseUrl() . "paystack/payment/callback",
            'metadata' => array('custom_fields' => array(
                array(
                    "display_name"=>"Plugin",
                    "variable_name"=>"plugin",
                    "value"=>"magento-2"
                )
            )) 
        ]);

        //var_dump($tranx); die();

        $redirectFactory = $this->resultRedirectFactory->create();
        $redirectFactory->setUrl($tranx->data->authorization_url);


        return $redirectFactory;
    }

}
