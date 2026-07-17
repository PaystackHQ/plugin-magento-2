<?php

namespace Pstk\Paystack\Test\Unit\Model\Ui;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Pstk\Paystack\Model\Ui\ConfigProvider;
use Pstk\Paystack\Model\Payment\Paystack;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\MethodInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class ConfigProviderTest extends TestCase
{
    /** @var ConfigProvider */
    private $configProvider;

    /** @var MockObject|MethodInterface */
    private $paymentMethod;

    /** @var MockObject|StoreManagerInterface */
    private $storeManager;

    protected function setUp(): void
    {
        $this->paymentMethod = $this->createMock(MethodInterface::class);

        $paymentHelper = $this->createMock(PaymentHelper::class);
        $paymentHelper->method('getMethodInstance')
            ->with(Paystack::CODE)
            ->willReturn($this->paymentMethod);

        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->configProvider = new ConfigProvider($paymentHelper, $this->storeManager, $logger);
    }

    private function setupStore(string $baseUrl = 'https://shop.example.com/'): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getBaseUrl')->willReturn($baseUrl);
        $this->storeManager->method('getStore')->willReturn($store);
    }

    public function testGetConfigReturnsCorrectStructureTestMode(): void
    {
        $this->setupStore();

        $this->paymentMethod->method('getConfigData')
            ->willReturnCallback(function ($field) {
                return match ($field) {
                    'test_mode' => true,
                    'test_public_key' => 'pk_test_abc123',
                    'live_public_key' => 'pk_live_xyz',
                    'integration_type' => 'inline',
                    default => null,
                };
            });

        $config = $this->configProvider->getConfig();

        $this->assertArrayHasKey('payment', $config);
        $this->assertArrayHasKey(Paystack::CODE, $config['payment']);

        $pstk = $config['payment'][Paystack::CODE];
        $this->assertEquals('pk_test_abc123', $pstk['public_key']);
        $this->assertEquals('inline', $pstk['integration_type']);
        $this->assertEquals('https://shop.example.com/rest/', $pstk['api_url']);
        $this->assertEquals('https://shop.example.com/paystack/payment/setup', $pstk['integration_type_standard_url']);
        $this->assertEquals('https://shop.example.com/paystack/payment/recreate', $pstk['recreate_quote_url']);
    }

    public function testGetConfigUsesLiveKeyWhenNotTestMode(): void
    {
        $this->setupStore();

        $this->paymentMethod->method('getConfigData')
            ->willReturnCallback(function ($field) {
                return match ($field) {
                    'test_mode' => false,
                    'live_public_key' => 'pk_live_real',
                    'integration_type' => 'redirect',
                    default => null,
                };
            });

        $config = $this->configProvider->getConfig();

        $this->assertEquals('pk_live_real', $config['payment'][Paystack::CODE]['public_key']);
        $this->assertEquals('redirect', $config['payment'][Paystack::CODE]['integration_type']);
    }

    public function testGetConfigDefaultsToInlineIntegrationType(): void
    {
        $this->setupStore();

        $this->paymentMethod->method('getConfigData')
            ->willReturnCallback(function ($field) {
                return match ($field) {
                    'test_mode' => true,
                    'test_public_key' => 'pk_test_123',
                    'integration_type' => null,
                    default => null,
                };
            });

        $config = $this->configProvider->getConfig();

        $this->assertEquals('inline', $config['payment'][Paystack::CODE]['integration_type']);
    }

    public function testGetConfigReturnsEmptyOnException(): void
    {
        $paymentHelper = $this->createMock(PaymentHelper::class);
        $paymentHelper->method('getMethodInstance')
            ->willThrowException(new \Exception('Method not found'));

        $configProvider = new ConfigProvider(
            $paymentHelper,
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(LoggerInterface::class)
        );

        $this->assertEquals([], $configProvider->getConfig());
    }

    public function testGetPublicKeyTestMode(): void
    {
        $this->paymentMethod->method('getConfigData')
            ->willReturnCallback(function ($field) {
                return match ($field) {
                    'test_mode' => true,
                    'test_public_key' => 'pk_test_xyz',
                    default => null,
                };
            });

        $this->assertEquals('pk_test_xyz', $this->configProvider->getPublicKey());
    }

    public function testGetPublicKeyLiveMode(): void
    {
        $this->paymentMethod->method('getConfigData')
            ->willReturnCallback(function ($field) {
                return match ($field) {
                    'test_mode' => false,
                    'live_public_key' => 'pk_live_prod',
                    default => null,
                };
            });

        $this->assertEquals('pk_live_prod', $this->configProvider->getPublicKey());
    }

    public function testGetSecretKeyArrayTestMode(): void
    {
        $this->paymentMethod->method('getConfigData')
            ->willReturnCallback(function ($field) {
                return match ($field) {
                    'test_mode' => true,
                    'test_secret_key' => 'sk_test_secret',
                    default => null,
                };
            });

        $this->assertEquals(['test' => 'sk_test_secret'], $this->configProvider->getSecretKeyArray());
    }

    public function testGetSecretKeyArrayLiveMode(): void
    {
        $this->paymentMethod->method('getConfigData')
            ->willReturnCallback(function ($field) {
                return match ($field) {
                    'test_mode' => false,
                    'live_secret_key' => 'sk_live_secret',
                    default => null,
                };
            });

        $this->assertEquals(['live' => 'sk_live_secret'], $this->configProvider->getSecretKeyArray());
    }
}
