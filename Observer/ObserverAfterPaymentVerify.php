<?php

namespace Pstk\Paystack\Observer;

use Magento\Framework\Event\ObserverInterface;

class ObserverAfterPaymentVerify implements ObserverInterface
{
    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    protected $orderSender;

    public function __construct(
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
    ) {
        $this->orderSender = $orderSender;
    }

    /**
     * Email-only: Model\PaymentSettlement::register() now owns advancing the
     * order (registerCaptureNotification() flips it to STATE_PROCESSING as a
     * side effect), so this observer must not also try to set state/history —
     * doing both was a no-op at best (register() already ran first) and,
     * once D8's registration was wired in without this fix, a guaranteed
     * "getStatus() == pending" mismatch on every successful payment (the
     * order is already Processing by the time this observer runs), silently
     * skipping the confirmation email.
     *
     * Gated on `!$order->getEmailSent()` instead of the old
     * `getStatus() == "pending"` check — idempotent against a repeat
     * dispatch (webhook + inline race, or a retried callback) the same way
     * the old gate was, just against the actual signal this observer cares
     * about (has the email already gone out) rather than a status value
     * register() may have already advanced past.
     *
     * Known residual, not race-proof: a genuine concurrent webhook-vs-inline
     * race can still have both reads see `getEmailSent() === null` and both
     * send — bounded by the same missing reference-keyed lock as the D7
     * binding race, not double-fixed here.
     *
     * Does NOT save $order: this observer receives the caller's own,
     * pre-register() instance, which is stale relative to whatever
     * register() already wrote and saved. A save() here would write that
     * stale instance back over the row register() just persisted, clobbering
     * the capture/state it just registered.
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order $order **/
        $order = $observer->getPaystackOrder();

        if ($order && !$order->getEmailSent()) {
            $order->setCanSendNewEmailFlag(true)
                    ->setCustomerNoteNotify(true);

            try {
                $this->orderSender->send($order, true);
            } catch (\Exception $e) {
                // Email sending failure should not affect order status
            }
        }
    }
}
