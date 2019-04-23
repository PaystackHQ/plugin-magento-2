<?php
namespace Pstk\Paystack\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Store\Model\Store as Store;

/**
 * Class ConfigProvider
 */
final class ConfigProvider implements ConfigProviderInterface
{

    protected $method;

    public function __construct(PaymentHelper $paymentHelper, Store $store)
    {
        $this->method = $paymentHelper->getMethodInstance(\Pstk\Paystack\Model\Payment\Paystack::CODE);
        $this->store = $store;
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig()
    {
        $publicKey = $this->method->getConfigData('live_public_key');
        if ($this->method->getConfigData('test_mode')) {
            $publicKey = $this->method->getConfigData('test_public_key');
        }
        
        $integrationType = $this->method->getConfigData('integration_type')?: 'inline';

        return [
            'payment' => [
                \Pstk\Paystack\Model\Payment\Paystack::CODE => [
                    'public_key' => $publicKey,
                    'integration_type' => $integrationType,
                    'api_url' => $this->store->getBaseUrl() . 'rest/',
                    'integration_type_standard_url' => $this->store->getBaseUrl() . 'paystack/payment/setup',
                ]
            ]
        ];
    }
    
    public function getStore() {
        return $this->store;
    }
    
    
}
