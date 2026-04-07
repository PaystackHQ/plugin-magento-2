<?php
namespace Pstk\Paystack\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class ConfigProvider implements ConfigProviderInterface
{

    protected $paymentHelper;
    protected $storeManager;
    protected $logger;
    private $method;

    public function __construct(
        PaymentHelper $paymentHelper,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->paymentHelper = $paymentHelper;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * @return \Magento\Payment\Model\MethodInterface
     */
    private function getMethod()
    {
        if ($this->method === null) {
            $this->method = $this->paymentHelper->getMethodInstance(\Pstk\Paystack\Model\Payment\Paystack::CODE);
        }
        return $this->method;
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig()
    {
        try {
            $method = $this->getMethod();

            $publicKey = $method->getConfigData('live_public_key');
            if ($method->getConfigData('test_mode')) {
                $publicKey = $method->getConfigData('test_public_key');
            }

            $integrationType = $method->getConfigData('integration_type') ?: 'inline';
            $baseUrl = $this->storeManager->getStore()->getBaseUrl();

            return [
                'payment' => [
                    \Pstk\Paystack\Model\Payment\Paystack::CODE => [
                        'public_key' => $publicKey,
                        'integration_type' => $integrationType,
                        'api_url' => $baseUrl . 'rest/',
                        'integration_type_standard_url' => $baseUrl . 'paystack/payment/setup',
                        'recreate_quote_url' => $baseUrl . 'paystack/payment/recreate',
                    ]
                ]
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Paystack ConfigProvider: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get secret key for webhook process
     *
     * @return array
     */
    public function getSecretKeyArray()
    {
        $method = $this->getMethod();
        $data = ["live" => $method->getConfigData('live_secret_key')];
        if ($method->getConfigData('test_mode')) {
            $data = ["test" => $method->getConfigData('test_secret_key')];
        }

        return $data;
    }

    public function getPublicKey()
    {
        $method = $this->getMethod();
        $publicKey = $method->getConfigData('live_public_key');
        if ($method->getConfigData('test_mode')) {
            $publicKey = $method->getConfigData('test_public_key');
        }
        return $publicKey;
    }
}
