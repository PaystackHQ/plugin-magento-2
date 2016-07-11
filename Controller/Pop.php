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

    public function execute() 
    {
        $this->loadLayout();
        $block = $this->getLayout()->createBlock('Mage_Core_Block_Template','paystack_inline',array('template' => 'pop.phtml'));
        $this->getLayout()->getBlock('content')->append($block);
        $this->renderLayout();
    }

    
}