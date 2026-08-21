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
                // The exception message is built from curl_error() and Paystack's raw
                // response body (see Gateway/PaystackApiClient::request()), so it can
                // carry internal hostnames, TLS/proxy detail, or gateway-side state —
                // the same leak D5 closed on Callback.php. Detail stays in the
                // (admin-only) order history; the customer gets a fixed, safe string.
                $this->logger->error(
                    'Paystack setup API error: ' . $e->getMessage(),
                    ['exception' => $e]
                );
                $order->addStatusToHistory($order->getStatus(), $e->getMessage());
                $this->orderRepository->save($order);
                $message = "We could not start your Paystack payment. Please try again "
                    . "or contact support if the problem continues.";
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

        $tranx = $this->paystackClient->initializeTransaction([
            'first_name' => $order->getCustomerFirstname(),
            'last_name' => $order->getCustomerLastname(),
            'amount' => $this->transactionValidator->expectedSubunits($order), // in kobo (integer, subunit)
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
