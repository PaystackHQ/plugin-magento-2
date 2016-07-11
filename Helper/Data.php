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
namespace Paystack\Inline\Helper;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    const XML_PATH_TEST_MODE        = 'payment/paystack_inline/test_mode';

    const XML_PATH_PUBLIC_KEY_LIVE  = 'payment/paystack_inline/public_key_live';
    const XML_PATH_SECRET_KEY_LIVE  = 'payment/paystack_inline/secret_key_live';
    const XML_PATH_PUBLIC_KEY_TEST  = 'payment/paystack_inline/public_key_test';
    const XML_PATH_SECRET_KEY_TEST  = 'payment/paystack_inline/secret_key_test';

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $salesOrderFactory;

    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Sales\Model\OrderFactory $salesOrderFactory
    ) {
        $this->salesOrderFactory = $salesOrderFactory;
        $this->scopeConfig = $scopeConfig;
        $this->checkoutSession = $checkoutSession;
        parent::__construct(
            $context
        );
    }


    function getPublicKey(){
        if($this->scopeConfig->getValue(\Paystack\Inline\Helper\Data::XML_PATH_TEST_MODE, \Magento\Store\Model\ScopeInterface::SCOPE_STORE)){
            return trim($this->scopeConfig->getValue(\Paystack\Inline\Helper\Data::XML_PATH_PUBLIC_KEY_TEST, \Magento\Store\Model\ScopeInterface::SCOPE_STORE));
        } else{
            return trim($this->scopeConfig->getValue(\Paystack\Inline\Helper\Data::XML_PATH_PUBLIC_KEY_LIVE, \Magento\Store\Model\ScopeInterface::SCOPE_STORE));
        }
    }
    
    function getSecretKey(){
        if($this->scopeConfig->getValue(\Paystack\Inline\Helper\Data::XML_PATH_TEST_MODE, \Magento\Store\Model\ScopeInterface::SCOPE_STORE)){
            return trim($this->scopeConfig->getValue(\Paystack\Inline\Helper\Data::XML_PATH_SECRET_KEY_TEST, \Magento\Store\Model\ScopeInterface::SCOPE_STORE));
        } else{
            return trim($this->scopeConfig->getValue(\Paystack\Inline\Helper\Data::XML_PATH_SECRET_KEY_LIVE, \Magento\Store\Model\ScopeInterface::SCOPE_STORE));
        }
    }
    
    function verifyTransaction($trxref)
    {
        $ch = curl_init();
        $transactionStatus = new \stdClass();

        // set url
        curl_setopt($ch, CURLOPT_URL, "https://api.paystack.co/transaction/verify/" . rawurlencode($trxref));

        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer '. \Paystack\Inline\Helper\Data::getSecretKey()
        ));

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, false);
        
        // Make sure CURL_SSLVERSION_TLSv1_2 is defined as 6
        // cURL must be able to use TLSv1.2 to connect
        // to Paystack servers
        if (!defined('CURL_SSLVERSION_TLSv1_2')) {
            define('CURL_SSLVERSION_TLSv1_2', 6);
        }
        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        
        // exec the cURL
        $response = curl_exec($ch);
        
        // should be 0
        if (curl_errno($ch)) {   
            // curl ended with an error
            $transactionStatus->error = "cURL said:" . curl_error($ch);
            curl_close($ch);
        } else {

            //close connection
            curl_close($ch);

            // Then, after your curl_exec call:
            $body = json_decode($response);
            if(!$body->status){
                // paystack has an error message for us
                $transactionStatus->error = "Paystack API said: " . $body->message;
            } else {
                // get body returned by Paystack API
                $transactionStatus = $body->data;
            }
        }

        return $transactionStatus;
    }
    
    function getFormParams() 
    {
        $order = $this->salesOrderFactory->create();
        $orderId = $this->checkoutSession->getLastRealOrderId();
        
        // return blank params if no order is found
        if(!$orderId){
            return array();
        }
        $order->loadByIncrementId($orderId);

        // get an email for this transaction
        $billing  = $order->getBillingAddress();
        if ($order->getBillingAddress()->getEmail()) {
            $email = $order->getBillingAddress()->getEmail();
        } else {
            $email = $order->getCustomerEmail();
        }

        $params = array(
            'key'         => \Paystack\Inline\Helper\Data::getPublicKey(),
            'orderId'     => $orderId,
            'nextUrl'     => Mage::getUrl('paystack/payment/response', array('_query'=> array('orderId' => $orderId))),
            'cancelUrl'   => Mage::getUrl('paystack/payment/cancel'),
            'amount'      => round($order->getGrandTotal(), 2) * 100,
            'currency'    => $order->getOrderCurrencyCode(),
            'firstname'   => $billing->getFirstname(),
            'lastname'    => $billing->getLastname(),
            'address'     => $billing->getStreet(-1),
            'email'       => $email,
            'phone'       => $billing->getTelephone(),
            'remarks'     => $this->__('Order ID: ') . $orderId
        );
        
        return $params;
    }
}
