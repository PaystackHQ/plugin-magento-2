<?php
namespace Profibro\Paystack\Controller;

abstract class PayAction extends AbstractAction
{
	protected $_requestData = [];

	public function __construct(
		\Magento\Framework\App\Action\Context $context,
		\Magento\Framework\Session\Generic $paystackSession,
		\Magento\Checkout\Model\Session $checkoutSession,
		\Magento\Sales\Model\OrderFactory $salesOrderFactory,
		\Magento\Quote\Model\QuoteFactory $quoteQuoteFactory,
		\Magento\AdminNotification\Model\InboxFactory $adminNotificationInboxFactory,
		\Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
	) {
		parent::__construct(
			$context,
			$paystackSession,
			$checkoutSession,
			$salesOrderFactory,
			$quoteQuoteFactory,
			$adminNotificationInboxFactory,
			$scopeConfig
		);
		$this->_requestData = $this->paystackSession->getSessionRequestData();
	}

	protected function sessionHasValidRequestData(){
		return is_array($this->_requestData) &&
			array_key_exists('amount', $this->_requestData) &&
			array_key_exists('remarks', $this->_requestData) &&
			array_key_exists('email', $this->_requestData) &&
			array_key_exists('reference', $this->_requestData) &&
			array_key_exists('callback_url', $this->_requestData);
	}

	protected function redirectToCheckout(){
		$this->getResponse()->setRedirect($this->_url->getUrl('checkout'));
	}

}