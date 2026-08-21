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

use Magento\Sales\Model\Order;

class Recreate extends AbstractPaystackStandard {

    public function execute() {

        $order = $this->checkoutSession->getLastRealOrder();

        // No last real order at all is a benign repeat hit — double-submit, back
        // button, refresh, or an expired session — not an incident. Just send the
        // customer back to checkout.
        if (!$order->getId()) {
            return $this->_redirect('checkout', ['_fragment' => 'payment']);
        }

        // A GET to this route is anonymous and unauthenticated. These two guards
        // stop an anonymous GET from cancelling a settled/advanced order (or one
        // paid via a non-Paystack method) and restoring its quote. Neither makes a
        // Paystack reference single-use: a customer can pay, close the tab before
        // verify runs, and the order stays "new" with the paid reference still
        // replayable — closing that gap is the deferred R2.3 reference-consumption
        // work.

        // Allow-list, not deny-list: only the two pre-payment states are
        // restorable, so a future state this list doesn't know about fails closed.
        $isPrePaymentState = in_array(
            $order->getState(),
            [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT],
            true
        );

        if (!$isPrePaymentState || !$this->transactionValidator->isPaystackOrder($order)) {
            return $this->redirectToFinal(
                false,
                "We could not restart this payment. Please contact support if you "
                    . "believe this is an error."
            );
        }

        $order->registerCancellation("Payment failed or cancelled")->save();

        $this->checkoutSession->restoreQuote();
        $this->_redirect('checkout', ['_fragment' => 'payment']);
    }

}
