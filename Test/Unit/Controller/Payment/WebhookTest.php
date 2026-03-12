<?php

namespace Pstk\Paystack\Test\Unit\Controller\Payment;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Pstk\Paystack\Controller\Payment\Webhook;
use Pstk\Paystack\Gateway\PaystackApiClient;
use Pstk\Paystack\Gateway\Exception\ApiException;
use Pstk\Paystack\Model\Ui\ConfigProvider;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Event\Manager as EventManager;
use Magento\Framework\View\Result\PageFactory;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class WebhookTest extends TestCase
{
    /** @var Webhook */
    private $controller;

    /** @var MockObject|PaystackApiClient */
    private $paystackClient;

    /** @var MockObject|EventManager */
    private $eventManager;

    /** @var MockObject|HttpRequest */
    private $request;

    /** @var MockObject|OrderInterface */
    private $orderInterface;

    /** @var MockObject|OrderRepositoryInterface */
    private $orderRepository;

    /** @var MockObject|ConfigProvider */
    private $configProvider;

    /** @var MockObject|Raw */
    private $rawResult;

    /** @var MockObject|LoggerInterface */
    private $logger;

    protected function setUp(): void
    {
        $this->paystackClient = $this->createMock(PaystackApiClient::class);
        $this->eventManager = $this->createMock(EventManager::class);
        $this->request = $this->createMock(HttpRequest::class);
        $this->orderInterface = $this->createMock(\Magento\Sales\Model\Order::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->configProvider = $this->createMock(ConfigProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->rawResult = $this->createMock(Raw::class);
        $this->rawResult->method('setContents')->willReturnSelf();

        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')
            ->with(ResultFactory::TYPE_RAW)
            ->willReturn($this->rawResult);

        $context = $this->createMock(Context::class);
        $context->method('getResultFactory')->willReturn($resultFactory);
        // getRequest is called by parent constructor
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getResponse')->willReturn($this->createMock(\Magento\Framework\App\ResponseInterface::class));
        $context->method('getObjectManager')->willReturn($this->createMock(\Magento\Framework\ObjectManagerInterface::class));
        $context->method('getEventManager')->willReturn($this->eventManager);
        $context->method('getMessageManager')->willReturn($this->createMock(\Magento\Framework\Message\ManagerInterface::class));
        $context->method('getRedirect')->willReturn($this->createMock(\Magento\Framework\App\Response\RedirectInterface::class));
        $context->method('getActionFlag')->willReturn($this->createMock(\Magento\Framework\App\ActionFlag::class));
        $context->method('getView')->willReturn($this->createMock(\Magento\Framework\App\ViewInterface::class));
        $context->method('getUrl')->willReturn($this->createMock(\Magento\Framework\UrlInterface::class));
        $context->method('getResultRedirectFactory')->willReturn(
            $this->createMock(\Magento\Framework\Controller\Result\RedirectFactory::class)
        );

        $pageFactory = $this->createMock(PageFactory::class);
        $checkoutSession = $this->createMock(CheckoutSession::class);
        $paymentHelper = $this->createMock(PaymentHelper::class);
        $messageManager = $this->createMock(\Magento\Framework\Message\ManagerInterface::class);
        $storeManager = $this->createMock(StoreManagerInterface::class);

        $this->controller = new Webhook(
            $context,
            $pageFactory,
            $this->orderRepository,
            $this->orderInterface,
            $checkoutSession,
            $paymentHelper,
            $messageManager,
            $this->configProvider,
            $storeManager,
            $this->eventManager,
            $this->request,
            $this->logger,
            $this->paystackClient
        );
    }

    public function testInvalidSignatureReturnsAuthFailed(): void
    {
        $this->request->method('getContent')->willReturn('{"event":"charge.success"}');
        $this->request->method('getHeader')->with('X-Paystack-Signature')->willReturn('bad_sig');

        $this->paystackClient->method('validateWebhookSignature')->willReturn(false);

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with($this->callback(function ($val) {
                return $val === 'auth failed';
            }));

        $this->eventManager->expects($this->never())->method('dispatch');

        $this->controller->execute();
    }

    public function testMissingSignatureReturnsAuthFailed(): void
    {
        $this->request->method('getContent')->willReturn('{"event":"charge.success"}');
        $this->request->method('getHeader')->with('X-Paystack-Signature')->willReturn('');

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with($this->callback(function ($val) {
                return $val === 'auth failed';
            }));

        $this->controller->execute();
    }

    public function testChargeSuccessWithValidOrder(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'success',
                'reference' => 'ORDER_001',
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->with('X-Paystack-Signature')->willReturn('valid_sig');

        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $verifyResponse = (object) [
            'data' => (object) [
                'reference' => 'ORDER_001',
                'status' => 'success',
            ],
        ];
        $this->paystackClient->method('verifyTransaction')
            ->with('ORDER_001')
            ->willReturn($verifyResponse);

        $this->configProvider->method('getPublicKey')->willReturn('pk_test_123');
        $this->paystackClient->expects($this->once())
            ->method('logTransactionSuccess')
            ->with('ORDER_001', 'pk_test_123');

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('ORDER_001');
        $order->method('getStatus')->willReturn('pending');

        $this->orderInterface->method('loadByIncrementId')
            ->with('ORDER_001')
            ->willReturn($order);

        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with('paystack_payment_verify_after', ['paystack_order' => $order]);

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('success');

        $this->controller->execute();
    }

    public function testChargeSuccessWithQuoteIdFallback(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'success',
                'reference' => 'PSK_ref123',
                'metadata' => ['quoteId' => '55'],
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $verifyResponse = (object) [
            'data' => (object) [
                'reference' => 'PSK_ref123',
                'status' => 'success',
                'metadata' => (object) ['quoteId' => '55'],
            ],
        ];
        $this->paystackClient->method('verifyTransaction')->willReturn($verifyResponse);
        $this->configProvider->method('getPublicKey')->willReturn('pk_test');

        // loadByIncrementId returns empty order (not found by reference)
        $emptyOrder = $this->createMock(\Magento\Sales\Model\Order::class);
        $emptyOrder->method('getId')->willReturn(null);
        $this->orderInterface->method('loadByIncrementId')->willReturn($emptyOrder);

        // The webhook uses ObjectManager::getInstance() for the fallback path,
        // which cannot be easily unit-tested. Verify the loadByIncrementId was attempted.
        $this->orderInterface->expects($this->once())->method('loadByIncrementId');

        $this->controller->execute();
    }

    public function testInvalidJsonPayloadReturnsInvalidPayload(): void
    {
        $this->request->method('getContent')->willReturn('not-json');
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with($this->callback(function ($val) {
                return $val === 'invalid payload';
            }));

        $this->controller->execute();
    }

    public function testNonChargeSuccessEventIsIgnored(): void
    {
        $rawBody = json_encode([
            'event' => 'transfer.success',
            'data' => ['reference' => 'TRF_001'],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $this->eventManager->expects($this->never())->method('dispatch');
        $this->paystackClient->expects($this->never())->method('verifyTransaction');

        $this->controller->execute();
    }

    public function testChargeSuccessWithNonSuccessStatusIsIgnored(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'failed',
                'reference' => 'ORDER_002',
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $this->paystackClient->expects($this->never())->method('verifyTransaction');
        $this->eventManager->expects($this->never())->method('dispatch');

        $this->controller->execute();
    }

    public function testApiExceptionDuringVerifyReturnsErrorMessage(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'success',
                'reference' => 'ORDER_003',
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);
        $this->paystackClient->method('verifyTransaction')
            ->willThrowException(new ApiException('API down'));

        $this->eventManager->expects($this->never())->method('dispatch');

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with($this->callback(function ($val) {
                return $val === 'API down';
            }));

        $this->controller->execute();
    }
}
