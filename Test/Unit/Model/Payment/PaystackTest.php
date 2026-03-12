<?php

namespace Pstk\Paystack\Test\Unit\Model\Payment;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Pstk\Paystack\Model\Payment\Paystack;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\InfoInterface;
use Magento\Quote\Api\Data\CartInterface;

class PaystackTest extends TestCase
{
    /** @var Paystack */
    private $model;

    /** @var MockObject|ScopeConfigInterface */
    private $scopeConfig;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->model = new Paystack($this->scopeConfig);
    }

    // ---- Identity ----

    public function testGetCodeReturnsPstkPaystack(): void
    {
        $this->assertEquals('pstk_paystack', $this->model->getCode());
    }

    // ---- Store scope ----

    public function testSetAndGetStore(): void
    {
        $this->model->setStore(5);
        $this->assertEquals(5, $this->model->getStore());
    }

    // ---- Configuration ----

    public function testGetConfigDataReadsFromScopeConfig(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('getValue')
            ->with('payment/pstk_paystack/title', 'store', null)
            ->willReturn('Pay with Paystack');

        $this->assertEquals('Pay with Paystack', $this->model->getConfigData('title'));
    }

    public function testGetConfigDataUsesStoreId(): void
    {
        $this->model->setStore(3);

        $this->scopeConfig->expects($this->once())
            ->method('getValue')
            ->with('payment/pstk_paystack/active', 'store', 3)
            ->willReturn('1');

        $this->assertEquals('1', $this->model->getConfigData('active'));
    }

    public function testGetConfigDataStoreIdParamOverridesSetStore(): void
    {
        $this->model->setStore(3);

        $this->scopeConfig->expects($this->once())
            ->method('getValue')
            ->with('payment/pstk_paystack/active', 'store', 7)
            ->willReturn('0');

        $this->assertEquals('0', $this->model->getConfigData('active', 7));
    }

    public function testGetTitle(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('Paystack');
        $this->assertEquals('Paystack', $this->model->getTitle());
    }

    // ---- Availability ----

    public function testIsActiveWhenConfigEnabled(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('1');
        $this->assertTrue($this->model->isActive());
    }

    public function testIsActiveWhenConfigDisabled(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('0');
        $this->assertFalse($this->model->isActive());
    }

    public function testIsAvailableWhenActiveAndCheckoutAllowed(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnCallback(function ($path) {
                if (str_ends_with($path, '/active')) return '1';
                if (str_ends_with($path, '/can_use_checkout')) return '1';
                return null;
            });

        $quote = $this->createMock(CartInterface::class);
        $quote->method('getStoreId')->willReturn(1);

        $this->assertTrue($this->model->isAvailable($quote));
    }

    public function testIsAvailableReturnsFalseWhenInactive(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('0');

        $this->assertFalse($this->model->isAvailable());
    }

    public function testCanUseInternalReturnsFalse(): void
    {
        $this->assertFalse($this->model->canUseInternal());
    }

    public function testCanUseCheckoutDefaultsToTrue(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        $this->assertTrue($this->model->canUseCheckout());
    }

    // ---- Country restrictions ----

    public function testCanUseForCountryAllowsAllWhenNotRestricted(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('0');
        $this->assertTrue($this->model->canUseForCountry('NG'));
    }

    public function testCanUseForCountryAllowsSpecificCountry(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnCallback(function ($path) {
                if (str_ends_with($path, '/allowspecific')) return '1';
                if (str_ends_with($path, '/specificcountry')) return 'NG,GH,ZA';
                return null;
            });

        $this->assertTrue($this->model->canUseForCountry('NG'));
    }

    public function testCanUseForCountryRejectsUnlistedCountry(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnCallback(function ($path) {
                if (str_ends_with($path, '/allowspecific')) return '1';
                if (str_ends_with($path, '/specificcountry')) return 'NG,GH';
                return null;
            });

        $this->assertFalse($this->model->canUseForCountry('US'));
    }

    public function testCanUseForCountryAllowsEmptyCountry(): void
    {
        $this->assertTrue($this->model->canUseForCountry(''));
    }

    // ---- Currency ----

    public function testCanUseForCurrencyAcceptsAnyCurrency(): void
    {
        $this->assertTrue($this->model->canUseForCurrency('NGN'));
        $this->assertTrue($this->model->canUseForCurrency('USD'));
        $this->assertTrue($this->model->canUseForCurrency('GHS'));
        $this->assertTrue($this->model->canUseForCurrency('ZAR'));
        $this->assertTrue($this->model->canUseForCurrency('KES'));
    }

    // ---- Capability flags ----

    public function testIsGatewayReturnsTrue(): void
    {
        $this->assertTrue($this->model->isGateway());
    }

    public function testIsOfflineReturnsFalse(): void
    {
        $this->assertFalse($this->model->isOffline());
    }

    public function testCanOrderReturnsFalse(): void
    {
        $this->assertFalse($this->model->canOrder());
    }

    public function testCanAuthorizeReturnsFalse(): void
    {
        $this->assertFalse($this->model->canAuthorize());
    }

    public function testCanCaptureReturnsFalse(): void
    {
        $this->assertFalse($this->model->canCapture());
    }

    public function testCanCapturePartialReturnsFalse(): void
    {
        $this->assertFalse($this->model->canCapturePartial());
    }

    public function testCanCaptureOnceReturnsFalse(): void
    {
        $this->assertFalse($this->model->canCaptureOnce());
    }

    public function testCanRefundReturnsFalse(): void
    {
        $this->assertFalse($this->model->canRefund());
    }

    public function testCanRefundPartialPerInvoiceReturnsFalse(): void
    {
        $this->assertFalse($this->model->canRefundPartialPerInvoice());
    }

    public function testCanVoidReturnsFalse(): void
    {
        $this->assertFalse($this->model->canVoid());
    }

    public function testCanEditReturnsTrue(): void
    {
        $this->assertTrue($this->model->canEdit());
    }

    public function testCanFetchTransactionInfoReturnsFalse(): void
    {
        $this->assertFalse($this->model->canFetchTransactionInfo());
    }

    public function testCanReviewPaymentReturnsFalse(): void
    {
        $this->assertFalse($this->model->canReviewPayment());
    }

    // ---- Payment actions throw exceptions ----

    public function testOrderThrowsException(): void
    {
        $this->expectException(LocalizedException::class);
        $payment = $this->createMock(InfoInterface::class);
        $this->model->order($payment, 100);
    }

    public function testAuthorizeThrowsException(): void
    {
        $this->expectException(LocalizedException::class);
        $payment = $this->createMock(InfoInterface::class);
        $this->model->authorize($payment, 100);
    }

    public function testCaptureThrowsException(): void
    {
        $this->expectException(LocalizedException::class);
        $payment = $this->createMock(InfoInterface::class);
        $this->model->capture($payment, 100);
    }

    public function testRefundThrowsException(): void
    {
        $this->expectException(LocalizedException::class);
        $payment = $this->createMock(InfoInterface::class);
        $this->model->refund($payment, 50);
    }

    public function testVoidThrowsException(): void
    {
        $this->expectException(LocalizedException::class);
        $payment = $this->createMock(InfoInterface::class);
        $this->model->void($payment);
    }

    public function testAcceptPaymentThrowsException(): void
    {
        $this->expectException(LocalizedException::class);
        $payment = $this->createMock(InfoInterface::class);
        $this->model->acceptPayment($payment);
    }

    public function testDenyPaymentThrowsException(): void
    {
        $this->expectException(LocalizedException::class);
        $payment = $this->createMock(InfoInterface::class);
        $this->model->denyPayment($payment);
    }

    // ---- Non-throwing methods ----

    public function testCancelReturnsThis(): void
    {
        $payment = $this->createMock(InfoInterface::class);
        $this->assertSame($this->model, $this->model->cancel($payment));
    }

    public function testValidateReturnsThis(): void
    {
        $this->assertSame($this->model, $this->model->validate());
    }

    public function testAssignDataReturnsThis(): void
    {
        $data = new \Magento\Framework\DataObject();
        $this->assertSame($this->model, $this->model->assignData($data));
    }

    public function testInitializeReturnsThis(): void
    {
        $stateObject = new \stdClass();
        $this->assertSame($this->model, $this->model->initialize('authorize', $stateObject));
    }

    public function testFetchTransactionInfoReturnsEmptyArray(): void
    {
        $payment = $this->createMock(InfoInterface::class);
        $this->assertEquals([], $this->model->fetchTransactionInfo($payment, 'txn_123'));
    }

    // ---- Block types ----

    public function testGetFormBlockType(): void
    {
        $this->assertEquals(\Magento\Payment\Block\Form::class, $this->model->getFormBlockType());
    }

    public function testGetInfoBlockType(): void
    {
        $this->assertEquals(\Magento\Payment\Block\Info::class, $this->model->getInfoBlockType());
    }

    // ---- Info instance ----

    public function testSetAndGetInfoInstance(): void
    {
        $info = $this->createMock(InfoInterface::class);
        $this->model->setInfoInstance($info);
        $this->assertSame($info, $this->model->getInfoInstance());
    }

    public function testGetInfoInstanceReturnsNullByDefault(): void
    {
        $this->assertNull($this->model->getInfoInstance());
    }
}
