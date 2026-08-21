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
        ], $overrides);

        return (object) ['data' => (object) $data];
    }

    public function testExactMatchPasses(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 500000, 'currency' => 'NGN']);

        $this->assertNull($this->validator->settlementFailureReason($response, $order));
    }

    public function testShortByOneSubunitRejects(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 499999]);

        $this->logger->expects($this->once())->method('warning');

        $this->assertSame(
            TransactionValidator::REASON_AMOUNT_MISMATCH,
            $this->validator->settlementFailureReason($response, $order)
        );
    }

    public function testOverpayByOnePassesAndLogsInfo(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 500001]);

        $this->logger->expects($this->once())->method('info');
        $this->logger->expects($this->never())->method('warning');

        $this->assertNull($this->validator->settlementFailureReason($response, $order));
    }

    public function testCurrencyMismatchRejects(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['currency' => 'USD']);

        $this->logger->expects($this->once())->method('warning');

        $this->assertSame(
            TransactionValidator::REASON_CURRENCY_MISMATCH,
            $this->validator->settlementFailureReason($response, $order)
        );
    }

    public function testCaseInsensitiveCurrencyPasses(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['currency' => 'ngn']);

        $this->assertNull($this->validator->settlementFailureReason($response, $order));
    }

    public function testMissingDataRejectsAsMalformedWithoutLogging(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = (object) [];

        $this->logger->expects($this->never())->method($this->anything());

        $this->assertSame(
            TransactionValidator::REASON_MALFORMED,
            $this->validator->settlementFailureReason($response, $order)
        );
    }

    public function testMissingAmountRejectsAsMalformed(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = (object) ['data' => (object) ['status' => 'success', 'currency' => 'NGN']];

        $this->assertSame(
            TransactionValidator::REASON_MALFORMED,
            $this->validator->settlementFailureReason($response, $order)
        );
    }

    public function testMissingCurrencyRejectsAsMalformed(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = (object) ['data' => (object) ['status' => 'success', 'amount' => 500000]];

        $this->assertSame(
            TransactionValidator::REASON_MALFORMED,
            $this->validator->settlementFailureReason($response, $order)
        );
    }

    public function testMissingStatusRejectsAsMalformed(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = (object) ['data' => (object) ['amount' => 500000, 'currency' => 'NGN']];

        $this->assertSame(
            TransactionValidator::REASON_MALFORMED,
            $this->validator->settlementFailureReason($response, $order)
        );
    }

    public function testFractionalFloatAmountRejectsAsMalformed(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 500000.5]);

        $this->assertSame(
            TransactionValidator::REASON_MALFORMED,
            $this->validator->settlementFailureReason($response, $order)
        );
    }

    public function testNumericStringAmountAcceptedOnMatch(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => '500000']);

        $this->assertNull($this->validator->settlementFailureReason($response, $order));
    }

    public function testZeroExpectedTotalRejects(): void
    {
        $order = $this->makeOrder(0.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 500000]);

        $this->logger->expects($this->once())->method('warning');

        $this->assertSame(
            TransactionValidator::REASON_ZERO_TOTAL,
            $this->validator->settlementFailureReason($response, $order)
        );
    }

    public function testZeroPaidAmountRejects(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = $this->verifyResponse(['amount' => 0]);

        $this->assertSame(
            TransactionValidator::REASON_ZERO_TOTAL,
            $this->validator->settlementFailureReason($response, $order)
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
            $this->validator->settlementFailureReason($response, $order)
        );
    }

    public function testMissingOrderCurrencyRejectsAsCurrencyMismatch(): void
    {
        $order = $this->makeOrder(5000.00, null);
        $response = $this->verifyResponse();

        $this->logger->expects($this->once())->method('warning');

        $this->assertSame(
            TransactionValidator::REASON_CURRENCY_MISMATCH,
            $this->validator->settlementFailureReason($response, $order)
        );
    }

    public function testWrongPaymentMethodRejects(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN', 'checkmo');
        $response = $this->verifyResponse();

        $this->logger->expects($this->once())->method('warning');

        $this->assertSame(
            TransactionValidator::REASON_WRONG_METHOD,
            $this->validator->settlementFailureReason($response, $order)
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
            $this->validator->settlementFailureReason($response, $order)
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
            $this->validator->settlementFailureReason($response, $order)
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
            $this->validator->settlementFailureReason($response, $order)
        );
    }

    public static function notSuccessfulStatusProvider(): array
    {
        return [
            'abandoned' => ['abandoned'],
            'failed' => ['failed'],
        ];
    }

    /**
     * Pins the checked-in-this-order sequence from the class docblock: when more
     * than one thing is wrong with a response/order pair, the FIRST failing check
     * in the documented order must win, not whichever the implementation happens
     * to reach last. A refactor that reorders the checks would silently change
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
        string $expectedReason
    ): void {
        $order = $this->makeOrder($grandTotal, $currencyCode, $method);
        $response = $this->verifyResponse($responseOverrides);

        $this->assertSame(
            $expectedReason,
            $this->validator->settlementFailureReason($response, $order)
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
                TransactionValidator::REASON_MALFORMED,
            ],
            // An in-flight status is checked before the amount is compared, so a
            // short amount on an in-flight transaction still reports IN_FLIGHT.
            'in-flight status beats amount mismatch' => [
                5000.00,
                'NGN',
                Paystack::CODE,
                ['status' => 'pending', 'amount' => 499999],
                TransactionValidator::REASON_IN_FLIGHT,
            ],
            // Wrong payment method is checked before the zero-total guard.
            'wrong method beats zero total' => [
                0.00,
                'NGN',
                'checkmo',
                [],
                TransactionValidator::REASON_WRONG_METHOD,
            ],
            // Zero/negative expected total is checked before currency is compared.
            'zero total beats currency mismatch' => [
                0.00,
                'NGN',
                Paystack::CODE,
                ['currency' => 'USD'],
                TransactionValidator::REASON_ZERO_TOTAL,
            ],
            // Currency mismatch is checked before the amount comparison.
            'currency mismatch beats amount mismatch' => [
                5000.00,
                'NGN',
                Paystack::CODE,
                ['currency' => 'USD', 'amount' => 499999],
                TransactionValidator::REASON_CURRENCY_MISMATCH,
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

        $this->assertNull($this->validator->settlementFailureReason($response, $order));

        // Same order, but the verify amount now reflects what a base-USD-derived
        // subunit figure (i.e. getBaseGrandTotal() * 100) would have been -- this
        // must NOT match, because the validator reads getGrandTotal() (the
        // order-currency total), not the base total.
        $baseDerivedResponse = $this->verifyResponse(['amount' => 1500000, 'currency' => 'NGN']);

        $this->assertSame(
            TransactionValidator::REASON_AMOUNT_MISMATCH,
            $this->validator->settlementFailureReason($baseDerivedResponse, $order)
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
            // Regression test for the whole finding: an unrecognised reason must
            // fall into the safest copy, exactly like isTerminalForCustomer()
            // fails closed on terminality for the same input.
            'unknown reason fails closed' => ['some_future_reason', $doNotPayAgain],
        ];
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
     * A read, not a gate: an unreadable envelope must not throw, it must just
     * answer "no".
     */
    public function testIsOverpaymentFalseOnUnreadableResponse(): void
    {
        $order = $this->makeOrder(5000.00, 'NGN');
        $response = (object) [];

        $this->assertFalse($this->validator->isOverpayment($response, $order));
    }
}
