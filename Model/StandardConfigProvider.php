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

class StandardConfigProvider implements ConfigProviderInterface
{
    /**
     * @var \Magento\Payment\Model\Method\AbstractMethod
     */
    protected $standardMethod = false;

    /**
     * @var PaymentHelper
     */
    protected $paymentHelper;

    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * @param PaymentHelper $paymentHelper
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        PaymentHelper $paymentHelper,
        UrlInterface $urlBuilder
    ) {
        $this->paymentHelper = $paymentHelper;
        $this->urlBuilder = $urlBuilder;

        $this->standardMethod = $this->paymentHelper->getMethodInstance('profibro_paystack');
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig()
    {
        $config = [
            'payment' => [
                'paystackStandard' => [],
            ],
        ];
        if ($this->standardMethod->isAvailable()) {
            $config['payment']['paystackStandard']['initializeUrl'] = $this->getInitializeUrl();
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
        return $this->urlBuilder->getUrl('paystack/initialize', ['_secure' => true]);
    }
}
