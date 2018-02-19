<?php

namespace Pstk\Paystack\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

class AfterPaymentVerifyObserver implements ObserverInterface
{
    /**
     * @var \Magento\Sales\Model\OrderFactory $_orderFactory
     */
    protected $_orderFactory;
    
    /**
     * @var \Magento\Checkout\Model\Session $_checkoutSession
     */
    protected $_checkoutSession;
    
    public function __construct(
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Checkout\Model\Session $checkoutSession
    ) {
        $this->_checkoutSession = $checkoutSession;
        $this->_orderFactory = $orderFactory;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        //Observer execution code...
        $order = $this->getOrder();
        if ($order) {
            // sets the status to processing since payment has been received
            $order->setStatus(Order::STATE_PROCESSING);
            $order->save();
        }
    }
    
    /**
     * Loads the order based on the last real order
     * @return boolean
     */
    private function getOrder()
    {
        // get the last real order id
        $lastOrderId = $this->_checkoutSession->getLastRealOrderId();
        if ($lastOrderId) {
            // load and return the order instance
            return $this->_orderFactory->create()->loadByIncrementId($lastOrderId);
        }
        return false;
    }
}
