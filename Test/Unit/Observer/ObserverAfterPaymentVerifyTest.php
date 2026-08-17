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

        $eventObserver = new Observer(['paystack_order' => $order]);

        $this->observer->execute($eventObserver);
    }

    /**
     * NOTE for the verification-gate work: this asserts the *status string* gate
     * that the observer currently uses, which is itself a known defect — a merchant
     * who assigns a custom default status to state New (e.g. `awaiting_payment`)
     * gets orders whose status is not the literal 'pending', so the observer no-ops
     * on every verified payment. When that is fixed to gate on state, this test is
     * expected to change; that is a planned correction, not a green test being bent.
     */
    public function testNonPendingOrderIsNotUpdated(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getStatus')->willReturn('processing');

        $order->expects($this->never())->method('setState');
        $order->expects($this->never())->method('save');

        $eventObserver = new Observer(['paystack_order' => $order]);

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

        $eventObserver = new Observer(['paystack_order' => $order]);

        // Should not throw
        $this->observer->execute($eventObserver);
    }

    public function testNullOrderDoesNotCrash(): void
    {
        // This case genuinely only guarantees "does not throw": remove the `$order &&`
        // guard from the production code and it dereferences null at getStatus(),
        // failing here on that Error before the never() below could be evaluated.
        // The never() is therefore a smoke check, not the assertion doing the work.
        // The distinguishing negative case — a real order that must NOT advance — is
        // testNonPendingOrderIsNotUpdated above.
        $this->orderSender->expects($this->never())->method('send');

        $eventObserver = new Observer(['paystack_order' => null]);

        $this->observer->execute($eventObserver);
    }
}
