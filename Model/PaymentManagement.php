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
use Yabacon\Paystack;

class PaymentManagement implements \Pstk\Paystack\Api\PaymentManagementInterface
{
    const CODE = 'pstk_paystack';

    protected $config;

    protected $paystack;

    /**
     * @var EventManager
     */
    private $eventManager;

    public function __construct(
        PaymentHelper $paymentHelper,
        \Magento\Framework\Event\Manager $eventManager
    ) {
        $this->eventManager = $eventManager;
        $this->config = $paymentHelper->getMethodInstance(self::CODE);

        $secretKey = $this->config->getConfigData('live_secret_key');
        if ($this->config->getConfigData('test_mode')) {
            $secretKey = $this->config->getConfigData('test_secret_key');
        }

        $this->paystack = new Paystack($secretKey);
    }

    /**
     * @return bool
     */
    public function verifyPayment($ref_quote)
    {
        // we are appending quoteid
        $ref = explode('_-~-_', $ref_quote);
        $reference = $ref[0];
        $quoteId = $ref[1];
        try {
            $transaction_details = $this->paystack->transaction->verify([
                'reference' => $reference
            ]);
            if ($transaction_details->data->metadata->quoteId === $quoteId) {
                // dispatch the `payment_verify_after` event to update the order status
                $this->eventManager->dispatch('payment_verify_after');

                return json_encode($transaction_details);
            }
        } catch (Exception $e) {
            return json_encode([
                'status'=>0,
                'message'=>$e->getMessage()
            ]);
        }
        return json_encode([
            'status'=>0,
            'message'=>"quoteId doesn't match transaction"
        ]);
    }

    public function getPayment($param): string {
        
    }

}
