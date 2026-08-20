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
}
