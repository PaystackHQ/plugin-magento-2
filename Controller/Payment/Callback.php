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

class Callback extends AbstractPaystackStandard {

    /**
     * Execute view action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute() {

        $reference = $this->request->get('reference');
        $message = "";
        
        if (!$reference) {
            return $this->redirectToFinal(false, "No reference supplied");
        }
        
        try {
            $transactionDetails = $this->paystack->transaction->verify([
                'reference' => $reference
            ]);
            
            $reference = explode('_', $transactionDetails->data->reference, 2);
            $reference = ($reference[0])?: 0;
            
            $order = $this->orderInterface->loadByIncrementId($reference);
            
            if ($order && $reference === $order->getIncrementId()) {
                // dispatch the `payment_verify_after` event to update the order status
                
                $this->eventManager->dispatch('paystack_payment_verify_after', [
                    "paystack_order" => $order,
                ]);

                return $this->redirectToFinal(true);
            }

            $message = "Invalid reference or order number";
            
        } catch (\Yabacon\Paystack\Exception\ApiException $e) {
            $message = $e->getMessage();
            
        } catch (Exception $e) {
            $message = $e->getMessage();
            
        }

        return $this->redirectToFinal(false, $message);
    }

}
