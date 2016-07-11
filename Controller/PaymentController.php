<?php
/**
 * Paytsack Inline Extension
 *
 * DISCLAIMER
 * This file will not be supported if it is modified.
 *
 * @category   Paystack
 * @author     Ibrahim Lawal (@ibrahimlawal)
 * @package    Paystack_Inline
 * @copyright  Copyright (c) 2016 Paystack. (https://www.paystack.com/)
 * @license    https://raw.githubusercontent.com/PaystackHQ/paystack-magento/master/LICENSE   MIT License (MIT)
 */

namespace Paystack\Inline\Controller;

class Payment extends \Magento\Framework\App\Action\Action 
{

    /**
     * @var \Magento\Framework\Session\Generic
     */
    protected $generic;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $salesOrderFactory;

    /**
     * @var \Magento\Quote\Model\QuoteFactory
     */
    protected $quoteQuoteFactory;

    /**
     * @var \Paystack\Inline\Helper\Data
     */
    protected $inlineHelper;

    /**
     * @var \Magento\AdminNotification\Model\InboxFactory
     */
    protected $adminNotificationInboxFactory;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\Session\Generic $generic,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Sales\Model\OrderFactory $salesOrderFactory,
        \Magento\Quote\Model\QuoteFactory $quoteQuoteFactory,
        \Paystack\Inline\Helper\Data $inlineHelper,
        \Magento\AdminNotification\Model\InboxFactory $adminNotificationInboxFactory
    ) {
        $this->generic = $generic;
        $this->checkoutSession = $checkoutSession;
        $this->salesOrderFactory = $salesOrderFactory;
        $this->quoteQuoteFactory = $quoteQuoteFactory;
        $this->inlineHelper = $inlineHelper;
        $this->adminNotificationInboxFactory = $adminNotificationInboxFactory;
        parent::__construct(
            $context
        );
    }

    public function cancelAction() 
    {
        $this->generic->addError(
            __("Payment cancelled."));
        
        $session = $this->checkoutSession;
        if ($session->getLastRealOrderId())
        {
            $order = $this->salesOrderFactory->create()->loadByIncrementId($session->getLastRealOrderId());
            if ($order->getId())
            {
                //Cancel order
                if ($order->getState() != \Magento\Sales\Model\Order::STATE_CANCELED)
                {
                    $order->registerCancellation("Canceled by User")->save();
                }
                $quote = $this->quoteQuoteFactory->create()->load($order->getQuoteId());
                //Return quote
                if ($quote->getId())
                {
                    $quote->setIsActive(1)
                        ->setReservedOrderId(NULL)
                        ->save();
                    $session->replaceQuote($quote);
                }

                //Unset data
                $session->unsLastRealOrderId();
            }
        }

        return $this->getResponse()->setRedirect( Mage::getUrl('checkout/onepage'));
    }

    public function popAction() 
    {
        $this->loadLayout();
        $block = $this->getLayout()->createBlock('Mage_Core_Block_Template','paystack_inline',array('template' => 'paystack/pop.phtml'));
        $this->getLayout()->getBlock('content')->append($block);
        $this->renderLayout();
    }

    public function responseAction() 
    {
        $success = false;

        $orderId = $this->getRequest()->get("orderId");
        $trxref = $this->getRequest()->get("trxref");
        
        // Both are required
        if(!$orderId || !$trxref){
            return;
        }
        
        // trxref must start with orderId by design
        if(strpos($trxref, $orderId) !== 0){
            return;
        }

        $order = $this->salesOrderFactory->create()->loadByIncrementId($orderId);
        if(!$order){
            return;
        }


        // verify transaction with paystack
        $transactionStatus = $this->inlineHelper->verifyTransaction($trxref);
        if($transactionStatus->error)
        {
            $this->adminNotificationInboxFactory->create()->addMajor(
                __("Error while attempting to verify transaction: trxref: " . $trxref),
                __($transactionStatus->error),
                '',
                true
            );
        }
        elseif($transactionStatus->status == 'success')
        {
            $order->setState(\Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW, true, 'Payment Success.');
            $order->save();

            $this->checkoutSession->unsQuoteId();
            \Magento\Framework\App\Action\Action::_redirect('checkout/onepage/success');
            $success = true;
        }
        else
        {
            $order->setState(\Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW, true, $transactionStatus->status);
            $order->save();

            $this->checkoutSession->unsQuoteId();
        }
    
        
        if(!$success){
            $this->generic->addError(
                __("There was an error processing your payment. Please try again."));
            \Magento\Framework\App\Action\Action::_redirect('checkout/cart');
        }
        
    }
}