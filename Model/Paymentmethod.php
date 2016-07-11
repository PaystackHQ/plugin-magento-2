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
namespace Paystack\Inline\Model;

class Paymentmethod extends \Magento\Payment\Model\Method\AbstractMethod {
    protected $_code  = 'paystack_inline';
    protected $_formBlockType = 'paystack_inline/form_inline';
    protected $_infoBlockType = 'paystack_inline/info_inline';
    public function __construct(
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
    }

    public function validate()
    {
        parent::validate();
        $info = $this->getInfoInstance();
 
        return $this;
    }

    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('paystack/payment/pop');
    }
}