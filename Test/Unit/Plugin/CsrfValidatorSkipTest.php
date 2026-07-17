<?php

namespace Pstk\Paystack\Test\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use Pstk\Paystack\Plugin\CsrfValidatorSkip;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ActionInterface;

class CsrfValidatorSkipTest extends TestCase
{
    /** @var CsrfValidatorSkip */
    private $plugin;

    protected function setUp(): void
    {
        $this->plugin = new CsrfValidatorSkip();
    }

    public function testWebhookActionSkipsCsrfValidation(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getModuleName')->willReturn('paystack');
        $request->method('getActionName')->willReturn('webhook');

        $action = $this->createMock(ActionInterface::class);
        $subject = new \stdClass();

        $proceedCalled = false;
        $proceed = function () use (&$proceedCalled) {
            $proceedCalled = true;
        };

        $this->plugin->aroundValidate($subject, $proceed, $request, $action);

        $this->assertFalse($proceedCalled, 'CSRF validation should be skipped for webhook');
    }

    public function testNonWebhookActionProceedsWithCsrfValidation(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getModuleName')->willReturn('checkout');
        $request->method('getActionName')->willReturn('index');

        $action = $this->createMock(ActionInterface::class);
        $subject = new \stdClass();

        $proceedCalled = false;
        $proceed = function () use (&$proceedCalled) {
            $proceedCalled = true;
        };

        $this->plugin->aroundValidate($subject, $proceed, $request, $action);

        $this->assertTrue($proceedCalled, 'CSRF validation should proceed for non-webhook actions');
    }

    public function testPaystackNonWebhookActionProceedsNormally(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getModuleName')->willReturn('paystack');
        $request->method('getActionName')->willReturn('callback');

        $action = $this->createMock(ActionInterface::class);
        $subject = new \stdClass();

        $proceedCalled = false;
        $proceed = function () use (&$proceedCalled) {
            $proceedCalled = true;
        };

        $this->plugin->aroundValidate($subject, $proceed, $request, $action);

        $this->assertTrue($proceedCalled, 'CSRF validation should proceed for paystack/callback');
    }
}
