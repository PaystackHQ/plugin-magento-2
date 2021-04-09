<?php

namespace Pstk\Paystack\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

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
            
            $this->orderSender->send($order, true);
        }
    }
}
