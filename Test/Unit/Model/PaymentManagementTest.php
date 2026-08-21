<?php

namespace Pstk\Paystack\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Pstk\Paystack\Model\PaymentManagement;
use Pstk\Paystack\Gateway\PaystackApiClient;
use Pstk\Paystack\Gateway\Exception\ApiException;
use Pstk\Paystack\Gateway\Validator\TransactionValidator;
use Pstk\Paystack\Model\Payment\Paystack;
use Magento\Framework\Event\Manager as EventManager;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;

class PaymentManagementTest extends TestCase
{
    /** @var PaymentManagement */
    private $paymentManagement;

    /** @var MockObject|PaystackApiClient */
    private $paystackClient;

    /** @var MockObject|EventManager */
    private $eventManager;

    /** @var MockObject|OrderInterface */
    private $orderInterface;

    /** @var MockObject|CheckoutSession */
    private $checkoutSession;

    /** @var MockObject|LoggerInterface */
    private $logger;

    protected function setUp(): void
    {
        $this->paystackClient = $this->createMock(PaystackApiClient::class);
        $this->eventManager = $this->createMock(EventManager::class);
        $this->orderInterface = $this->createMock(\Magento\Sales\Model\Order::class);
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->paymentManagement = new PaymentManagement(
            $this->paystackClient,
            $this->eventManager,
            $this->orderInterface,
            $this->checkoutSession,
            $this->logger,
            new TransactionValidator($this->createMock(LoggerInterface::class))
        );
    }

    /**
     * Wires the checkout session / order repository mocks so verifyPayment()
     * finds a last-real-order that settlement-matches the given quoteId, with
     * payment method/grand total/currency configured for the validator.
     *
     * @param string $quoteId
     * @param float  $grandTotal
     * @param string $currencyCode
     * @return MockObject|\Magento\Sales\Model\Order
     */
    private function stubMatchingOrder(string $quoteId, float $grandTotal = 5000.00, string $currencyCode = 'NGN')
    {
        $lastOrder = $this->createMock(\Magento\Sales\Model\Order::class);
        $lastOrder->method('getIncrementId')->willReturn('000000001');
        $this->checkoutSession->method('getLastRealOrder')->willReturn($lastOrder);

        $payment = $this->createMock(OrderPayment::class);
        $payment->method('getMethod')->willReturn(Paystack::CODE);

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getQuoteId')->willReturn($quoteId);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getOrderCurrencyCode')->willReturn($currencyCode);
        $order->method('getIncrementId')->willReturn('000000001');

        $this->orderInterface->method('loadByIncrementId')
            ->with('000000001')
            ->willReturn($order);

        return $order;
    }

    /**
     * @param string $status
     * @param string $quoteId
     * @param int    $amount
     * @param string $currency
     * @return object
     */
    private function buildTxData(
        string $status,
        string $quoteId,
        int $amount = 500000,
        string $currency = 'NGN',
        string $reference = 'PSK_abc123'
    ): object {
        return (object) [
            'status' => $status,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'metadata' => (object) ['quoteId' => $quoteId],
        ];
    }

    public function testVerifyPaymentSuccessful(): void
    {
        $quoteId = '42';
        $reference = 'PSK_abc123_-~-_' . $quoteId;

        $txData = $this->buildTxData('success', $quoteId);
        $apiResponse = (object) ['data' => $txData];

        $this->paystackClient->expects($this->once())
            ->method('verifyTransaction')
            ->with('PSK_abc123')
            ->willReturn($apiResponse);

        $order = $this->stubMatchingOrder($quoteId);

        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with('paystack_payment_verify_after', ['paystack_order' => $order]);

        $result = json_decode($this->paymentManagement->verifyPayment($reference), true);

        $this->assertTrue($result['status']);
        $this->assertEquals('Verification successful', $result['message']);
        $this->assertSame(['status', 'reference'], array_keys($result['data']));
        $this->assertEquals('success', $result['data']['status']);
        $this->assertEquals('PSK_abc123', $result['data']['reference']);
    }

    public function testVerifyPaymentQuoteIdMismatch(): void
    {
        $reference = 'PSK_abc123_-~-_42';

        $txData = (object) [
            'status' => 'success',
            'reference' => 'PSK_abc123',
            'metadata' => (object) ['quoteId' => '99'],
        ];
        $apiResponse = (object) ['data' => $txData];

        $this->paystackClient->method('verifyTransaction')->willReturn($apiResponse);

        $lastOrder = $this->createMock(\Magento\Sales\Model\Order::class);
        $lastOrder->method('getIncrementId')->willReturn('000000001');
        $this->checkoutSession->method('getLastRealOrder')->willReturn($lastOrder);

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getQuoteId')->willReturn('42');
        $this->orderInterface->method('loadByIncrementId')->willReturn($order);

        $this->eventManager->expects($this->never())->method('dispatch');

        $result = json_decode($this->paymentManagement->verifyPayment($reference), true);

        $this->assertFalse($result['status']);
        $this->assertEquals('quote_mismatch', $result['reason']);
        $this->assertTrue($result['final'], 'Terminal: the customer must not be invited to pay again.');
        // 'quote_mismatch' is not on TransactionValidator's explicit retry-safe
        // list, so customerMessage() falls into the same fail-closed default
        // branch as any other unrecognised reason. This changes the raw JSON
        // `message` text (was the shorter "Payment could not be verified.")
        // but is NOT a customer-visible behavior change: the inline JS was
        // already terminal-by-default for every reason except
        // 'not_successful', and its old terminal-branch fallback literal was
        // character-identical to this new copy — only the wire contract moved.
        $this->assertEquals(
            'We could not confirm your payment. Please do not pay again — contact support with your order number.',
            $result['message']
        );
    }

    public function testVerifyPaymentOrderQuoteIdMismatch(): void
    {
        $reference = 'PSK_abc123_-~-_42';

        $txData = (object) [
            'status' => 'success',
            'reference' => 'PSK_abc123',
            'metadata' => (object) ['quoteId' => '42'],
        ];
        $apiResponse = (object) ['data' => $txData];

        $this->paystackClient->method('verifyTransaction')->willReturn($apiResponse);

        $lastOrder = $this->createMock(\Magento\Sales\Model\Order::class);
        $lastOrder->method('getIncrementId')->willReturn('000000001');
        $this->checkoutSession->method('getLastRealOrder')->willReturn($lastOrder);

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getQuoteId')->willReturn('99');
        $this->orderInterface->method('loadByIncrementId')->willReturn($order);

        $this->eventManager->expects($this->never())->method('dispatch');

        $result = json_decode($this->paymentManagement->verifyPayment($reference), true);

        $this->assertFalse($result['status']);
        $this->assertEquals('quote_mismatch', $result['reason']);
        $this->assertTrue($result['final'], 'Terminal: the customer must not be invited to pay again.');
    }

    public function testVerifyPaymentApiExceptionReturnsError(): void
    {
        $reference = 'bad_ref_-~-_42';
        $exceptionMessage = 'Transaction not found: raw gateway body leaked here';

        $this->paystackClient->method('verifyTransaction')
            ->willThrowException(new ApiException($exceptionMessage));

        $this->eventManager->expects($this->never())->method('dispatch');

        $result = json_decode($this->paymentManagement->verifyPayment($reference), true);

        $this->assertFalse($result['status']);
        $this->assertEquals('error', $result['reason']);
        $this->assertTrue($result['final'], 'Terminal: the customer must not be invited to pay again.');
        // 'error' is not on the retry-safe list either, so it falls into
        // customerMessage()'s same fail-closed default as 'quote_mismatch'
        // above. Wire-contract change only, not a customer-visible one — see
        // the comment on testVerifyPaymentQuoteIdMismatch.
        $this->assertEquals(
            'We could not confirm your payment. Please do not pay again — contact support with your order number.',
            $result['message']
        );
        $this->assertStringNotContainsString($exceptionMessage, json_encode($result));
    }

    public function testVerifyPaymentNoLastOrderReturnsError(): void
    {
        $reference = 'PSK_abc123_-~-_42';

        $txData = (object) [
            'status' => 'success',
            'reference' => 'PSK_abc123',
            'metadata' => (object) ['quoteId' => '42'],
        ];
        $this->paystackClient->method('verifyTransaction')
            ->willReturn((object) ['data' => $txData]);

        $this->checkoutSession->method('getLastRealOrder')->willReturn(null);

        $this->eventManager->expects($this->never())->method('dispatch');

        $result = json_decode($this->paymentManagement->verifyPayment($reference), true);

        $this->assertFalse($result['status']);
        $this->assertEquals('quote_mismatch', $result['reason']);
        $this->assertTrue($result['final'], 'Terminal: the customer must not be invited to pay again.');
    }

    public function testVerifyPaymentNoIncrementIdReturnsError(): void
    {
        $reference = 'PSK_abc123_-~-_42';

        $txData = (object) [
            'status' => 'success',
            'reference' => 'PSK_abc123',
            'metadata' => (object) ['quoteId' => '42'],
        ];
        $this->paystackClient->method('verifyTransaction')
            ->willReturn((object) ['data' => $txData]);

        $lastOrder = $this->createMock(\Magento\Sales\Model\Order::class);
        $lastOrder->method('getIncrementId')->willReturn(null);
        $this->checkoutSession->method('getLastRealOrder')->willReturn($lastOrder);

        $this->eventManager->expects($this->never())->method('dispatch');

        $result = json_decode($this->paymentManagement->verifyPayment($reference), true);

        $this->assertFalse($result['status']);
        $this->assertEquals('quote_mismatch', $result['reason']);
        $this->assertTrue($result['final'], 'Terminal: the customer must not be invited to pay again.');
    }

    /**
     * @dataProvider notSuccessfulStatusProvider
     */
    public function testVerifyPaymentNotSuccessfulStatusRejected(string $status): void
    {
        $quoteId = '42';
        $reference = 'PSK_abc123_-~-_' . $quoteId;

        $txData = $this->buildTxData($status, $quoteId);
        $this->paystackClient->method('verifyTransaction')->willReturn((object) ['data' => $txData]);

        $this->stubMatchingOrder($quoteId);

        $this->eventManager->expects($this->never())->method('dispatch');

        $result = json_decode($this->paymentManagement->verifyPayment($reference), true);

        $this->assertFalse($result['status']);
        $this->assertEquals('not_successful', $result['reason']);
        $this->assertFalse($result['final'], 'Retry-safe: nothing was charged through us.');
        // Paystack's own record says nothing was charged for this reason — the
        // one status-based reason customerMessage() invites a retry for.
        $this->assertEquals('Your payment was not completed. Please try again.', $result['message']);
    }

    public static function notSuccessfulStatusProvider(): array
    {
        return [
            'abandoned' => ['abandoned'],
            'failed' => ['failed'],
        ];
    }

    /**
     * @dataProvider inFlightStatusProvider
     */
    public function testVerifyPaymentInFlightStatusRejected(string $status): void
    {
        $quoteId = '42';
        $reference = 'PSK_abc123_-~-_' . $quoteId;

        $txData = $this->buildTxData($status, $quoteId);
        $this->paystackClient->method('verifyTransaction')->willReturn((object) ['data' => $txData]);

        $this->stubMatchingOrder($quoteId);

        $this->eventManager->expects($this->never())->method('dispatch');

        $result = json_decode($this->paymentManagement->verifyPayment($reference), true);

        $this->assertFalse($result['status']);
        $this->assertEquals('in_flight', $result['reason']);
        $this->assertTrue($result['final'], 'Terminal: the customer must not be invited to pay again.');
        $this->assertStringContainsString('do not pay again', $result['message']);
    }

    public static function inFlightStatusProvider(): array
    {
        return [
            'pending' => ['pending'],
            'ongoing' => ['ongoing'],
            'queued' => ['queued'],
        ];
    }

    public function testVerifyPaymentAmountShortByOneSubunitRejected(): void
    {
        $quoteId = '42';
        $reference = 'PSK_abc123_-~-_' . $quoteId;

        // Order expects 500000 subunits (grand total 5000.00); paid one short.
        $txData = $this->buildTxData('success', $quoteId, 499999, 'NGN');
        $this->paystackClient->method('verifyTransaction')->willReturn((object) ['data' => $txData]);

        $this->stubMatchingOrder($quoteId, 5000.00, 'NGN');

        $this->eventManager->expects($this->never())->method('dispatch');

        $result = json_decode($this->paymentManagement->verifyPayment($reference), true);

        $this->assertFalse($result['status']);
        $this->assertEquals('amount_mismatch', $result['reason']);
        $this->assertTrue($result['final'], 'Terminal: the customer must not be invited to pay again.');
    }

    public function testVerifyPaymentCurrencyMismatchRejected(): void
    {
        $quoteId = '42';
        $reference = 'PSK_abc123_-~-_' . $quoteId;

        $txData = $this->buildTxData('success', $quoteId, 500000, 'USD');
        $this->paystackClient->method('verifyTransaction')->willReturn((object) ['data' => $txData]);

        $this->stubMatchingOrder($quoteId, 5000.00, 'NGN');

        $this->eventManager->expects($this->never())->method('dispatch');

        $result = json_decode($this->paymentManagement->verifyPayment($reference), true);

        $this->assertFalse($result['status']);
        $this->assertEquals('currency_mismatch', $result['reason']);
        $this->assertTrue($result['final'], 'Terminal: the customer must not be invited to pay again.');
    }

    /**
     * A verify response with no `metadata` at all must fail closed to
     * quote_mismatch, not emit an "Undefined property"/"Attempt to read property
     * on null" warning — Magento developer mode escalates warnings to
     * exceptions, so this would otherwise 500 instead of returning JSON.
     */
    public function testVerifyPaymentMissingMetadataReturnsQuoteMismatchWithoutWarning(): void
    {
        $reference = 'PSK_abc123_-~-_42';

        $txData = (object) [
            'status' => 'success',
            'reference' => 'PSK_abc123',
            'amount' => 500000,
            'currency' => 'NGN',
        ];
        $this->paystackClient->method('verifyTransaction')
            ->willReturn((object) ['data' => $txData]);

        $this->stubMatchingOrder('42');

        $this->eventManager->expects($this->never())->method('dispatch');

        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        }, E_WARNING | E_NOTICE | E_DEPRECATED);

        try {
            $result = json_decode($this->paymentManagement->verifyPayment($reference), true);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings, 'A verify response with no `metadata` must not emit PHP warnings/notices.');
        $this->assertFalse($result['status']);
        $this->assertEquals('quote_mismatch', $result['reason']);
        $this->assertTrue($result['final'], 'Terminal: the customer must not be invited to pay again.');
    }

    /**
     * The `_-~-_` split guard fires before any verify call — nothing was
     * charged through us — so this now reports REASON_BAD_REFERENCE, not
     * REASON_MALFORMED (which is reserved for an unreadable *gateway* verify
     * response, a case where money's fate is unknown). Deliberate change: the
     * copy also gains "Please try again." since bad_reference is retry-safe.
     */
    public function testVerifyPaymentMalformedReferenceReturnsGenericFailure(): void
    {
        $reference = 'no-separator-reference';

        $this->paystackClient->expects($this->never())->method('verifyTransaction');
        $this->eventManager->expects($this->never())->method('dispatch');

        $result = json_decode($this->paymentManagement->verifyPayment($reference), true);

        $this->assertFalse($result['status']);
        $this->assertEquals('bad_reference', $result['reason']);
        $this->assertFalse($result['final'], 'Retry-safe: nothing was charged through us.');
        $this->assertEquals('Payment could not be verified. Please try again.', $result['message']);
    }
}
