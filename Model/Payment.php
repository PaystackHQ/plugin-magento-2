<?php
namespace Profibro\Paystack\Model;

class Payment extends \Magento\Payment\Model\Method\AbstractMethod
{
    const CODE = 'profibro_paystack';

    protected $_code = self::CODE;

    protected $_canAuthorize                = true;
    protected $_canCapture                  = false;
    protected $_canCapturePartial           = false;
    protected $_canRefund                   = false;
    protected $_canRefundInvoicePartial     = false;

    protected $_minAmount = null;
    protected $_secretKey = false;
    
    protected $_supportedCurrencyCodes = array('NGN');
    protected $urlBuilder = false;

    /**
     * @var \Magento\Framework\Session\Generic
     */
    protected $paystackSession;

    
    public function __construct(
        \Magento\Framework\Session\Generic $paystackSession,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );

        $this->paystackSession = $paystackSession;
        $this->_minAmount = $this->getConfigData('min_order_total');
        $this->_maxAmount = $this->getConfigData('max_order_total');
        $this->urlBuilder = $urlBuilder;
        $this->_secretKey = $this->getConfigData('secret_key');
    }
    
    public function canUseForCurrency($currencyCode)
    {
        if (!in_array($currencyCode, $this->_supportedCurrencyCodes)) {
            return false;
        }
        return true;
    }
    
    protected function generateRandomString($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }
    
    /**
     * Payment capturing
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Validator\Exception
     */
    public function authorize(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        // throw new \Magento\Framework\Validator\Exception(__('Inside Paystack, throwing foofoo :]'));

        /** @var \Magento\Sales\Model\Order $order */
        $order = $payment->getOrder();

        /** @var \Magento\Sales\Model\Order\Address $billing */
        $billing = $order->getBillingAddress();

        try {
            $requestData = [
                'amount'        => $amount * 100,
                'currency'      => strtolower($order->getBaseCurrencyCode()),
                'description'   => sprintf('#%s, %s', $order->getIncrementId(), $order->getCustomerEmail()),
                'email'         => $order->getCustomerEmail(),
                'reference'     => $order->getIncrementId() . '-' . $this->generateRandomString(),
                'callback_url'  => $this->urlBuilder->getUrl('paystack/verify')
            ];

            $paystack = new \Yabacon\Paystack($this->_secretKey);
            $url = $paystack
                ->transaction
                ->initialize($requestData);
            
            //throw new \Magento\Framework\Validator\Exception(__($url->data->authorization_url));
            $payment
                ->setTransactionId($requestData['reference'])
                ->setIsTransactionClosed(false);

            $this->paystackSession->setTransactionUrl($url->data->authorization_url);
        } catch (\Exception $e) {
            $this->debugData(['request' => $requestData, 'exception' => $e->getMessage()]);
            $this->_logger->error(__('Payment capturing error: ' . $e->getMessage()));
            throw new \Magento\Framework\Validator\Exception(__($e->getMessage()));
        }

        return $this;
    }

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if ($quote && (
            $quote->getBaseGrandTotal() < $this->_minAmount
            || ($this->_maxAmount && $quote->getBaseGrandTotal() > $this->_maxAmount))
        ) {
            return false;
        }

        if (!$this->_secretKey) {
            return false;
        }

        return parent::isAvailable($quote);
    }
}