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

    public function testIsTestModeTrueWhenConfigTestModeIsOn(): void
    {
        $this->paymentMethod->method('getConfigData')
            ->willReturnCallback(function ($field) {
                if ($field === 'test_mode') return true;
                return null;
            });

        $this->assertTrue($this->client->isTestMode());
    }

    public function testIsTestModeFalseWhenConfigTestModeIsOff(): void
    {
        $this->paymentMethod->method('getConfigData')
            ->willReturnCallback(function ($field) {
                if ($field === 'test_mode') return false;
                return null;
            });

        $this->assertFalse($this->client->isTestMode());
    }

    /**
     * An unconfigured/empty secret key makes hash_hmac(..., '') computable by
     * anyone, so it must never be allowed to trivially satisfy the signature
     * check — even a signature genuinely computed against the empty key must
     * be rejected.
     */
    public function testValidateWebhookSignatureEmptySecretKeyFails(): void
    {
        $this->paymentMethod->method('getConfigData')
            ->willReturnCallback(function ($field) {
                if ($field === 'test_mode') return true;
                if ($field === 'test_secret_key') return '';
                return null;
            });

        $rawBody = '{"event":"charge.success"}';
        $signatureComputedAgainstEmptyKey = hash_hmac('sha512', $rawBody, '');

        $this->assertFalse($this->client->validateWebhookSignature($rawBody, $signatureComputedAgainstEmptyKey));
    }

    /**
     * The real unconfigured-config shape: `getConfigData()` returns `null` for
     * an unset field (the `?? null` default this test's callback simulates via
     * falling through to `return null`), not an already-empty string —
     * `getSecretKey()`'s `(string)` cast turns that into `''`, and this
     * confirms the cast-then-guard chain rejects it end to end, not just the
     * already-empty-string shortcut above.
     */
    public function testValidateWebhookSignatureNullSecretKeyFails(): void
    {
        $this->paymentMethod->method('getConfigData')
            ->willReturnCallback(function ($field) {
                if ($field === 'test_mode') return true;
                if ($field === 'test_secret_key') return null;
                return null;
            });

        $rawBody = '{"event":"charge.success"}';
        $signatureComputedAgainstEmptyKey = hash_hmac('sha512', $rawBody, '');

        $this->assertFalse($this->client->validateWebhookSignature($rawBody, $signatureComputedAgainstEmptyKey));
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
