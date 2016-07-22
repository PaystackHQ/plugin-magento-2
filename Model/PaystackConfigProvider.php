<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Profibro\Paystack\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data as PaymentHelper;

class PaystackConfigProvider implements ConfigProviderInterface
{
    /**
     * @var \Magento\Payment\Model\Method\AbstractMethod
     */
    protected $paystackMethod = false;

    /**
     * @var PaymentHelper
     */
    protected $paymentHelper;

    /**
     * @var UrlInterface
     */
    protected $_urlBuilder;

    /**
     * @param PaymentHelper $paymentHelper
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        PaymentHelper $paymentHelper,
        UrlInterface $urlBuilder
    ) {
        $this->paymentHelper = $paymentHelper;
        $this->_urlBuilder = $urlBuilder;

        $this->paystackMethod = $this->paymentHelper->getMethodInstance('profibro_paystack');
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig()
    {
        $config = [
            'payment' => [
                'paystack' => [],
            ],
        ];
        $config['payment']['paystack']['enabled'] = false;
        if ( $this->paystackMethod->isAvailable() ){
			$config['payment']['paystack']['enabled'] = true;
        	if ( $this->paystackMethod->shouldUseInline() ) {
				$config['payment']['paystack']['redirectUrl'] = $this->getPopUrl();
			} else {
				$config['payment']['paystack']['redirectUrl'] = $this->getInitializeUrl();
			}
        }

        return $config;
    }

    /**
     * Get initialize URL
     *
     * @param string $code
     * @return string
     */
    protected function getInitializeUrl()
    {
        return $this->_urlBuilder->getUrl('paystack/initialize', ['_secure' => true]);
    }

    /**
     * Get pop URL
     *
     * @return string
     */
    protected function getPopUrl()
    {
        return $this->_urlBuilder->getUrl('paystack/pop', ['_secure' => true]);
    }
}
