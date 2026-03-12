<?php

namespace Pstk\Paystack\Test\Unit\Gateway;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Pstk\Paystack\Gateway\PaystackApiClient;
use Pstk\Paystack\Model\Payment\Paystack;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\MethodInterface;

class PaystackApiClientTest extends TestCase
{
    /** @var PaystackApiClient */
    private $client;

    /** @var MockObject|PaymentHelper */
    private $paymentHelper;

    /** @var MockObject|MethodInterface */
    private $paymentMethod;

    protected function setUp(): void
    {
        $this->paymentHelper = $this->createMock(PaymentHelper::class);
        $this->paymentMethod = $this->createMock(MethodInterface::class);

        $this->paymentHelper->method('getMethodInstance')
            ->with(Paystack::CODE)
            ->willReturn($this->paymentMethod);

        $this->client = new PaystackApiClient($this->paymentHelper);
    }

    public function testValidateWebhookSignatureValid(): void
    {
        $this->paymentMethod->method('getConfigData')
            ->willReturnCallback(function ($field) {
                if ($field === 'test_mode') return true;
                if ($field === 'test_secret_key') return 'sk_test_secret123';
                return null;
            });

        $rawBody = '{"event":"charge.success","data":{"reference":"abc123"}}';
        $signature = hash_hmac('sha512', $rawBody, 'sk_test_secret123');

        $this->assertTrue($this->client->validateWebhookSignature($rawBody, $signature));
    }

    public function testValidateWebhookSignatureInvalid(): void
    {
        $this->paymentMethod->method('getConfigData')
            ->willReturnCallback(function ($field) {
                if ($field === 'test_mode') return true;
                if ($field === 'test_secret_key') return 'sk_test_secret123';
                return null;
            });

        $rawBody = '{"event":"charge.success"}';
        $forgedSignature = hash_hmac('sha512', $rawBody, 'wrong_key');

        $this->assertFalse($this->client->validateWebhookSignature($rawBody, $forgedSignature));
    }

    public function testValidateWebhookSignatureUsesLiveKeyWhenNotTestMode(): void
    {
        $this->paymentMethod->method('getConfigData')
            ->willReturnCallback(function ($field) {
                if ($field === 'test_mode') return false;
                if ($field === 'live_secret_key') return 'sk_live_real456';
                return null;
            });

        $rawBody = '{"event":"charge.success"}';
        $signature = hash_hmac('sha512', $rawBody, 'sk_live_real456');

        $this->assertTrue($this->client->validateWebhookSignature($rawBody, $signature));
    }

    public function testValidateWebhookSignatureEmptySignatureFails(): void
    {
        $this->paymentMethod->method('getConfigData')
            ->willReturnCallback(function ($field) {
                if ($field === 'test_mode') return true;
                if ($field === 'test_secret_key') return 'sk_test_secret123';
                return null;
            });

        $this->assertFalse($this->client->validateWebhookSignature('body', ''));
    }

    public function testValidateWebhookSignatureTampered(): void
    {
        $this->paymentMethod->method('getConfigData')
            ->willReturnCallback(function ($field) {
                if ($field === 'test_mode') return true;
                if ($field === 'test_secret_key') return 'sk_test_secret123';
                return null;
            });

        $originalBody = '{"event":"charge.success","data":{"amount":10000}}';
        $signature = hash_hmac('sha512', $originalBody, 'sk_test_secret123');

        $tamperedBody = '{"event":"charge.success","data":{"amount":1}}';

        $this->assertFalse($this->client->validateWebhookSignature($tamperedBody, $signature));
    }
}
