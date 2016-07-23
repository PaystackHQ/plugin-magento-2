<?php
namespace Profibro\Paystack\Controller;

abstract class AbstractAction extends \Magento\Framework\App\Action\Action
{
	/**
	 * @var \Magento\Framework\Session\Generic
	 */
	protected $paystackSession;
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
	 * @var \Magento\AdminNotification\Model\InboxFactory
	 */
	protected $adminNotificationInboxFactory;
	/**
	 * @var \Yabacon\Paystack
	 */
	protected $_paystack;
	protected $quote;
	
	public function __construct(
		\Magento\Framework\App\Action\Context $context,
		\Magento\Framework\Session\Generic $paystackSession,
		\Magento\Checkout\Model\Session $checkoutSession,
		\Magento\Sales\Model\OrderFactory $salesOrderFactory,
		\Magento\Quote\Model\QuoteFactory $quoteQuoteFactory,
		\Magento\AdminNotification\Model\InboxFactory $adminNotificationInboxFactory,
		\Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
	) {
		$this->scopeConfig = $scopeConfig;
	
		// $this->_paystack = new \Yabacon\Paystack($this->getConfigData('secret_key'));
		$this->_paystack = new \Yabacon\Paystack($this->getSecretKey());
		$this->paystackSession = $paystackSession;
		$this->checkoutSession = $checkoutSession;
		$this->salesOrderFactory = $salesOrderFactory;
		$this->quoteQuoteFactory = $quoteQuoteFactory;
		$this->adminNotificationInboxFactory = $adminNotificationInboxFactory;
		parent::__construct(
			$context
		);
	}
	
	public function getSecretKey() {
		$storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
		return $this->scopeConfig->getValue('payment/profibro_paystack/secret_key', $storeScope);
	}

	public function getPublicKey() {
		$storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
		return $this->scopeConfig->getValue('payment/profibro_paystack/public_key', $storeScope);
	}

	protected function _getCustomer()
	{
		if (empty($this->customer)) {
			$this->customer = $this->customerRepository->getById($this->customerSession->getCustomerId());
		}
		return $this->customer;
	}

	/**
	 * Retrieve checkout session model
	 *
	 * @return \Magento\Checkout\Model\Session
	 */
	public function getCheckout()
	{
		return $this->checkoutSession;
	}

	/**
	 * Retrieve sales quote model
	 *
	 * @return Quote
	 */
	public function getQuote()
	{
		if (empty($this->quote)) {
			$this->quote = $this->getCheckout()->getQuote();
		}
		return $this->quote;
	}

}