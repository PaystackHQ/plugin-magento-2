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

        // Kept separate: `$reference` is reassigned below to the order's increment ID,
        // and it is the value the caller actually sent that a rejection needs to record.
        $requestedReference = $reference;

        // A rejection must not tell the customer to try again once money may have
        // moved. This is the one generic surface used by every failure branch.
        $unconfirmed = "We could not confirm your payment. If you believe you were "
            . "charged, please contact support before trying again.";

        if (!$reference) {
            return $this->redirectToFinal(false, "No reference supplied");
        }

        $dispatched = false;

        try {
            $transactionDetails = $this->paystackClient->verifyTransaction($reference);

            // The verify response is the only trustworthy statement of what happened
            // to the money. Anyone can hit this route with an arbitrary reference and
            // no session, so nothing may advance an order until Paystack itself says
            // the charge succeeded.
            $status = $transactionDetails->data->status ?? null;

            if ('success' !== $status) {
                $this->logger->warning(
                    'Paystack callback rejected: transaction is not successful',
                    ["reference" => $requestedReference, "status" => $status]
                );

                // `pending`, `ongoing` and `queued` are in-flight, not failed: bank
                // transfer and USSD sit there at callback time and settle minutes
                // later via the webhook. Telling that customer to try again invites a
                // second payment for a charge already on its way.
                $inFlight = in_array($status, ["pending", "ongoing", "queued"], true);

                return $this->redirectToFinal(
                    false,
                    $inFlight
                        ? "Your payment is still being confirmed. Please do not pay "
                            . "again — we will email you once it completes."
                        : "Your payment was not completed. If you believe you were "
                            . "charged, please contact support before trying again."
                );
            }

            $reference = explode('_', $transactionDetails->data->reference, 2);
            $reference = ($reference[0])?: 0;
            
            $order = $this->orderInterface->loadByIncrementId($reference);
            
            if ($order && $reference === $order->getIncrementId()) {
                // dispatch the `payment_verify_after` event to update the order status
                
                $dispatched = true;

                $this->eventManager->dispatch('paystack_payment_verify_after', [
                    "paystack_order" => $order,
                ]);

                return $this->redirectToFinal(true);
            }

            $message = "Invalid reference or order number";

        } catch (\Pstk\Paystack\Gateway\Exception\ApiException $e) {
            // The message is not reflected to the caller: it is built from curl_error()
            // and Paystack's raw response, so it both leaks internal detail and turns
            // this anonymous route into an oracle that distinguishes "no such
            // reference" from "reference exists".
            $this->logger->error(
                'Paystack callback API error: ' . $e->getMessage(),
                ["reference" => $requestedReference, "exception" => $e]
            );

            $message = $unconfirmed;

        } catch (\Throwable $e) {
            // Was `catch (Exception $e)` — unqualified in a namespaced file with no
            // `use`, so it resolved to a class that does not exist and never caught
            // anything: every non-ApiException escaped as a 500. The message is not
            // shown to the customer because it can carry internal detail.
            $this->logger->error(
                'Paystack callback failed: ' . $e->getMessage(),
                ["reference" => $requestedReference, "exception" => $e]
            );

            $message = $unconfirmed;
        }

        if ($dispatched) {
            // Verification succeeded and the advance was already under way when something
            // downstream threw — the observer saves the order before it sends the email.
            // Showing the failure page here would present a paid order as failed and
            // invite a second payment; the warning covers the narrower case where the
            // throw came before the save, so the order is paid but not yet advanced.
            $this->messageManager->addWarningMessage(
                __("Your payment was received, but we could not finish updating your "
                    . "order. Please do not pay again — contact support if you do not "
                    . "receive a confirmation email shortly.")
            );

            return $this->redirectToFinal(true);
        }

        return $this->redirectToFinal(false, $message);
    }

}
