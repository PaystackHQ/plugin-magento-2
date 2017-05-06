<?php

namespace Profibro\Paystack\Observer;


use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

class AfterOrderObserver implements ObserverInterface
{

    public function __construct()
    {

    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
    //Observer execution code...
        $order = $observer->getEvent()->getOrder();
        $order->setStatus(Order::STATE_PROCESSING);
        $order->save();
    }
}
