<?php

namespace Pstk\Paystack\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Pstk\Paystack\Model\PaymentManagement;
use Pstk\Paystack\Gateway\PaystackApiClient;
use Pstk\Paystack\Gateway\Exception\ApiException;
use Magento\Framework\Event\Manager as EventManager;
use Magento\Sales\Api\Data\OrderInterface;
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
            $this->logger
        );
    }

    public function testVerifyPaymentSuccessful(): void
    {
        $quoteId = '42';
        $reference = 'PSK_abc123_-~-_' . $quoteId;

        $txData = (object) [
            'status' => 'success',
            'reference' => 'PSK_abc123',
            'metadata' => (object) ['quoteId' => $quoteId],
        ];
        $apiResponse = (object) ['data' => $txData];

        $this->paystackClient->expects($this->once())
            ->method('verifyTransaction')
            ->with('PSK_abc123')
            ->willReturn($apiResponse);

        $lastOrder = $this->createMock(\Magento\Sales\Model\Order::class);
        $lastOrder->method('getIncrementId')->willReturn('000000001');
        $this->checkoutSession->method('getLastRealOrder')->willReturn($lastOrder);

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getQuoteId')->willReturn($quoteId);
        $this->orderInterface->method('loadByIncrementId')
            ->with('000000001')
            ->willReturn($order);

        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with('paystack_payment_verify_after', ['paystack_order' => $order]);

        $result = json_decode($this->paymentManagement->verifyPayment($reference), true);

        $this->assertTrue($result['status']);
        $this->assertEquals('Verification successful', $result['message']);
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
        $this->assertStringContainsString("quoteId doesn't match", $result['message']);
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
    }

    public function testVerifyPaymentApiExceptionReturnsError(): void
    {
        $reference = 'bad_ref_-~-_42';

        $this->paystackClient->method('verifyTransaction')
            ->willThrowException(new ApiException('Transaction not found'));

        $this->eventManager->expects($this->never())->method('dispatch');

        $result = json_decode($this->paymentManagement->verifyPayment($reference), true);

        $this->assertFalse($result['status']);
        $this->assertEquals('Transaction not found', $result['message']);
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
    }
}
