<?php

namespace Pstk\Paystack\Test\Unit\Controller\Payment;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Pstk\Paystack\Controller\Payment\Callback;
use Pstk\Paystack\Gateway\PaystackApiClient;
use Pstk\Paystack\Gateway\Exception\ApiException;
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
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class CallbackTest extends TestCase
{
    /** @var Callback */
    private $controller;

    /** @var MockObject|PaystackApiClient */
    private $paystackClient;

    /** @var MockObject|EventManager */
    private $eventManager;

    /** @var MockObject|HttpRequest */
    private $request;

    /** @var MockObject|OrderInterface */
    private $orderInterface;

    /** @var MockObject|MessageManager */
    private $messageManager;

    private function createController(): Callback
    {
        $this->paystackClient = $this->createMock(PaystackApiClient::class);
        $this->eventManager = $this->createMock(EventManager::class);
        $this->request = $this->createMock(HttpRequest::class);
        $this->orderInterface = $this->createMock(\Magento\Sales\Model\Order::class);
        $this->messageManager = $this->createMock(MessageManager::class);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setUrl')->willReturnSelf();

        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getResponse')->willReturn($this->createMock(\Magento\Framework\App\ResponseInterface::class));
        $context->method('getObjectManager')->willReturn($this->createMock(\Magento\Framework\ObjectManagerInterface::class));
        $context->method('getEventManager')->willReturn($this->eventManager);
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $context->method('getRedirect')->willReturn($this->createMock(RedirectInterface::class));
        $context->method('getActionFlag')->willReturn($this->createMock(\Magento\Framework\App\ActionFlag::class));
        $context->method('getView')->willReturn($this->createMock(\Magento\Framework\App\ViewInterface::class));
        $context->method('getUrl')->willReturn($this->createMock(\Magento\Framework\UrlInterface::class));
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getResultFactory')->willReturn(
            $this->createMock(\Magento\Framework\Controller\ResultFactory::class)
        );

        return new Callback(
            $context,
            $this->createMock(PageFactory::class),
            $this->createMock(OrderRepositoryInterface::class),
            $this->orderInterface,
            $this->createMock(CheckoutSession::class),
            $this->createMock(PaymentHelper::class),
            $this->messageManager,
            $this->createMock(ConfigProvider::class),
            $this->createMock(StoreManagerInterface::class),
            $this->eventManager,
            $this->request,
            $this->createMock(LoggerInterface::class),
            $this->paystackClient
        );
    }

    public function testSuccessfulCallbackDispatchesEvent(): void
    {
        $controller = $this->createController();

        $this->request->method('get')
            ->with('reference')
            ->willReturn('000000001_suffix');

        $verifyResponse = (object) [
            'data' => (object) [
                'reference' => '000000001_suffix',
                'status' => 'success',
            ],
        ];
        $this->paystackClient->method('verifyTransaction')
            ->with('000000001_suffix')
            ->willReturn($verifyResponse);

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getIncrementId')->willReturn('000000001');
        $this->orderInterface->method('loadByIncrementId')
            ->with('000000001')
            ->willReturn($order);

        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with('paystack_payment_verify_after', ['paystack_order' => $order]);

        $controller->execute();
    }

    public function testMissingReferenceRedirectsToFailure(): void
    {
        $controller = $this->createController();

        $this->request->method('get')
            ->with('reference')
            ->willReturn(null);

        $this->messageManager->expects($this->once())
            ->method('addErrorMessage');

        $this->eventManager->expects($this->never())->method('dispatch');

        $controller->execute();
    }

    public function testApiExceptionRedirectsToFailure(): void
    {
        $controller = $this->createController();

        $this->request->method('get')->willReturn('bad_ref');

        $this->paystackClient->method('verifyTransaction')
            ->willThrowException(new ApiException('Transaction failed'));

        $this->messageManager->expects($this->once())
            ->method('addErrorMessage');

        $this->eventManager->expects($this->never())->method('dispatch');

        $controller->execute();
    }

    public function testOrderNotFoundRedirectsToFailure(): void
    {
        $controller = $this->createController();

        $this->request->method('get')->willReturn('000000099');

        $verifyResponse = (object) [
            'data' => (object) [
                'reference' => '000000099',
                'status' => 'success',
            ],
        ];
        $this->paystackClient->method('verifyTransaction')->willReturn($verifyResponse);

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getIncrementId')->willReturn(null);
        $this->orderInterface->method('loadByIncrementId')->willReturn($order);

        $this->eventManager->expects($this->never())->method('dispatch');

        $controller->execute();
    }
}
