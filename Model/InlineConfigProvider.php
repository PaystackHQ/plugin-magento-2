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

class InlineConfigProvider implements ConfigProviderInterface
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

        $this->paystackMethod = $this->paymentHelper->getMethodInstance('profibro_paystack');
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig()
    {
        $config = [
            'payment' => [
                'paystackInline' => [],
            ],
        ];
        $config['payment']['paystackInline']['enabled'] = false;
        if ( $this->paystackMethod->isAvailable() && $this->paystackMethod->shouldUseInline() ) {
            $config['payment']['paystackInline']['popUrl'] = $this->getPopUrl();
            $config['payment']['paystackInline']['publicKey'] = $this->paystackMethod->getPublicKey();
            $config['payment']['paystackInline']['enabled'] = true;
        }

        return $config;
    }

    /**
     * Get initialize URL
     *
     * @return string
     */
    protected function getPopUrl()
    {
        return $this->urlBuilder->getUrl('paystack/pop', ['_secure' => true]);
    }
}
