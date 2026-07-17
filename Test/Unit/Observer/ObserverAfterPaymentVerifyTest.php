<?php

namespace Pstk\Paystack\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Pstk\Paystack\Observer\ObserverAfterPaymentVerify;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;

class ObserverAfterPaymentVerifyTest extends TestCase
{
    /** @var ObserverAfterPaymentVerify */
    private $observer;

    /** @var MockObject|OrderSender */
    private $orderSender;

    protected function setUp(): void
    {
        $this->orderSender = $this->createMock(OrderSender::class);
        $this->observer = new ObserverAfterPaymentVerify($this->orderSender);
    }

    public function testPendingOrderTransitionsToProcessing(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getStatus')->willReturn('pending');

        $order->expects($this->once())
            ->method('setState')
            ->with(Order::STATE_PROCESSING)
            ->willReturn($order);
        $order->expects($this->once())
            ->method('addStatusToHistory')
            ->with(
                Order::STATE_PROCESSING,
                $this->callback(fn($msg) => str_contains((string)$msg, 'Paystack Payment Verified')),
                true
            )
            ->willReturn($order);
        $order->expects($this->once())
            ->method('setCanSendNewEmailFlag')
            ->with(true)
            ->willReturn($order);
        $order->expects($this->once())
            ->method('setCustomerNoteNotify')
            ->with(true)
            ->willReturn($order);
        $order->expects($this->once())->method('save');

        $this->orderSender->expects($this->once())
            ->method('send')
            ->with($order, true);

        $eventObserver = $this->createMock(Observer::class);
        $eventObserver->method('getPaystackOrder')->willReturn($order);

        $this->observer->execute($eventObserver);
    }

    public function testNonPendingOrderIsNotUpdated(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getStatus')->willReturn('processing');

        $order->expects($this->never())->method('setState');
        $order->expects($this->never())->method('save');

        $eventObserver = $this->createMock(Observer::class);
        $eventObserver->method('getPaystackOrder')->willReturn($order);

        $this->observer->execute($eventObserver);
    }

    public function testEmailSendingFailureDoesNotAffectOrderStatus(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getStatus')->willReturn('pending');
        $order->method('setState')->willReturn($order);
        $order->method('addStatusToHistory')->willReturn($order);
        $order->method('setCanSendNewEmailFlag')->willReturn($order);
        $order->method('setCustomerNoteNotify')->willReturn($order);

        $order->expects($this->once())->method('save');

        $this->orderSender->method('send')
            ->willThrowException(new \Exception('SMTP failure'));

        $eventObserver = $this->createMock(Observer::class);
        $eventObserver->method('getPaystackOrder')->willReturn($order);

        // Should not throw
        $this->observer->execute($eventObserver);
    }

    public function testNullOrderDoesNotCrash(): void
    {
        $eventObserver = $this->createMock(Observer::class);
        $eventObserver->method('getPaystackOrder')->willReturn(null);

        // Should not throw
        $this->observer->execute($eventObserver);
    }
}
