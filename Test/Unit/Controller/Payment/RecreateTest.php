<?php

namespace Pstk\Paystack\Test\Unit\Controller\Payment;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Pstk\Paystack\Controller\Payment\Recreate;
use Pstk\Paystack\Gateway\PaystackApiClient;
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

    private function createController(): Recreate
    {
        $this->checkoutSession = $this->createMock(CheckoutSession::class);

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
        $context->method('getMessageManager')->willReturn($this->createMock(MessageManager::class));
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
            $this->createMock(MessageManager::class),
            $this->createMock(ConfigProvider::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(EventManager::class),
            $request,
            $this->createMock(LoggerInterface::class),
            $this->createMock(PaystackApiClient::class)
        );
    }

    public function testCancelsActiveOrderAndRestoresQuote(): void
    {
        $controller = $this->createController();

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(1);
        $order->method('getState')->willReturn(Order::STATE_NEW);

        $order->expects($this->once())
            ->method('registerCancellation')
            ->with('Payment failed or cancelled')
            ->willReturn($order);
        $order->expects($this->once())->method('save');

        $this->checkoutSession->method('getLastRealOrder')->willReturn($order);
        $this->checkoutSession->expects($this->once())->method('restoreQuote');

        $controller->execute();
    }

    public function testAlreadyCancelledOrderIsNotCancelledAgain(): void
    {
        $controller = $this->createController();

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(1);
        $order->method('getState')->willReturn(Order::STATE_CANCELED);

        $order->expects($this->never())->method('registerCancellation');

        $this->checkoutSession->method('getLastRealOrder')->willReturn($order);
        $this->checkoutSession->expects($this->once())->method('restoreQuote');

        $controller->execute();
    }

    public function testNoOrderStillRestoresQuote(): void
    {
        $controller = $this->createController();

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(null);

        $order->expects($this->never())->method('registerCancellation');

        $this->checkoutSession->method('getLastRealOrder')->willReturn($order);
        $this->checkoutSession->expects($this->once())->method('restoreQuote');

        $controller->execute();
    }
}
