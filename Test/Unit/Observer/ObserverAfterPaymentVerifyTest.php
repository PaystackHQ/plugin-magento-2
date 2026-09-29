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

    /**
     * Model\PaymentSettlement::register() now owns advancing the order
     * (registerCaptureNotification() flips it to STATE_PROCESSING as a side
     * effect) — this observer is email-only. It must not set state/history
     * itself, and it must not save() the order: it receives the caller's own,
     * pre-register() instance, and saving it here would write that stale
     * instance back over the row register() already persisted.
     */
    public function testEmailNotYetSentSendsConfirmationEmail(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getEmailSent')->willReturn(null);

        $order->expects($this->never())->method('setState');
        $order->expects($this->never())->method('addStatusToHistory');
        $order->expects($this->never())->method('save');
        $order->expects($this->once())
            ->method('setCanSendNewEmailFlag')
            ->with(true)
            ->willReturn($order);
        $order->expects($this->once())
            ->method('setCustomerNoteNotify')
            ->with(true)
            ->willReturn($order);

        $this->orderSender->expects($this->once())
            ->method('send')
            ->with($order, true);

        $eventObserver = new Observer(['paystack_order' => $order]);

        $this->observer->execute($eventObserver);
    }

    /**
     * The gate is `!$order->getEmailSent()`, not the order's status/state —
     * `register()` may already have advanced the order to Processing by the
     * time this observer runs, and that must not itself suppress the email.
     */
    public function testAlreadyAdvancedOrderStillSendsEmailWhenNotYetSent(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getEmailSent')->willReturn(null);
        $order->method('setCanSendNewEmailFlag')->willReturn($order);
        $order->method('setCustomerNoteNotify')->willReturn($order);

        $order->expects($this->never())->method('setState');
        $order->expects($this->never())->method('save');

        $this->orderSender->expects($this->once())
            ->method('send')
            ->with($order, true);

        $eventObserver = new Observer(['paystack_order' => $order]);

        $this->observer->execute($eventObserver);
    }

    /**
     * A repeat dispatch (webhook + inline race, or a retried callback) once the
     * confirmation email has already gone out must not send a second one.
     */
    public function testEmailAlreadySentDoesNotResend(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getEmailSent')->willReturn(true);

        $order->expects($this->never())->method('setCanSendNewEmailFlag');
        $order->expects($this->never())->method('setCustomerNoteNotify');
        $order->expects($this->never())->method('save');

        $this->orderSender->expects($this->never())->method('send');

        $eventObserver = new Observer(['paystack_order' => $order]);

        $this->observer->execute($eventObserver);
    }

    public function testEmailSendingFailureDoesNotAffectOrderStatus(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getEmailSent')->willReturn(null);
        $order->method('setCanSendNewEmailFlag')->willReturn($order);
        $order->method('setCustomerNoteNotify')->willReturn($order);

        $order->expects($this->never())->method('save');

        $this->orderSender->method('send')
            ->willThrowException(new \Exception('SMTP failure'));

        $eventObserver = new Observer(['paystack_order' => $order]);

        // Should not throw
        $this->observer->execute($eventObserver);
    }

    public function testNullOrderDoesNotCrash(): void
    {
        // This case genuinely only guarantees "does not throw": remove the `$order &&`
        // guard from the production code and it dereferences null at getEmailSent(),
        // failing here on that Error before the never() below could be evaluated.
        // The never() is therefore a smoke check, not the assertion doing the work.
        // The distinguishing negative case — a real order that must NOT resend — is
        // testEmailAlreadySentDoesNotResend above.
        $this->orderSender->expects($this->never())->method('send');

        $eventObserver = new Observer(['paystack_order' => null]);

        $this->observer->execute($eventObserver);
    }
}
