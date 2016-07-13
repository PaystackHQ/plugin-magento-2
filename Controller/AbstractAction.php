<?php
namespace Profibro\Paystack\Controller;

abstract class AbstractAction extends \Magento\Framework\App\Action\Action
{
    protected $_paystack;
    public function __construct(
        \Magento\Framework\App\Action\Context $context
    ) {
        $this->_paystack = new \Yabacon\Paystack('sk_test_0339bc88c2328470413dc6c0a39aa5cf5e09ca68');
        parent::__construct($context);
    }

}