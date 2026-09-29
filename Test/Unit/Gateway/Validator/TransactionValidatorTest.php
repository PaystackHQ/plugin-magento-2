<?php

namespace Pstk\Paystack\Test\Unit\Gateway\Validator;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Pstk\Paystack\Gateway\Validator\TransactionValidator;
use Pstk\Paystack\Model\Payment\Paystack;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;

class TransactionValidatorTest extends TestCase
{
    /** @var MockObject|LoggerInterface */
    private $logger;

    /** @var TransactionValidator */
    private $validator;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->validator = new TransactionValidator($this->logger);
    }

    /**
     * @return MockObject|Order
     */
    private function makeOrder(float $grandTotal, ?string $currencyCode, string $method = Paystack::CODE)
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn($method);

        $order = $this->createMock(Order::class);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getOrderCurrencyCode')->willReturn($currencyCode);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getIncrementId')->willReturn('000000001');

        return $order;
    }

    private function verifyResponse(array $overrides = []): object
    {
        $data = array_merge([
            'status' => 'success',
            'amount' => 500000,
            'currency' => 'NGN',
            'reference' => 'PSK_abc123',
            'domain' => 'test',
        ], $overrides);

        return (object) ['data' => (object) $data];
    }

    public function testExactMatchPasses(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 500000, 'currency' => 'NGN']);

        $this->assertNull($this->validator->settlementFailureReason($response, $order, true));
    }

    public function testShortByOneSubunitPassesWithinSymmetricToleranceWindow(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 499999]);

        $this->assertNull($this->validator->settlementFailureReason($response, $order, true));
    }

    public function testShortByTwoSubunitsRejects(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 499998]);

        $this->logger->expects($this->once())->method('warning');

        $this->assertSame(
            TransactionValidator::REASON_AMOUNT_MISMATCH,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    /**
     * Fallback branch (no requested_amount readable on this channel): the
     * tight wrong-exponent window never ran, so this overpayment has no
     * backstop behind it — logged at warning, not info.
     */
    public function testOverpayByOneWithNoRequestedAmountPassesAndLogsWarning(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 500001]);

        $this->assertArrayNotHasKey('requested_amount', (array) $response->data);
        $this->logger->expects($this->once())->method('warning');
        $this->logger->expects($this->never())->method('info');

        $this->assertNull($this->validator->settlementFailureReason($response, $order, true));
    }

    public function testCurrencyMismatchRejects(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['currency' => 'USD']);

        $this->logger->expects($this->once())->method('warning');

        $this->assertSame(
            TransactionValidator::REASON_CURRENCY_MISMATCH,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    public function testCaseInsensitiveCurrencyPasses(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['currency' => 'ngn']);

        $this->assertNull($this->validator->settlementFailureReason($response, $order, true));
    }

    public function testMissingDataRejectsAsMalformedWithoutLogging(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = (object) [];

        $this->logger->expects($this->never())->method($this->anything());

        $this->assertSame(
            TransactionValidator::REASON_MALFORMED,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    public function testMissingAmountRejectsAsMalformed(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = (object) ['data' => (object) ['status' => 'success', 'currency' => 'NGN', 'domain' => 'test']];

        $this->assertSame(
            TransactionValidator::REASON_MALFORMED,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    public function testMissingCurrencyRejectsAsMalformed(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = (object) ['data' => (object) ['status' => 'success', 'amount' => 500000, 'domain' => 'test']];

        $this->assertSame(
            TransactionValidator::REASON_MALFORMED,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    public function testMissingStatusRejectsAsMalformed(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = (object) ['data' => (object) ['amount' => 500000, 'currency' => 'NGN', 'domain' => 'test']];

        $this->assertSame(
            TransactionValidator::REASON_MALFORMED,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    public function testFractionalFloatAmountRejectsAsMalformed(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 500000.5]);

        $this->assertSame(
            TransactionValidator::REASON_MALFORMED,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    public function testNumericStringAmountAcceptedOnMatch(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => '500000']);

        $this->assertNull($this->validator->settlementFailureReason($response, $order, true));
    }

    public function testZeroExpectedTotalRejects(): void
    {
        $order = $this->makeOrder(0.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 500000]);

        $this->logger->expects($this->once())->method('warning');

        $this->assertSame(
            TransactionValidator::REASON_ZERO_TOTAL,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    public function testZeroPaidAmountRejects(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 0]);

        $this->assertSame(
            TransactionValidator::REASON_ZERO_TOTAL,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    public function testNullGrandTotalRejectsAsZeroTotal(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(Paystack::CODE);

        $order = $this->createMock(Order::class);
        $order->method('getGrandTotal')->willReturn(null);
        $order->method('getOrderCurrencyCode')->willReturn('NGN');
        $order->method('getPayment')->willReturn($payment);
        $order->method('getIncrementId')->willReturn('000000001');

        $response = $this->verifyResponse(['amount' => 500000]);

        $this->assertSame(
            TransactionValidator::REASON_ZERO_TOTAL,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    /**
     * Non-numeric grand total (import/headless/partial-migration order
     * shapes): expectedSubunits()'s is_numeric guard returns 0, caught by
     * the existing REASON_ZERO_TOTAL check rather than a TypeError.
     */
    public function testNonNumericGrandTotalRejectsAsZeroTotal(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(Paystack::CODE);

        $order = $this->createMock(Order::class);
        $order->method('getGrandTotal')->willReturn('not-a-number');
        $order->method('getOrderCurrencyCode')->willReturn('NGN');
        $order->method('getPayment')->willReturn($payment);
        $order->method('getIncrementId')->willReturn('000000001');

        $response = $this->verifyResponse(['amount' => 500000]);

        $this->assertSame(
            TransactionValidator::REASON_ZERO_TOTAL,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    public function testMissingOrderCurrencyRejectsAsCurrencyMismatch(): void
    {
        $order = $this->makeOrder(5000.00, null);
        $response = $this->verifyResponse();

        $this->logger->expects($this->once())->method('warning');

        $this->assertSame(
            TransactionValidator::REASON_CURRENCY_MISMATCH,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    public function testWrongPaymentMethodRejects(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN', 'checkmo');
        $response = $this->verifyResponse();

        $this->logger->expects($this->once())->method('warning');

        $this->assertSame(
            TransactionValidator::REASON_WRONG_METHOD,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    public function testNullPaymentRejectsAsWrongMethod(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getGrandTotal')->willReturn(5000.00);
        $order->method('getOrderCurrencyCode')->willReturn('NGN');
        $order->method('getPayment')->willReturn(null);
        $order->method('getIncrementId')->willReturn('000000001');

        $response = $this->verifyResponse();

        $this->assertSame(
            TransactionValidator::REASON_WRONG_METHOD,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    /**
     * @dataProvider inFlightStatusProvider
     */
    public function testInFlightStatusesReject(string $status): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['status' => $status]);

        $this->logger->expects($this->never())->method($this->anything());

        $this->assertSame(
            TransactionValidator::REASON_IN_FLIGHT,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    public static function inFlightStatusProvider(): array
    {
        return [
            'pending' => ['pending'],
            'ongoing' => ['ongoing'],
            'queued' => ['queued'],
        ];
    }

    /**
     * @dataProvider notSuccessfulStatusProvider
     */
    public function testNotSuccessfulStatusesReject(string $status): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['status' => $status]);

        $this->logger->expects($this->never())->method($this->anything());

        $this->assertSame(
            TransactionValidator::REASON_NOT_SUCCESSFUL,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    public static function notSuccessfulStatusProvider(): array
    {
        return [
            'abandoned' => ['abandoned'],
            'failed' => ['failed'],
        ];
    }

    // --- domain / mode-mismatch --------------------------------------------

    public function testDomainMatchingTestModePasses(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['domain' => 'test']);

        $this->assertNull($this->validator->settlementFailureReason($response, $order, true));
    }

    public function testDomainMatchingLiveModePasses(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['domain' => 'live']);

        $this->assertNull($this->validator->settlementFailureReason($response, $order, false));
    }

    public function testDomainLiveWhenTestModeExpectedRejectsAsModeMismatch(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['domain' => 'live']);

        $this->assertSame(
            TransactionValidator::REASON_MODE_MISMATCH,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    public function testDomainTestWhenLiveModeExpectedRejectsAsModeMismatch(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['domain' => 'test']);

        $this->assertSame(
            TransactionValidator::REASON_MODE_MISMATCH,
            $this->validator->settlementFailureReason($response, $order, false)
        );
    }

    /**
     * A missing/renamed `domain` must skip the mode check entirely (fail
     * open) rather than reject the whole payment — `domain` only feeds the
     * secondary mode-mismatch sanity signal, not the core settlement
     * guarantee, unlike status/currency/paid amount. The skip is logged as a
     * warning so it stays visible.
     */
    public function testMissingDomainSkipsModeCheckAndLogsWarning(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['domain' => null]);

        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->validator->settlementFailureReason($response, $order, true));
    }

    public function testNonStringDomainSkipsModeCheckAndLogsWarning(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['domain' => ['test']]);

        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->validator->settlementFailureReason($response, $order, true));
    }

    public function testEmptyStringDomainSkipsModeCheckAndLogsWarning(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['domain' => '']);

        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->validator->settlementFailureReason($response, $order, true));
    }

    // --- requested_amount two-field overpayment check -----------------------

    public function testRequestedAmountExactMatchWithFeeOnPaidPasses(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        // expected 500000; requested matches exactly; paid includes an
        // uncapped fee on top — accepted, any excess is fee.
        $response = $this->verifyResponse(['amount' => 515000, 'requested_amount' => 500000]);

        $this->logger->expects($this->once())->method('info');

        $this->assertNull($this->validator->settlementFailureReason($response, $order, true));
    }

    public function testRequestedAmountWithinToleranceWindowPasses(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 500001, 'requested_amount' => 500001]);

        $this->assertNull($this->validator->settlementFailureReason($response, $order, true));
    }

    public function testRequestedAmountBeyondToleranceWindowRejects(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        // requested_amount is 100x the expected figure — the wrong-exponent
        // client-bug scenario this design exists to catch, regardless of any
        // fee added on top of it.
        $response = $this->verifyResponse(['amount' => 50000000, 'requested_amount' => 50000000]);

        $this->logger->expects($this->once())->method('warning');

        $this->assertSame(
            TransactionValidator::REASON_AMOUNT_MISMATCH,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    public function testRequestedAmountBelowExpectedRejects(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 400000, 'requested_amount' => 400000]);

        $this->assertSame(
            TransactionValidator::REASON_AMOUNT_MISMATCH,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    /**
     * The tolerance window is symmetric: a requested_amount landing 1 subunit
     * BELOW expected (plausible from independent client/server rounding of
     * the same order total) is still accepted, same as 1 above.
     */
    public function testRequestedAmountOneBelowExpectedWithinToleranceWindowPasses(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 499999, 'requested_amount' => 499999]);

        $this->assertNull($this->validator->settlementFailureReason($response, $order, true));
    }

    public function testRequestedAmountTwoBelowExpectedBeyondToleranceWindowRejects(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 499998, 'requested_amount' => 499998]);

        $this->assertSame(
            TransactionValidator::REASON_AMOUNT_MISMATCH,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    public function testPaidLessThanRequestedAmountRejects(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        // requested_amount matches expected, but paid fell short of even that
        // — the merchant was not paid what was requested.
        $response = $this->verifyResponse(['amount' => 499000, 'requested_amount' => 500000]);

        $this->assertSame(
            TransactionValidator::REASON_AMOUNT_MISMATCH,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    /**
     * requested_amount present but negative: is-integral (is_int) still
     * accepts it, so it is NOT treated as unreadable/absent — it must go
     * through the tolerance-window comparison like any other integral value
     * and lose there (a negative figure is always far outside
     * `[expected, expected + TOLERANCE]`), not slip past as "absent -> fall
     * back to the permissive paid >= expected check".
     */
    public function testNegativeRequestedAmountRejectsViaToleranceWindowNotFallback(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 500000, 'requested_amount' => -500000]);

        $this->logger->expects($this->once())->method('warning');

        $this->assertSame(
            TransactionValidator::REASON_AMOUNT_MISMATCH,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    /**
     * requested_amount present but a non-integral FLOAT (e.g. Paystack, or a
     * proxy in front of it, returning `1999.5`): isIntegral() rejects it via
     * the is_float branch specifically (distinct code path from the
     * non-numeric-string case already covered by
     * testRequestedAmountUnreadableFallsBackToPlainPaidCheck) — must still be
     * treated as absent/unreadable and fall back to the plain paid >=
     * expected check, not thrown, not treated as a match.
     */
    public function testNonIntegralFloatRequestedAmountFallsBackToPlainPaidCheck(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 500000, 'requested_amount' => 1999.5]);

        $this->assertNull($this->validator->settlementFailureReason($response, $order, true));
    }

    /**
     * requested_amount AND paid are both wildly below expected, in the same
     * direction (e.g. a client that sent a naira figure where kobo was
     * expected, 100x under) — distinct from
     * testRequestedAmountBelowExpectedRejects (a modest, single-digit-percent
     * shortfall) and from testPaidLessThanRequestedAmountRejects (where
     * requested_amount matches expected exactly and only paid falls short).
     * Confirms the tolerance-window check (the first of the two
     * requested_amount guards) is what catches this, not the second
     * (paid < requested) guard, since here neither figure is anywhere near
     * `expected`.
     */
    public function testRequestedAmountAndPaidBothWildlyBelowExpectedInSameDirectionRejects(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 5000, 'requested_amount' => 5000]);

        $this->logger->expects($this->once())->method('warning');

        $this->assertSame(
            TransactionValidator::REASON_AMOUNT_MISMATCH,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    public function testRequestedAmountAbsentFallsBackToPlainPaidCheck(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        // No requested_amount on this channel; plain paid >= expected fallback
        // still passes.
        $response = $this->verifyResponse(['amount' => 500000]);

        $this->assertArrayNotHasKey('requested_amount', (array) $response->data);
        $this->assertNull($this->validator->settlementFailureReason($response, $order, true));
    }

    /**
     * The fallback window (no requested_amount available) is symmetric too: a
     * paid amount 1 subunit below expected is still accepted.
     */
    public function testFallbackPaidOneBelowExpectedWithinToleranceWindowPasses(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 499999]);

        $this->assertArrayNotHasKey('requested_amount', (array) $response->data);
        $this->assertNull($this->validator->settlementFailureReason($response, $order, true));
    }

    public function testFallbackPaidTwoBelowExpectedBeyondToleranceWindowRejects(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 499998]);

        $this->assertArrayNotHasKey('requested_amount', (array) $response->data);
        $this->assertSame(
            TransactionValidator::REASON_AMOUNT_MISMATCH,
            $this->validator->settlementFailureReason($response, $order, true)
        );
    }

    public function testRequestedAmountUnreadableFallsBackToPlainPaidCheck(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        // requested_amount present but not integral -> treated as absent,
        // falls back to the plain paid >= expected check.
        $response = $this->verifyResponse(['amount' => 500000, 'requested_amount' => 'garbage']);

        $this->assertNull($this->validator->settlementFailureReason($response, $order, true));
    }

    /**
     * Pins the checked-in-this-method sequence: when more than one thing is
     * wrong with a response/order pair, the FIRST failing check in the
     * documented order must win, not whichever the implementation happens to
     * reach last. A refactor that reorders the checks would silently change
     * which REASON_* callers see (and, via isPermanentForWebhook()/
     * isTerminalForCustomer(), whether Paystack gets a retry and whether the
     * customer is invited to pay again) without any single-condition test here
     * catching it.
     *
     * @dataProvider orderingPrecedenceProvider
     */
    public function testSettlementFailureReasonPrecedenceOrder(
        float $grandTotal,
        ?string $currencyCode,
        string $method,
        array $responseOverrides,
        bool $testMode,
        string $expectedReason
    ): void {
        $order = $this->makeOrder($grandTotal, $currencyCode, $method);
        $response = $this->verifyResponse($responseOverrides);

        $this->assertSame(
            $expectedReason,
            $this->validator->settlementFailureReason($response, $order, $testMode)
        );
    }

    public static function orderingPrecedenceProvider(): array
    {
        return [
            // Unreadable amount (MALFORMED) must win over a bad status (NOT_SUCCESSFUL)
            // — MALFORMED is checked before the status branch is even reached.
            'malformed amount beats not-successful status' => [
                5000.00,
                'NGN',
                Paystack::CODE,
                ['status' => 'failed', 'amount' => null],
                true,
                TransactionValidator::REASON_MALFORMED,
            ],
            // An in-flight status is checked before the amount is compared, so a
            // short amount on an in-flight transaction still reports IN_FLIGHT.
            'in-flight status beats amount mismatch' => [
                5000.00,
                'NGN',
                Paystack::CODE,
                ['status' => 'pending', 'amount' => 499999],
                true,
                TransactionValidator::REASON_IN_FLIGHT,
            ],
            // Wrong payment method is checked before the zero-total guard.
            'wrong method beats zero total' => [
                0.00,
                'NGN',
                'checkmo',
                [],
                true,
                TransactionValidator::REASON_WRONG_METHOD,
            ],
            // Zero/negative expected total is checked before currency is compared.
            'zero total beats currency mismatch' => [
                0.00,
                'NGN',
                Paystack::CODE,
                ['currency' => 'USD'],
                true,
                TransactionValidator::REASON_ZERO_TOTAL,
            ],
            // Currency mismatch is checked before the amount comparison.
            'currency mismatch beats amount mismatch' => [
                5000.00,
                'NGN',
                Paystack::CODE,
                ['currency' => 'USD', 'amount' => 499999],
                true,
                TransactionValidator::REASON_CURRENCY_MISMATCH,
            ],
            // Non-success status is checked before a currency mismatch is even
            // reached.
            'non-success beats currency mismatch' => [
                5000.00,
                'NGN',
                Paystack::CODE,
                ['status' => 'failed', 'currency' => 'USD'],
                true,
                TransactionValidator::REASON_NOT_SUCCESSFUL,
            ],
            // The mode-mismatch check is positioned after every amount
            // rejection path (the last guard before the overpayment info-log)
            // specifically so an amount mismatch keeps winning here — this is
            // the exact placement the panel's finding was about: putting the
            // mode check any earlier would flip this case's winning reason
            // from AMOUNT_MISMATCH (permanent, 200) to MODE_MISMATCH
            // (transient, 503), silently changing webhook retry behavior.
            'mode mismatch does not beat amount mismatch (fallback branch)' => [
                5000.00,
                'NGN',
                Paystack::CODE,
                ['amount' => 499998, 'domain' => 'live'],
                true,
                TransactionValidator::REASON_AMOUNT_MISMATCH,
            ],
            // Same precedence, but exercising the requested_amount branch
            // (not just the fallback): a requested_amount far outside the
            // tight tolerance window must still win over a would-be mode
            // mismatch.
            'mode mismatch does not beat amount mismatch (requested_amount beyond window)' => [
                5000.00,
                'NGN',
                Paystack::CODE,
                ['amount' => 50000000, 'requested_amount' => 50000000, 'domain' => 'live'],
                true,
                TransactionValidator::REASON_AMOUNT_MISMATCH,
            ],
            // Same precedence, but for the requested_amount branch's second
            // condition (paid < requested) rather than the tolerance window.
            'mode mismatch does not beat amount mismatch (paid below requested_amount)' => [
                5000.00,
                'NGN',
                Paystack::CODE,
                ['amount' => 499000, 'requested_amount' => 500000, 'domain' => 'live'],
                true,
                TransactionValidator::REASON_AMOUNT_MISMATCH,
            ],
        ];
    }

    /**
     * Pins the getGrandTotal() + getOrderCurrencyCode() pairing both init paths
     * (Setup.php, the inline JS) use for the expected-amount comparison. A future
     * swap to getBaseGrandTotal() (the base-currency total) must fail this test:
     * a store with a USD base currency and an NGN order currency has a base grand
     * total that does not correspond to the NGN amount Paystack actually charged.
     */
    public function testBaseCurrencyDivergenceFromOrderCurrencyIsPinned(): void
    {
        // Order-currency total: 25000.00 NGN -> 2,500,000 kobo, matches what
        // Paystack (an NGN-denominated charge) reports as paid.
        $order = $this->makeOrder(25000.00, 'NGN');
        // Stubbed so a future swap to getBaseGrandTotal() fails via the real
        // amount-mismatch mechanism below, not via ZERO_TOTAL (an unstubbed
        // mock method returns null, and a null base total would hit the
        // zero-total guard first — passing this test for the wrong reason).
        // 15000.00 mirrors the base-USD-derived figure the assertion below
        // exercises (15000.00 * 100 = 1,500,000 subunits).
        $order->method('getBaseGrandTotal')->willReturn(15000.00);
        $response = $this->verifyResponse(['amount' => 2500000, 'currency' => 'NGN']);

        $this->assertNull($this->validator->settlementFailureReason($response, $order, true));

        // Same order, but the verify amount now reflects what a base-USD-derived
        // subunit figure (i.e. getBaseGrandTotal() * 100) would have been -- this
        // must NOT match, because the validator reads getGrandTotal() (the
        // order-currency total), not the base total.
        $baseDerivedResponse = $this->verifyResponse(['amount' => 1500000, 'currency' => 'NGN']);

        $this->assertSame(
            TransactionValidator::REASON_AMOUNT_MISMATCH,
            $this->validator->settlementFailureReason($baseDerivedResponse, $order, true)
        );
    }

    /**
     * @dataProvider expectedSubunitsProvider
     * @param float|string $grandTotal
     */
    public function testExpectedSubunits($grandTotal, int $expected): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getGrandTotal')->willReturn($grandTotal);

        $this->assertSame($expected, $this->validator->expectedSubunits($order));
    }

    public static function expectedSubunitsProvider(): array
    {
        return [
            '19.99 -> 1999' => [19.99, 1999],
            '8.21 -> 821' => [8.21, 821],
            '5000.00 -> 500000' => [5000.00, 500000],
            'decimal string 20.1500 -> 2015' => ['20.1500', 2015],
        ];
    }

    /**
     * The is_numeric guard: a non-numeric grand total must return 0, never
     * throw a TypeError.
     */
    public function testExpectedSubunitsReturnsZeroForNonNumericGrandTotal(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getGrandTotal')->willReturn('not-a-number');

        $this->assertSame(0, $this->validator->expectedSubunits($order));
    }

    public function testExpectedSubunitsReturnsZeroForNullGrandTotal(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getGrandTotal')->willReturn(null);

        $this->assertSame(0, $this->validator->expectedSubunits($order));
    }

    /**
     * @dataProvider retryableReasonProvider
     */
    public function testIsTerminalForCustomerFalseForRetryableReasons(string $reason): void
    {
        $this->assertFalse($this->validator->isTerminalForCustomer($reason));
    }

    public static function retryableReasonProvider(): array
    {
        return [
            'not_successful' => [TransactionValidator::REASON_NOT_SUCCESSFUL],
            'bad_reference' => [TransactionValidator::REASON_BAD_REFERENCE],
        ];
    }

    /**
     * @dataProvider terminalReasonProvider
     */
    public function testIsTerminalForCustomerTrueForEverythingElse(string $reason): void
    {
        $this->assertTrue($this->validator->isTerminalForCustomer($reason));
    }

    public static function terminalReasonProvider(): array
    {
        return [
            'in_flight' => [TransactionValidator::REASON_IN_FLIGHT],
            'amount_mismatch' => [TransactionValidator::REASON_AMOUNT_MISMATCH],
            'currency_mismatch' => [TransactionValidator::REASON_CURRENCY_MISMATCH],
            'zero_total' => [TransactionValidator::REASON_ZERO_TOTAL],
            'wrong_method' => [TransactionValidator::REASON_WRONG_METHOD],
            'malformed' => [TransactionValidator::REASON_MALFORMED],
            'mode_mismatch' => [TransactionValidator::REASON_MODE_MISMATCH],
            'reference_bound_elsewhere' => [TransactionValidator::REASON_REFERENCE_BOUND_ELSEWHERE],
            'order_not_payable' => [TransactionValidator::REASON_ORDER_NOT_PAYABLE],
            'order_closed' => [TransactionValidator::REASON_ORDER_CLOSED],
            'registration_failed' => [TransactionValidator::REASON_REGISTRATION_FAILED],
            // Regression test for the whole finding: a reason this class does
            // not (yet) know about must fail closed, not silently invite a
            // second payment the way the three old, independent policy maps did.
            'unknown reason fails closed' => ['some_future_reason'],
        ];
    }

    /**
     * @dataProvider permanentWebhookReasonProvider
     */
    public function testIsPermanentForWebhookTrueForAllowListedReasons(string $reason): void
    {
        $this->assertTrue($this->validator->isPermanentForWebhook($reason));
    }

    public static function permanentWebhookReasonProvider(): array
    {
        return [
            'not_successful' => [TransactionValidator::REASON_NOT_SUCCESSFUL],
            'amount_mismatch' => [TransactionValidator::REASON_AMOUNT_MISMATCH],
            'currency_mismatch' => [TransactionValidator::REASON_CURRENCY_MISMATCH],
            'zero_total' => [TransactionValidator::REASON_ZERO_TOTAL],
            // Being bound to a different order is not time-dependent —
            // retrying won't change the answer, unlike REASON_ORDER_NOT_PAYABLE
            // below.
            'reference_bound_elsewhere' => [TransactionValidator::REASON_REFERENCE_BOUND_ELSEWHERE],
            // The order can never take this money and the rejection is already
            // recorded on it — ack instead of retrying for ~72h.
            'order_closed' => [TransactionValidator::REASON_ORDER_CLOSED],
        ];
    }

    /**
     * @dataProvider transientWebhookReasonProvider
     */
    public function testIsPermanentForWebhookFalseForEverythingElse(string $reason): void
    {
        $this->assertFalse($this->validator->isPermanentForWebhook($reason));
    }

    public static function transientWebhookReasonProvider(): array
    {
        return [
            'in_flight' => [TransactionValidator::REASON_IN_FLIGHT],
            'malformed' => [TransactionValidator::REASON_MALFORMED],
            'wrong_method' => [TransactionValidator::REASON_WRONG_METHOD],
            'bad_reference' => [TransactionValidator::REASON_BAD_REFERENCE],
            // REASON_MODE_MISMATCH is deliberately NOT permanent: a swapped/
            // misconfigured secret-key pair is not "the money definitively
            // didn't settle" the same way a real amount/currency mismatch is.
            'mode_mismatch' => [TransactionValidator::REASON_MODE_MISMATCH],
            // A bank-transfer/USSD charge genuinely `pending` at callback time
            // can settle minutes later via the webhook — if the customer used
            // `/paystack/payment/recreate` meanwhile, the order is now
            // `canceled`, and a late but genuine `charge.success` must keep
            // retrying, not be permanently dropped (money captured with no
            // order and no refund path). See Webhook.php's own
            // NEVER_RECENCY_BOUNDED for the never-falls-back-to-permanent half.
            'order_not_payable' => [TransactionValidator::REASON_ORDER_NOT_PAYABLE],
            // A transient DB/invoice issue during registration should keep
            // retrying, since the same event redelivered later may succeed.
            'registration_failed' => [TransactionValidator::REASON_REGISTRATION_FAILED],
            // Regression test for the whole finding: an unrecognised reason must
            // default to transient (retry), not permanent — a permanent 200 on a
            // reason we cannot classify would silently strand a real payment
            // with no retry and no merchant trace.
            'unknown reason fails closed (transient)' => ['some_future_reason'],
        ];
    }

    /**
     * @dataProvider customerMessageProvider
     */
    public function testCustomerMessage(string $reason, string $expectedMessage): void
    {
        $this->assertSame($expectedMessage, $this->validator->customerMessage($reason));
    }

    public static function customerMessageProvider(): array
    {
        $doNotPayAgain = "We could not confirm your payment. Please do not pay "
            . "again — contact support with your order number.";

        return [
            'not_successful' => [
                TransactionValidator::REASON_NOT_SUCCESSFUL,
                "Your payment was not completed. Please try again.",
            ],
            'bad_reference' => [
                TransactionValidator::REASON_BAD_REFERENCE,
                "Payment could not be verified. Please try again.",
            ],
            'in_flight' => [
                TransactionValidator::REASON_IN_FLIGHT,
                "Your payment is still being confirmed. Please do not pay "
                    . "again — we will email you once it is confirmed.",
            ],
            'amount_mismatch' => [TransactionValidator::REASON_AMOUNT_MISMATCH, $doNotPayAgain],
            'currency_mismatch' => [TransactionValidator::REASON_CURRENCY_MISMATCH, $doNotPayAgain],
            'zero_total' => [TransactionValidator::REASON_ZERO_TOTAL, $doNotPayAgain],
            'wrong_method' => [TransactionValidator::REASON_WRONG_METHOD, $doNotPayAgain],
            'malformed' => [TransactionValidator::REASON_MALFORMED, $doNotPayAgain],
            'mode_mismatch' => [TransactionValidator::REASON_MODE_MISMATCH, $doNotPayAgain],
            'reference_bound_elsewhere' => [TransactionValidator::REASON_REFERENCE_BOUND_ELSEWHERE, $doNotPayAgain],
            'order_not_payable' => [TransactionValidator::REASON_ORDER_NOT_PAYABLE, $doNotPayAgain],
            'order_closed' => [TransactionValidator::REASON_ORDER_CLOSED, $doNotPayAgain],
            'registration_failed' => [TransactionValidator::REASON_REGISTRATION_FAILED, $doNotPayAgain],
            // Regression test for the whole finding: an unrecognised reason must
            // fall into the safest copy, exactly like isTerminalForCustomer()
            // fails closed on terminality for the same input.
            'unknown reason fails closed' => ['some_future_reason', $doNotPayAgain],
        ];
    }

    public function testOrderClosedMessageMatchesOrderNotPayable(): void
    {
        $this->assertSame(
            $this->validator->customerMessage(TransactionValidator::REASON_ORDER_NOT_PAYABLE),
            $this->validator->customerMessage(TransactionValidator::REASON_ORDER_CLOSED)
        );
    }

    public function testIsOverpaymentTrueWhenPaidExceedsExpected(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 500001]);

        $this->assertTrue($this->validator->isOverpayment($response, $order));
    }

    public function testIsOverpaymentFalseOnExactMatch(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 500000]);

        $this->assertFalse($this->validator->isOverpayment($response, $order));
    }

    public function testIsOverpaymentFalseWhenShort(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 499999]);

        $this->assertFalse($this->validator->isOverpayment($response, $order));
    }

    /**
     * When requested_amount is readable, isOverpayment() compares against it
     * (not expectedSubunits()) for consistency with the two-field check in
     * settlementFailureReason().
     */
    public function testIsOverpaymentComparesAgainstRequestedAmountWhenPresent(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 515000, 'requested_amount' => 500000]);

        $this->assertTrue($this->validator->isOverpayment($response, $order));
    }

    public function testIsOverpaymentFalseWhenPaidMatchesRequestedAmountExactly(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 500000, 'requested_amount' => 500000]);

        $this->assertFalse($this->validator->isOverpayment($response, $order));
    }

    /**
     * A read, not a gate: an unreadable envelope must not throw, it must just
     * answer "no".
     */
    public function testIsOverpaymentFalseOnUnreadableResponse(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = (object) [];

        $this->assertFalse($this->validator->isOverpayment($response, $order));
    }

    public function testPaidSubunitsReturnsIntOnValidResponse(): void
    {
        $response = $this->verifyResponse(['amount' => 500000]);

        $this->assertSame(500000, $this->validator->paidSubunits($response));
    }

    public function testPaidSubunitsNullOnMissingData(): void
    {
        $response = (object) [];

        $this->assertNull($this->validator->paidSubunits($response));
    }

    public function testPaidSubunitsNullOnFractionalAmount(): void
    {
        $response = $this->verifyResponse(['amount' => 500000.5]);

        $this->assertNull($this->validator->paidSubunits($response));
    }

    /**
     * Pins the contract that 0 is a valid, readable amount, distinct from
     * "unreadable" (null) — a caller checking falsiness instead of `=== null`
     * would conflate the two.
     */
    public function testPaidSubunitsReturnsZeroForZeroAmount(): void
    {
        $response = $this->verifyResponse(['amount' => 0]);

        $this->assertSame(0, $this->validator->paidSubunits($response));
    }

    public function testIsPaystackOrderTrueForPaystackMethod(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN', Paystack::CODE);

        $this->assertTrue($this->validator->isPaystackOrder($order));
    }

    public function testIsPaystackOrderFalseForOtherMethod(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN', 'checkmo');

        $this->assertFalse($this->validator->isPaystackOrder($order));
    }

    public function testIsPaystackOrderFalseForNullPayment(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn(null);

        $this->assertFalse($this->validator->isPaystackOrder($order));
    }

    /**
     * Drift-catcher: every REASON_* constant on this class must be named in
     * one of the explicit/defaulted allow-lists below for each classification
     * map. A new REASON_* nobody wires up here would otherwise silently
     * inherit isTerminalForCustomer()'s and isPermanentForWebhook()'s
     * fail-closed defaults and customerMessage()'s default copy without
     * anyone deciding that was the intended classification — this test forces
     * that decision to be explicit and named.
     */
    public function testEveryReasonConstantIsAccountedForInClassificationMaps(): void
    {
        $reflection = new \ReflectionClass(TransactionValidator::class);
        $reasonConstants = array_filter(
            $reflection->getConstants(),
            static fn (string $name): bool => strpos($name, 'REASON_') === 0,
            ARRAY_FILTER_USE_KEY
        );

        // isTerminalForCustomer(): explicit -> RETRYABLE_FOR_CUSTOMER (false);
        // defaulted -> everything else, fails closed to true.
        $terminalAccountedFor = [
            TransactionValidator::REASON_NOT_SUCCESSFUL,
            TransactionValidator::REASON_BAD_REFERENCE,
            TransactionValidator::REASON_MALFORMED,
            TransactionValidator::REASON_IN_FLIGHT,
            TransactionValidator::REASON_WRONG_METHOD,
            TransactionValidator::REASON_ZERO_TOTAL,
            TransactionValidator::REASON_CURRENCY_MISMATCH,
            TransactionValidator::REASON_AMOUNT_MISMATCH,
            TransactionValidator::REASON_MODE_MISMATCH,
            TransactionValidator::REASON_REFERENCE_BOUND_ELSEWHERE,
            TransactionValidator::REASON_ORDER_NOT_PAYABLE,
            TransactionValidator::REASON_ORDER_CLOSED,
            TransactionValidator::REASON_REGISTRATION_FAILED,
        ];

        // isPermanentForWebhook(): explicit -> PERMANENT_FOR_WEBHOOK (true);
        // defaulted -> everything else, fails closed to false (transient/retry).
        $permanentAccountedFor = [
            TransactionValidator::REASON_NOT_SUCCESSFUL,
            TransactionValidator::REASON_AMOUNT_MISMATCH,
            TransactionValidator::REASON_CURRENCY_MISMATCH,
            TransactionValidator::REASON_ZERO_TOTAL,
            TransactionValidator::REASON_MALFORMED,
            TransactionValidator::REASON_IN_FLIGHT,
            TransactionValidator::REASON_WRONG_METHOD,
            TransactionValidator::REASON_BAD_REFERENCE,
            TransactionValidator::REASON_MODE_MISMATCH,
            TransactionValidator::REASON_REFERENCE_BOUND_ELSEWHERE,
            TransactionValidator::REASON_ORDER_NOT_PAYABLE,
            TransactionValidator::REASON_ORDER_CLOSED,
            TransactionValidator::REASON_REGISTRATION_FAILED,
        ];

        // customerMessage(): explicit -> its own switch case; defaulted -> the
        // "do not pay again, contact support" fallback branch.
        $messageAccountedFor = [
            TransactionValidator::REASON_NOT_SUCCESSFUL,
            TransactionValidator::REASON_BAD_REFERENCE,
            TransactionValidator::REASON_IN_FLIGHT,
            TransactionValidator::REASON_AMOUNT_MISMATCH,
            TransactionValidator::REASON_CURRENCY_MISMATCH,
            TransactionValidator::REASON_ZERO_TOTAL,
            TransactionValidator::REASON_WRONG_METHOD,
            TransactionValidator::REASON_MALFORMED,
            TransactionValidator::REASON_MODE_MISMATCH,
            TransactionValidator::REASON_REFERENCE_BOUND_ELSEWHERE,
            TransactionValidator::REASON_ORDER_NOT_PAYABLE,
            TransactionValidator::REASON_ORDER_CLOSED,
            TransactionValidator::REASON_REGISTRATION_FAILED,
        ];

        foreach ($reasonConstants as $name => $value) {
            $this->assertContains(
                $value,
                $terminalAccountedFor,
                "$name ('$value') is not accounted for in isTerminalForCustomer()'s "
                    . "explicit-or-defaulted allow-list — decide and register it."
            );
            $this->assertContains(
                $value,
                $permanentAccountedFor,
                "$name ('$value') is not accounted for in isPermanentForWebhook()'s "
                    . "explicit-or-defaulted allow-list — decide and register it."
            );
            $this->assertContains(
                $value,
                $messageAccountedFor,
                "$name ('$value') is not accounted for in customerMessage()'s "
                    . "explicit-or-defaulted allow-list — decide and register it."
            );
        }
    }

    /**
     * @dataProvider isPayableProvider
     */
    public function testIsPayable(string $state, float $baseTotalDue, bool $expected): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getState')->willReturn($state);
        $order->method('getBaseTotalDue')->willReturn($baseTotalDue);

        $this->assertSame($expected, $this->validator->isPayable($order));
    }

    public static function isPayableProvider(): array
    {
        return [
            'new, due' => [Order::STATE_NEW, 5000.00, true],
            'pending_payment, due' => [Order::STATE_PENDING_PAYMENT, 5000.00, true],
            'new, nothing due' => [Order::STATE_NEW, 0.0, false],
            'new, negative due' => [Order::STATE_NEW, -1.0, false],
            'processing' => [Order::STATE_PROCESSING, 5000.00, false],
            'canceled' => [Order::STATE_CANCELED, 5000.00, false],
            'closed' => [Order::STATE_CLOSED, 5000.00, false],
            'complete' => [Order::STATE_COMPLETE, 5000.00, false],
            'holded' => [Order::STATE_HOLDED, 5000.00, false],
        ];
    }

    /**
     * @dataProvider isClosedForPaymentProvider
     */
    public function testIsClosedForPayment(string $state, float $baseTotalDue, bool $expected): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getState')->willReturn($state);
        $order->method('getBaseTotalDue')->willReturn($baseTotalDue);

        $this->assertSame($expected, $this->validator->isClosedForPayment($order));
    }

    public static function isClosedForPaymentProvider(): array
    {
        return [
            'canceled, due' => [Order::STATE_CANCELED, 5000.00, true],
            'closed, due' => [Order::STATE_CLOSED, 5000.00, true],
            'complete, due' => [Order::STATE_COMPLETE, 5000.00, true],
            'new, nothing due' => [Order::STATE_NEW, 0.0, true],
            'new, due' => [Order::STATE_NEW, 5000.00, false],
            'holded, due' => [Order::STATE_HOLDED, 5000.00, false],
            'payment_review, due' => [Order::STATE_PAYMENT_REVIEW, 5000.00, false],
        ];
    }

    /**
     * @dataProvider chargeIsRealProvider
     */
    public function testChargeIsReal($data, bool $expected): void
    {
        $this->assertSame($expected, $this->validator->chargeIsReal($data));
    }

    public static function chargeIsRealProvider(): array
    {
        return [
            'success, live' => [(object) ['status' => 'success', 'domain' => 'live'], true],
            'success, domain missing' => [(object) ['status' => 'success'], true],
            'success, domain null' => [(object) ['status' => 'success', 'domain' => null], true],
            'success, test domain' => [(object) ['status' => 'success', 'domain' => 'test'], false],
            'failed, live' => [(object) ['status' => 'failed', 'domain' => 'live'], false],
            'abandoned' => [(object) ['status' => 'abandoned', 'domain' => 'live'], false],
            'status missing' => [(object) ['domain' => 'live'], false],
            'not an object' => ['success', false],
            'null' => [null, false],
        ];
    }
}
