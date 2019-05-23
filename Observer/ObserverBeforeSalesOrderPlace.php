<?php

namespace Pstk\Paystack\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

class ObserverBeforeSalesOrderPlace implements ObserverInterface
{

    
    public function __construct() {

    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        //Observer execution code...
        /** @var \Magento\Sales\Model\Order $order **/
        $order = $observer->getEvent()->getOrder();
        
        if ($order) {
            $order->setCanSendNewEmailFlag(false)
                    ->setCustomerNoteNotify(false);
        }
    }
}
