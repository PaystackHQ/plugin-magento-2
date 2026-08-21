<?php

namespace Pstk\Paystack\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

class ObserverAfterPaymentVerify implements ObserverInterface
{
    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    protected $orderSender;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        LoggerInterface $logger
    ) {
        $this->orderSender = $orderSender;
        $this->logger = $logger;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        //Observer execution code...
        /** @var \Magento\Sales\Model\Order $order **/
        $order = $observer->getPaystackOrder();

        if ($order && $order->getStatus() == "pending") {
            // sets the status to processing since payment has been received
            $order->setState(Order::STATE_PROCESSING)
                    ->addStatusToHistory(Order::STATE_PROCESSING, __("Paystack Payment Verified and Order is being processed"), true)
                    ->setCanSendNewEmailFlag(true)
                    ->setCustomerNoteNotify(true);
            $order->save();

            try {
                $this->orderSender->send($order, true);
            } catch (\Exception $e) {
                // Email sending failure should not affect order status
            }
        } elseif ($order) {
            // The caller has already told the outside world the payment settled —
            // the callback redirects to the success page, the REST endpoint returns
            // status true, the webhook returns 200 so Paystack won't retry — so if
            // the status gate above doesn't match, the order silently never advances
            // and nothing else records that. Log it so there is at least a trace.
            $this->logger->warning(sprintf(
                'Paystack: settled payment did not advance order %s — status is "%s", not "pending"',
                $order->getIncrementId(),
                $order->getStatus()
            ));
        }
    }
}
