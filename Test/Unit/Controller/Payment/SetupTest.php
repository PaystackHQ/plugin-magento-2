<?php

namespace Pstk\Paystack\Test\Unit\Controller\Payment;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Pstk\Paystack\Controller\Payment\Setup;
use Pstk\Paystack\Gateway\PaystackApiClient;
use Pstk\Paystack\Gateway\Exception\ApiException;
use Pstk\Paystack\Model\Payment\Paystack;
use Pstk\Paystack\Model\Ui\ConfigProvider;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Event\Manager as EventManager;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\MethodInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class SetupTest extends TestCase
{
    /** @var MockObject|PaystackApiClient */
    private $paystackClient;

    /** @var MockObject|CheckoutSession */
    private $checkoutSession;

    /** @var MockObject|OrderInterface */
    private $orderInterface;

    /** @var MockObject|PaymentHelper */
    private $paymentHelper;

    /** @var MockObject|StoreManagerInterface */
    private $storeManager;

    /** @var MockObject|OrderRepositoryInterface */
    private $orderRepository;

    /** @var MockObject|MessageManager */
    private $messageManager;

    /** @var MockObject|Redirect */
    private $redirect;

    private function createController(): Setup
    {
        $this->paystackClient = $this->createMock(PaystackApiClient::class);
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->orderInterface = $this->createMock(Order::class);
        $this->paymentHelper = $this->createMock(PaymentHelper::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->messageManager = $this->createMock(MessageManager::class);

        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setUrl')->willReturnSelf();

        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);

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

        return new Setup(
            $context,
            $this->createMock(PageFactory::class),
            $this->orderRepository,
            $this->orderInterface,
            $this->checkoutSession,
            $this->paymentHelper,
            $this->messageManager,
            $this->createMock(ConfigProvider::class),
            $this->storeManager,
            $this->createMock(EventManager::class),
            $request,
            $this->createMock(LoggerInterface::class),
            $this->paystackClient
        );
    }

    public function testSuccessfulSetupRedirectsToPaystack(): void
    {
        $controller = $this->createController();

        $lastOrder = $this->createMock(Order::class);
        $lastOrder->method('getIncrementId')->willReturn('000000001');
        $this->checkoutSession->method('getLastRealOrder')->willReturn($lastOrder);

        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(Paystack::CODE);

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getCustomerFirstname')->willReturn('John');
        $order->method('getCustomerLastname')->willReturn('Doe');
        $order->method('getGrandTotal')->willReturn(5000.00);
        $order->method('getCustomerEmail')->willReturn('john@example.com');
        $order->method('getIncrementId')->willReturn('000000001');
        $order->method('getOrderCurrencyCode')->willReturn('NGN');

        $this->orderInterface->method('loadByIncrementId')
            ->with('000000001')
            ->willReturn($order);

        $methodInstance = $this->createMock(MethodInterface::class);
        $methodInstance->method('getCode')->willReturn(Paystack::CODE);
        $this->paymentHelper->method('getMethodInstance')
            ->with(Paystack::CODE)
            ->willReturn($methodInstance);

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $this->storeManager->method('getStore')->willReturn($store);

        $txResponse = (object) [
            'data' => (object) [
                'authorization_url' => 'https://checkout.paystack.com/abc123',
            ],
        ];
        $this->paystackClient->expects($this->once())
            ->method('initializeTransaction')
            ->with($this->callback(function ($params) {
                return $params['amount'] === 500000 // kobo, integer subunit
                    && $params['currency'] === 'NGN'
                    && $params['email'] === 'john@example.com'
                    && $params['reference'] === '000000001'
                    && $params['callback_url'] === 'https://example.com/paystack/payment/callback';
            }))
            ->willReturn($txResponse);

        $this->redirect->expects($this->once())
            ->method('setUrl')
            ->with('https://checkout.paystack.com/abc123');

        $controller->execute();
    }

    public function testApiExceptionSavesStatusHistory(): void
    {
        $controller = $this->createController();

        $lastOrder = $this->createMock(Order::class);
        $lastOrder->method('getIncrementId')->willReturn('000000001');
        $this->checkoutSession->method('getLastRealOrder')->willReturn($lastOrder);

        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(Paystack::CODE);

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStatus')->willReturn('pending');
        $order->method('getCustomerFirstname')->willReturn('John');
        $order->method('getCustomerLastname')->willReturn('Doe');
        $order->method('getGrandTotal')->willReturn(100.00);
        $order->method('getCustomerEmail')->willReturn('john@test.com');
        $order->method('getIncrementId')->willReturn('000000001');
        $order->method('getOrderCurrencyCode')->willReturn('NGN');

        $this->orderInterface->method('loadByIncrementId')->willReturn($order);

        $methodInstance = $this->createMock(MethodInterface::class);
        $methodInstance->method('getCode')->willReturn(Paystack::CODE);
        $this->paymentHelper->method('getMethodInstance')->willReturn($methodInstance);

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $this->storeManager->method('getStore')->willReturn($store);

        $this->paystackClient->method('initializeTransaction')
            ->willThrowException(new ApiException('Invalid key'));

        $order->expects($this->once())
            ->method('addStatusToHistory')
            ->with('pending', 'Invalid key');

        $this->orderRepository->expects($this->once())
            ->method('save')
            ->with($order);

        $controller->execute();
    }
}
