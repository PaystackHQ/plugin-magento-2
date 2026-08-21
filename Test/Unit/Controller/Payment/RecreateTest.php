<?php

namespace Pstk\Paystack\Test\Unit\Controller\Payment;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Pstk\Paystack\Controller\Payment\Recreate;
use Pstk\Paystack\Gateway\PaystackApiClient;
use Pstk\Paystack\Gateway\Validator\TransactionValidator;
use Pstk\Paystack\Model\Ui\ConfigProvider;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Event\Manager as EventManager;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class RecreateTest extends TestCase
{
    /** @var MockObject|CheckoutSession */
    private $checkoutSession;

    /** @var MockObject|MessageManager */
    private $messageManager;

    private function createController(): Recreate
    {
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->messageManager = $this->createMock(MessageManager::class);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setUrl')->willReturnSelf();

        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $context = $this->createMock(Context::class);
        $request = $this->createMock(HttpRequest::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResponse')->willReturn($this->createMock(\Magento\Framework\App\ResponseInterface::class));
        $context->method('getObjectManager')->willReturn($this->createMock(\Magento\Framework\ObjectManagerInterface::class));
        $context->method('getEventManager')->willReturn($this->createMock(EventManager::class));
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $context->method('getRedirect')->willReturn($this->createMock(RedirectInterface::class));
        $context->method('getActionFlag')->willReturn($this->createMock(\Magento\Framework\App\ActionFlag::class));
        $context->method('getView')->willReturn($this->createMock(\Magento\Framework\App\ViewInterface::class));
        $context->method('getUrl')->willReturn($this->createMock(\Magento\Framework\UrlInterface::class));
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getResultFactory')->willReturn(
            $this->createMock(\Magento\Framework\Controller\ResultFactory::class)
        );

        return new Recreate(
            $context,
            $this->createMock(PageFactory::class),
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(Order::class),
            $this->checkoutSession,
            $this->createMock(PaymentHelper::class),
            $this->messageManager,
            $this->createMock(ConfigProvider::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(EventManager::class),
            $request,
            $this->createMock(LoggerInterface::class),
            $this->createMock(PaystackApiClient::class),
            new TransactionValidator($this->createMock(LoggerInterface::class))
        );
    }

    public function testCancelsActiveOrderAndRestoresQuote(): void
    {
        $controller = $this->createController();

        $payment = $this->createMock(\Magento\Sales\Model\Order\Payment::class);
        $payment->method('getMethod')->willReturn(\Pstk\Paystack\Model\Payment\Paystack::CODE);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(1);
        $order->method('getState')->willReturn(Order::STATE_NEW);
        $order->method('getPayment')->willReturn($payment);

        $order->expects($this->once())
            ->method('registerCancellation')
            ->with('Payment failed or cancelled')
            ->willReturn($order);
        $order->expects($this->once())->method('save');

        $this->checkoutSession->method('getLastRealOrder')->willReturn($order);
        $this->checkoutSession->expects($this->once())->method('restoreQuote');

        $controller->execute();
    }

    public function testPendingPaymentOrderIsCancelledAndQuoteRestored(): void
    {
        $controller = $this->createController();

        $payment = $this->createMock(\Magento\Sales\Model\Order\Payment::class);
        $payment->method('getMethod')->willReturn(\Pstk\Paystack\Model\Payment\Paystack::CODE);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(1);
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->method('getPayment')->willReturn($payment);

        $order->expects($this->once())
            ->method('registerCancellation')
            ->with('Payment failed or cancelled')
            ->willReturn($order);
        $order->expects($this->once())->method('save');

        $this->checkoutSession->method('getLastRealOrder')->willReturn($order);
        $this->checkoutSession->expects($this->once())->method('restoreQuote');

        $controller->execute();
    }

    public function testNonPaystackOrderIsNotCancelledAndQuoteIsNotRestored(): void
    {
        // Even a pre-payment (new/pending_payment) order must not be cancelled and
        // its quote restored by an anonymous GET if it wasn't placed with
        // Paystack — otherwise a third-party page can CSRF a shopper's pending
        // offline order (Check/Money Order, bank transfer, COD) into "canceled".
        $controller = $this->createController();

        $payment = $this->createMock(\Magento\Sales\Model\Order\Payment::class);
        $payment->method('getMethod')->willReturn('checkmo');

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(1);
        $order->method('getState')->willReturn(Order::STATE_NEW);
        $order->method('getPayment')->willReturn($payment);

        $order->expects($this->never())->method('registerCancellation');
        $order->expects($this->never())->method('save');

        $this->checkoutSession->method('getLastRealOrder')->willReturn($order);
        $this->checkoutSession->expects($this->never())->method('restoreQuote');

        $this->messageManager->expects($this->once())->method('addErrorMessage');

        $controller->execute();
    }

    public function testProcessingOrderIsNotCancelledAndQuoteIsNotRestored(): void
    {
        $controller = $this->createController();

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(1);
        $order->method('getState')->willReturn(Order::STATE_PROCESSING);

        $order->expects($this->never())->method('registerCancellation');
        $order->expects($this->never())->method('save');

        $this->checkoutSession->method('getLastRealOrder')->willReturn($order);
        $this->checkoutSession->expects($this->never())->method('restoreQuote');

        $this->messageManager->expects($this->once())->method('addErrorMessage');

        $result = $controller->execute();

        $this->assertNotNull($result);
    }

    public function testAlreadyCancelledOrderIsNotRestorable(): void
    {
        // A paid/processing order is not the only state outside the {new,
        // pending_payment} allow-list: a previously-cancelled order also falls
        // outside it. This is a behavior change from before the guard existed —
        // a second hit on an already-cancelled order used to still restore the
        // quote (silent double-restore); it now shows the "could not restart"
        // notice instead. Cancellation is never a security risk (no money moved),
        // but the guard is an allow-list, not a deny-list, on purpose: it must
        // fail closed on any state it doesn't explicitly know is pre-payment.
        $controller = $this->createController();

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(1);
        $order->method('getState')->willReturn(Order::STATE_CANCELED);

        $order->expects($this->never())->method('registerCancellation');

        $this->checkoutSession->method('getLastRealOrder')->willReturn($order);
        $this->checkoutSession->expects($this->never())->method('restoreQuote');

        $this->messageManager->expects($this->once())->method('addErrorMessage');

        $controller->execute();
    }

    public function testNoOrderIdRedirectsToCheckoutWithoutErrorOrCancel(): void
    {
        // No last real order at all is a benign repeat hit (double-submit, back
        // button, refresh, expired session) — not an incident, so it should
        // silently restart checkout rather than land on the failure page.
        $controller = $this->createController();

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(null);

        $order->expects($this->never())->method('registerCancellation');
        $order->expects($this->never())->method('getState');

        $this->checkoutSession->method('getLastRealOrder')->willReturn($order);
        $this->checkoutSession->expects($this->never())->method('restoreQuote');

        $this->messageManager->expects($this->never())->method('addErrorMessage');

        $controller->execute();
    }
}
