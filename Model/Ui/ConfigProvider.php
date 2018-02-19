<?php
namespace Paystack\Paystack\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Store\Model\Store as Store;

/**
 * Class ConfigProvider
 */
final class ConfigProvider implements ConfigProviderInterface
{
    const CODE = 'pstk_paystack';

    protected $method;

    public function __construct(PaymentHelper $paymentHelper, Store $store)
    {
        $this->method = $paymentHelper->getMethodInstance(self::CODE);
        $this->store = $store;
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig()
    {
        $public_key = $this->method->getConfigData('live_public_key');
        if ($this->method->getConfigData('test_mode')) {
            $public_key = $this->method->getConfigData('test_public_key');
        }

        return [
            'payment' => [
                self::CODE => [
                    'public_key' => $public_key,
                    'api_url' => $this->store->getBaseUrl() . 'rest/'
                ]
            ]
        ];
    }
}
