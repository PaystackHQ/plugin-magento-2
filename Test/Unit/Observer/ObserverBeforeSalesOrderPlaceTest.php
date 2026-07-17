<?php

namespace Pstk\Paystack\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Pstk\Paystack\Observer\ObserverBeforeSalesOrderPlace;
use Pstk\Paystack\Model\Payment\Paystack;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;

class ObserverBeforeSalesOrderPlaceTest extends TestCase
{
    /** @var ObserverBeforeSalesOrderPlace */
    private $observer;

    protected function setUp(): void
    {
        $this->observer = new ObserverBeforeSalesOrderPlace();
    }

    public function testPaystackOrderSuppressesEmail(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(Paystack::CODE);

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->expects($this->once())
            ->method('setCanSendNewEmailFlag')
            ->with(false)
            ->willReturn($order);
        $order->expects($this->once())
            ->method('setCustomerNoteNotify')
            ->with(false);

        $event = $this->createMock(Event::class);
        $event->method('getOrder')->willReturn($order);

        $eventObserver = $this->createMock(Observer::class);
        $eventObserver->method('getEvent')->willReturn($event);

        $this->observer->execute($eventObserver);
    }

    public function testNonPaystackOrderDoesNotSuppressEmail(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn('checkmo');

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->expects($this->never())->method('setCanSendNewEmailFlag');

        $event = $this->createMock(Event::class);
        $event->method('getOrder')->willReturn($order);

        $eventObserver = $this->createMock(Observer::class);
        $eventObserver->method('getEvent')->willReturn($event);

        $this->observer->execute($eventObserver);
    }

    public function testNullPaymentDoesNotCrash(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn(null);

        $event = $this->createMock(Event::class);
        $event->method('getOrder')->willReturn($order);

        $eventObserver = $this->createMock(Observer::class);
        $eventObserver->method('getEvent')->willReturn($event);

        $this->observer->execute($eventObserver);
    }
}
