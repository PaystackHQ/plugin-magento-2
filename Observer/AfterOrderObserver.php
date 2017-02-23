<?php

namespace Profibro\Paystack\Observer;


use Magento\Framework\Event\ObserverInterface;

class AfterOrderObserver implements ObserverInterface
{

    public function __construct()
    {

    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
    //Observer execution code...
        $order = $observer->getEvent()->getOrder();
        $order->setStatus('complete');
        $order->save();
    }
}
