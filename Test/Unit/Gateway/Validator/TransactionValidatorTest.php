<?php

namespace Pstk\Paystack\Test\Unit\Gateway\Validator;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Pstk\Paystack\Gateway\Validator\TransactionValidator;
use Magento\Sales\Api\Data\OrderInterface;

class TransactionValidatorTest extends TestCase
{
    /** @var TransactionValidator */
    private $validator;

    protected function setUp(): void
    {
        $this->validator = new TransactionValidator();
    }

    /**
     * @param float|string|null $grandTotal
     * @param string $currencyCode
     * @return MockObject|OrderInterface
     */
    private function makeOrder($grandTotal, $currencyCode)
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getOrderCurrencyCode')->willReturn($currencyCode);

        return $order;
    }

    private function makeVerifyData(array $overrides = [])
    {
        $data = (object) array_merge([
            'status' => 'success',
            'currency' => 'NGN',
            'amount' => 10000,
            'domain' => 'test',
        ], $overrides);

        return $data;
    }

    public function testAllValidReturnsEmptyArray(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $result = $this->validator->validate($this->makeVerifyData(), $order, true);

        $this->assertSame([], $result);
    }

    public function testDeclinedStatusShortCircuitsEvenWhenOthersWouldAlsoFail(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = $this->makeVerifyData([
            'status' => 'failed',
            'currency' => 'USD',
            'amount' => 1,
            'domain' => 'live',
        ]);

        $result = $this->validator->validate($verifyData, $order, true);

        $this->assertCount(1, $result);
        $this->assertSame(TransactionValidator::STATUS_NOT_SUCCESS, $result[0]['code']);
    }

    public function testCurrencyMismatchFailsIndependently(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = $this->makeVerifyData(['currency' => 'USD']);

        $result = $this->validator->validate($verifyData, $order, true);

        $this->assertSame([TransactionValidator::CURRENCY_MISMATCH], array_column($result, 'code'));
    }

    public function testAmountMismatchFailsIndependently(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = $this->makeVerifyData(['amount' => 1]);

        $result = $this->validator->validate($verifyData, $order, true);

        $this->assertSame([TransactionValidator::AMOUNT_MISMATCH], array_column($result, 'code'));
    }

    public function testDomainMismatchFailsIndependently(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = $this->makeVerifyData(['domain' => 'live']);

        $result = $this->validator->validate($verifyData, $order, true);

        $this->assertSame([TransactionValidator::MODE_MISMATCH], array_column($result, 'code'));
    }

    public function testLiveDomainMatchesWhenTestModeIsFalse(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = $this->makeVerifyData(['domain' => 'live']);

        $result = $this->validator->validate($verifyData, $order, false);

        $this->assertSame([], $result);
    }

    public function testEveryErrorHasCodeAndMessageKeys(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = $this->makeVerifyData([
            'currency' => 'USD',
            'amount' => 1,
            'domain' => 'live',
        ]);

        $result = $this->validator->validate($verifyData, $order, true);

        $this->assertNotEmpty($result);
        foreach ($result as $error) {
            $this->assertArrayHasKey('code', $error);
            $this->assertArrayHasKey('message', $error);
        }
    }

    public function testAmountAtExactExpectedIsAccepted(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = $this->makeVerifyData(['amount' => 10000]);

        $result = $this->validator->validate($verifyData, $order, true);

        $codes = array_column($result, 'code');
        $this->assertNotContains(TransactionValidator::AMOUNT_MISMATCH, $codes);
        $this->assertNotContains(TransactionValidator::AMOUNT_EXCESS, $codes);
    }

    public function testLegacyMathCeilOverpaymentOfOneSubunitIsAccepted(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = $this->makeVerifyData(['amount' => 10001]);

        $result = $this->validator->validate($verifyData, $order, true);

        $codes = array_column($result, 'code');
        $this->assertNotContains(TransactionValidator::AMOUNT_MISMATCH, $codes);
        $this->assertNotContains(TransactionValidator::AMOUNT_EXCESS, $codes);
    }

    public function testAmountOneSubunitBelowExpectedIsRejected(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = $this->makeVerifyData(['amount' => 9999]);

        $result = $this->validator->validate($verifyData, $order, true);

        $codes = array_column($result, 'code');
        $this->assertContains(TransactionValidator::AMOUNT_MISMATCH, $codes);
    }

    public function testAmountTwoSubunitsAboveExpectedIsRejectedAsExcess(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = $this->makeVerifyData(['amount' => 10002]);

        $result = $this->validator->validate($verifyData, $order, true);

        $codes = array_column($result, 'code');
        $this->assertContains(TransactionValidator::AMOUNT_EXCESS, $codes);
        $this->assertNotContains(TransactionValidator::AMOUNT_MISMATCH, $codes);
    }

    public function testStringGrandTotalDoesNotThrow(): void
    {
        $order = $this->makeOrder('19.9900', 'NGN');
        $verifyData = $this->makeVerifyData(['amount' => 1999]);

        $result = $this->validator->validate($verifyData, $order, true);

        $this->assertSame([], $result);
    }

    public function testUsesOrderCurrencyCodeNotBaseCurrencyCode(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getGrandTotal')->willReturn(100.00);
        $order->method('getOrderCurrencyCode')->willReturn('USD');
        $order->expects($this->never())->method('getBaseCurrencyCode');
        $order->expects($this->never())->method('getBaseGrandTotal');

        $verifyData = $this->makeVerifyData(['currency' => 'USD']);

        $result = $this->validator->validate($verifyData, $order, true);

        $this->assertSame([], $result);
    }

    public function testCurrencyCaseDifferenceStillPasses(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = $this->makeVerifyData(['currency' => 'ngn']);

        $result = $this->validator->validate($verifyData, $order, true);

        $this->assertSame([], $result);
    }

    public function testNullVerifyDataShortCircuitsWithoutThrowing(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');

        $result = $this->validator->validate(null, $order, true);

        $this->assertCount(1, $result);
        $this->assertSame(TransactionValidator::STATUS_NOT_SUCCESS, $result[0]['code']);
    }

    public function testMissingStatusPropertyShortCircuits(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = (object) ['currency' => 'NGN', 'amount' => 10000, 'domain' => 'test'];

        $result = $this->validator->validate($verifyData, $order, true);

        $this->assertCount(1, $result);
        $this->assertSame(TransactionValidator::STATUS_NOT_SUCCESS, $result[0]['code']);
    }

    public function testMissingCurrencyPropertyFails(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = (object) ['status' => 'success', 'amount' => 10000, 'domain' => 'test'];

        $result = $this->validator->validate($verifyData, $order, true);

        $codes = array_column($result, 'code');
        $this->assertContains(TransactionValidator::CURRENCY_MISMATCH, $codes);
    }

    public function testMissingAmountPropertyFails(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = (object) ['status' => 'success', 'currency' => 'NGN', 'domain' => 'test'];

        $result = $this->validator->validate($verifyData, $order, true);

        $codes = array_column($result, 'code');
        $this->assertContains(TransactionValidator::AMOUNT_MISMATCH, $codes);
    }

    public function testMissingDomainPropertyFails(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = (object) ['status' => 'success', 'currency' => 'NGN', 'amount' => 10000];

        $result = $this->validator->validate($verifyData, $order, true);

        $codes = array_column($result, 'code');
        $this->assertContains(TransactionValidator::MODE_MISMATCH, $codes);
    }

    public function testZeroGrandTotalIsRejectedNotVacuouslyPassed(): void
    {
        $order = $this->makeOrder(0, 'NGN');
        $verifyData = $this->makeVerifyData(['amount' => 0]);

        $result = $this->validator->validate($verifyData, $order, true);

        $codes = array_column($result, 'code');
        $this->assertContains(TransactionValidator::ORDER_AMOUNT_INVALID, $codes);
    }

    public function testNullGrandTotalIsRejected(): void
    {
        $order = $this->makeOrder(null, 'NGN');
        $verifyData = $this->makeVerifyData(['amount' => 0]);

        $result = $this->validator->validate($verifyData, $order, true);

        $codes = array_column($result, 'code');
        $this->assertContains(TransactionValidator::ORDER_AMOUNT_INVALID, $codes);
    }

    public function testEmptyOrderCurrencyCodeIsRejected(): void
    {
        $order = $this->makeOrder(100.00, '');
        $verifyData = $this->makeVerifyData();

        $result = $this->validator->validate($verifyData, $order, true);

        $codes = array_column($result, 'code');
        $this->assertContains(TransactionValidator::CURRENCY_MISMATCH, $codes);
    }

    /**
     * @dataProvider inFlightStatusProvider
     */
    public function testInFlightStatusReturnsAwaitingConfirmationNotStatusNotSuccess(string $status): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = $this->makeVerifyData(['status' => $status]);

        $result = $this->validator->validate($verifyData, $order, true);

        $this->assertSame([TransactionValidator::STATUS_AWAITING_CONFIRMATION], array_column($result, 'code'));
    }

    public static function inFlightStatusProvider(): array
    {
        return [
            'pending' => ['pending'],
            'ongoing' => ['ongoing'],
            'queued' => ['queued'],
        ];
    }

    public function testGenuineDeclineStillReturnsStatusNotSuccess(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = $this->makeVerifyData(['status' => 'failed']);

        $result = $this->validator->validate($verifyData, $order, true);

        $this->assertSame([TransactionValidator::STATUS_NOT_SUCCESS], array_column($result, 'code'));
    }

    public function testMissingTransactionAmountFieldIsAmountMismatchNotOrderAmountInvalid(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = $this->makeVerifyData(['amount' => null]);

        $result = $this->validator->validate($verifyData, $order, true);

        $this->assertSame([TransactionValidator::AMOUNT_MISMATCH], array_column($result, 'code'));
    }

    /**
     * A genuinely garbage (non-numeric, non-null) amount must fail the
     * is_numeric() check itself, distinct from the ?? null coalesce exercised
     * by a missing/null amount property.
     */
    public function testNonNumericStringAmountIsAmountMismatch(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = $this->makeVerifyData(['amount' => 'abc']);

        $result = $this->validator->validate($verifyData, $order, true);

        $this->assertSame([TransactionValidator::AMOUNT_MISMATCH], array_column($result, 'code'));
    }

    /**
     * Paystack's JSON responses are decoded server-side; a numeric string
     * (e.g. "10000") is valid JSON-number input in disguise and must be
     * accepted via PHP's numeric-string comparison, not rejected outright.
     */
    public function testNumericStringAmountMatchingExpectedIsAccepted(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = $this->makeVerifyData(['amount' => '10000']);

        $result = $this->validator->validate($verifyData, $order, true);

        $this->assertSame([], $result);
    }

    public function testNonScalarCurrencyDoesNotThrowAndFailsClosed(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = $this->makeVerifyData(['currency' => new \stdClass()]);

        $result = $this->validator->validate($verifyData, $order, true);

        $codes = array_column($result, 'code');
        $this->assertContains(TransactionValidator::CURRENCY_MISMATCH, $codes);
    }

    public function testArrayCurrencyDoesNotThrowAndFailsClosed(): void
    {
        $order = $this->makeOrder(100.00, 'NGN');
        $verifyData = $this->makeVerifyData(['currency' => ['NGN']]);

        $result = $this->validator->validate($verifyData, $order, true);

        $codes = array_column($result, 'code');
        $this->assertContains(TransactionValidator::CURRENCY_MISMATCH, $codes);
    }

    public function testChargeIsRealTrueOnlyWhenSuccessAndLiveDomain(): void
    {
        $verifyData = $this->makeVerifyData(['status' => 'success', 'domain' => 'live']);

        $this->assertTrue(TransactionValidator::chargeIsReal($verifyData));
    }

    public function testChargeIsRealFalseForSuccessOnTestDomain(): void
    {
        $verifyData = $this->makeVerifyData(['status' => 'success', 'domain' => 'test']);

        $this->assertFalse(TransactionValidator::chargeIsReal($verifyData));
    }

    public function testChargeIsRealFalseForNonSuccessStatus(): void
    {
        $verifyData = $this->makeVerifyData(['status' => 'failed', 'domain' => 'live']);

        $this->assertFalse(TransactionValidator::chargeIsReal($verifyData));
    }

    public function testChargeIsRealFalseForNullVerifyData(): void
    {
        $this->assertFalse(TransactionValidator::chargeIsReal(null));
    }

    public function testChargeIsRealFalseWhenDomainMissing(): void
    {
        $verifyData = (object) ['status' => 'success'];

        $this->assertFalse(TransactionValidator::chargeIsReal($verifyData));
    }
}
