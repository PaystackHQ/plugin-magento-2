<?php

namespace Pstk\Paystack\Test\Unit\Controller\Payment;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Pstk\Paystack\Controller\Payment\Webhook;
use Pstk\Paystack\Gateway\PaystackApiClient;
use Pstk\Paystack\Gateway\Exception\ApiException;
use Pstk\Paystack\Gateway\Validator\TransactionValidator;
use Pstk\Paystack\Model\Payment\Paystack;
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
        $this->rawResult->method('setHttpResponseCode')->willReturnSelf();

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
            $this->paystackClient,
            new TransactionValidator($this->createMock(LoggerInterface::class))
        );
    }

    protected function tearDown(): void
    {
        // The webhook's quoteId fallback reaches for ObjectManager::getInstance()
        // directly. A test that primes the static instance to exercise that branch
        // must not leak it into whichever test runs next in this process.
        $reflection = new \ReflectionClass(\Magento\Framework\App\ObjectManager::class);
        $property = $reflection->getProperty('_instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    /**
     * A settled order: same payment method, currency and grand total the
     * matching-currency `settledVerifyData()` amount pays for in full.
     */
    private function createSettledOrder(string $incrementId): MockObject
    {
        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getId')->willReturn(1);
        $order->method('getIncrementId')->willReturn($incrementId);
        $order->method('getStatus')->willReturn('pending');

        $payment = $this->createMock(\Magento\Sales\Model\Order\Payment::class);
        $payment->method('getMethod')->willReturn(Paystack::CODE);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getGrandTotal')->willReturn(5000.00);
        $order->method('getOrderCurrencyCode')->willReturn('NGN');

        return $order;
    }

    /**
     * Verify-response `data` fields for a successful transaction that settles the
     * order `createSettledOrder()` builds (5000.00 NGN => 500000 subunits).
     */
    private function settledVerifyData(string $reference, array $overrides = []): array
    {
        return array_merge([
            'reference' => $reference,
            'status' => 'success',
            'amount' => 500000,
            'currency' => 'NGN',
        ], $overrides);
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
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(401);

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
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(401);

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
            'data' => (object) $this->settledVerifyData('ORDER_001'),
        ];
        $this->paystackClient->method('verifyTransaction')
            ->with('ORDER_001')
            ->willReturn($verifyResponse);

        $this->configProvider->method('getPublicKey')->willReturn('pk_test_123');
        $this->paystackClient->expects($this->once())
            ->method('logTransactionSuccess')
            ->with('ORDER_001', 'pk_test_123');

        $order = $this->createSettledOrder('ORDER_001');
        $order->expects($this->never())->method('addStatusToHistory');

        $this->orderInterface->method('loadByIncrementId')
            ->with('ORDER_001')
            ->willReturn($order);

        $this->orderRepository->expects($this->never())->method('save');

        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with('paystack_payment_verify_after', ['paystack_order' => $order]);

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('success');
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(200);

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

        // The webhook uses ObjectManager::getInstance() for the fallback path, which
        // cannot be easily unit-tested here without priming the static instance (done
        // separately below in testQuoteIdFallbackWithAmountMismatchDoesNotDispatch, the
        // test that actually exercises this branch's settlement gate). This test is
        // limited to the behavioral floor that holds regardless of how that call
        // resolves: the lookup was attempted, and nothing dispatched or saved off it.
        $this->orderInterface->expects($this->once())->method('loadByIncrementId');
        $this->eventManager->expects($this->never())->method('dispatch');
        $this->orderRepository->expects($this->never())->method('save');

        $this->controller->execute();
    }

    /**
     * The settlement gate applies on the quoteId-fallback lookup too, not only the
     * by-reference lookup — a mismatch found via the fallback must not dispatch.
     */
    public function testQuoteIdFallbackWithAmountMismatchDoesNotDispatch(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'success',
                'reference' => 'PSK_ref456',
                'metadata' => ['quoteId' => '77'],
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $verifyResponse = (object) [
            'data' => (object) $this->settledVerifyData('PSK_ref456', ['amount' => 499999]),
        ];
        $this->paystackClient->method('verifyTransaction')->willReturn($verifyResponse);
        $this->configProvider->method('getPublicKey')->willReturn('pk_test');

        $emptyOrder = $this->createMock(\Magento\Sales\Model\Order::class);
        $emptyOrder->method('getId')->willReturn(null);
        $this->orderInterface->method('loadByIncrementId')->willReturn($emptyOrder);

        $order = $this->createSettledOrder('000000055');
        $order->expects($this->once())
            ->method('addStatusToHistory')
            ->with('pending', $this->stringContains('amount_mismatch'));

        // OrderSearchResultInterface itself has no getFirstItem() — the concrete
        // collection orderRepository->getList() actually returns does, which is what
        // Webhook.php's fallback branch relies on.
        $searchResult = $this->createMock(\Magento\Sales\Model\ResourceModel\Order\Collection::class);
        $searchResult->method('getTotalCount')->willReturn(1);
        $searchResult->method('getFirstItem')->willReturn($order);
        $this->orderRepository->method('getList')->willReturn($searchResult);
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $searchCriteriaBuilder = $this->createMock(\Magento\Framework\Api\SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $searchCriteriaBuilder->method('create')
            ->willReturn($this->createMock(\Magento\Framework\Api\SearchCriteria::class));

        $objectManager = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $objectManager->method('create')
            ->with('Magento\Framework\Api\SearchCriteriaBuilder')
            ->willReturn($searchCriteriaBuilder);
        \Magento\Framework\App\ObjectManager::setInstance($objectManager);

        $this->eventManager->expects($this->never())->method('dispatch');

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(200);
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('rejected');

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
        // Malformed JSON cannot be fixed by a retry — permanent, not transient.
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(200);

        $this->controller->execute();
    }

    /**
     * Event types this switch does not act on are permanently accepted (200,
     * "ignored"), never retried — pins the explicit fall-through that replaced
     * the dead `$finalMessage` variable (it could only ever hold its initializer,
     * "failed", and nothing ever read it as anything else).
     */
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

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(200);
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('ignored');

        $this->controller->execute();
    }

    /**
     * A `charge.success` whose payload status is not itself "success" falls
     * through to the same permanent-accept branch as an unhandled event type.
     */
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

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(200);
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('ignored');

        $this->controller->execute();
    }

    /**
     * The webhook payload's own status only gates whether re-verification is
     * attempted; the re-verified status must also be checked (this closed a gap
     * where only `event.data.status` was read, never the verify response's status).
     *
     * The fixture deliberately includes `amount`/`currency` so the validator reaches
     * `REASON_IN_FLIGHT` (a fixture missing them would resolve to `REASON_MALFORMED`
     * first, since that check runs earlier — same 503, but the wrong reason, and this
     * test's "no history written" assertion only holds for IN_FLIGHT under the
     * deny-list history behavior).
     */
    public function testReVerifyPendingStatusReturns503WithoutDispatchOrHistory(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'success',
                'reference' => 'ORDER_PENDING',
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $verifyResponse = (object) [
            'data' => (object) $this->settledVerifyData('ORDER_PENDING', ['status' => 'pending']),
        ];
        $this->paystackClient->method('verifyTransaction')->willReturn($verifyResponse);
        $this->configProvider->method('getPublicKey')->willReturn('pk_test');

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('ORDER_PENDING');
        $order->expects($this->never())->method('addStatusToHistory');
        $this->orderInterface->method('loadByIncrementId')->willReturn($order);

        $this->eventManager->expects($this->never())->method('dispatch');
        $this->orderRepository->expects($this->never())->method('save');

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(503);
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('pending');

        $this->controller->execute();
    }

    /**
     * `REASON_MALFORMED` means we could not read the gateway's answer (verify
     * returned a body with the reference present but the amount unreadable) — the
     * textbook transient case. A permanent 200 here would strand a real payment with
     * no retry and no merchant trace, so this must be 503, not 200. The deny-list
     * history write still fires (MALFORMED is not IN_FLIGHT), so the merchant gets
     * visibility even while Paystack is retrying.
     */
    public function testMalformedVerifyResponseReturns503WithHistory(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'success',
                'reference' => 'ORDER_060',
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $verifyResponse = (object) [
            'data' => (object) $this->settledVerifyData('ORDER_060', ['amount' => null]),
        ];
        $this->paystackClient->method('verifyTransaction')->willReturn($verifyResponse);
        $this->configProvider->method('getPublicKey')->willReturn('pk_test');

        $order = $this->createSettledOrder('ORDER_060');
        $order->expects($this->once())
            ->method('addStatusToHistory')
            ->with('pending', $this->stringContains('malformed'));
        $this->orderInterface->method('loadByIncrementId')->willReturn($order);

        $this->orderRepository->expects($this->once())->method('save')->with($order);
        $this->eventManager->expects($this->never())->method('dispatch');

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(503);
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('unverified');

        $this->controller->execute();
    }

    /**
     * `REASON_WRONG_METHOD` is derived from `$order->getPayment()`, a lazily-loaded
     * relation, not a row invariant — a permanent rejection here could burn a genuine
     * payment if it is ever observed unhydrated. Transient (503), with the deny-list
     * history write still firing so the merchant is not left without a trace.
     */
    public function testWrongPaymentMethodReturns503WithHistory(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'success',
                'reference' => 'ORDER_050',
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $verifyResponse = (object) [
            'data' => (object) $this->settledVerifyData('ORDER_050'),
        ];
        $this->paystackClient->method('verifyTransaction')->willReturn($verifyResponse);
        $this->configProvider->method('getPublicKey')->willReturn('pk_test');

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('ORDER_050');
        $order->method('getStatus')->willReturn('pending');
        $order->method('getGrandTotal')->willReturn(5000.00);
        $order->method('getOrderCurrencyCode')->willReturn('NGN');
        $wrongPayment = $this->createMock(\Magento\Sales\Model\Order\Payment::class);
        $wrongPayment->method('getMethod')->willReturn('checkmo');
        $order->method('getPayment')->willReturn($wrongPayment);
        $order->expects($this->once())
            ->method('addStatusToHistory')
            ->with('pending', $this->stringContains('wrong_method'));
        $this->orderInterface->method('loadByIncrementId')->willReturn($order);

        $this->orderRepository->expects($this->once())->method('save')->with($order);
        $this->eventManager->expects($this->never())->method('dispatch');

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(503);
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('unverified');

        $this->controller->execute();
    }

    /**
     * A signed `charge.success` with no `data.reference` cannot be re-verified at
     * all — `verifyTransaction()` must never be called with it, and the `?? null`
     * guard must reject it before any PHP error can escape.
     */
    public function testChargeSuccessWithNoEventReferenceReturnsInvalidPayload(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'success',
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $this->paystackClient->expects($this->never())->method('verifyTransaction');
        $this->eventManager->expects($this->never())->method('dispatch');

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(200);
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('invalid payload');

        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        }, E_WARNING | E_NOTICE | E_DEPRECATED);

        try {
            $this->controller->execute();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings, 'Missing data.reference must not emit PHP warnings/notices.');
    }

    /**
     * The verify response itself can come back with no readable `data.reference`
     * (the gateway's answer, not our payload) — `logTransactionSuccess()` takes a
     * non-nullable string, so it must never be called with the null it would
     * otherwise receive. Transient (503): a retry may get a readable response.
     */
    public function testVerifyResponseWithNoReferenceReturns503WithoutCallingLogSuccess(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'success',
                'reference' => 'ORDER_070',
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $verifyResponse = (object) [
            'data' => (object) [
                'status' => 'success',
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ];
        $this->paystackClient->method('verifyTransaction')->willReturn($verifyResponse);

        $this->paystackClient->expects($this->never())->method('logTransactionSuccess');
        $this->eventManager->expects($this->never())->method('dispatch');

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(503);
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('unverified');

        $this->controller->execute();
    }

    /**
     * Order-not-found is bounded by transaction recency, not treated as transient
     * forever: a recent `paid_at` still gets the retry window.
     */
    public function testOrderNotFoundWithRecentPaidAtReturns503(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'success',
                'reference' => 'ORDER_080',
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $verifyResponse = (object) [
            'data' => (object) $this->settledVerifyData('ORDER_080', ['paid_at' => date('c')]),
        ];
        $this->paystackClient->method('verifyTransaction')->willReturn($verifyResponse);
        $this->configProvider->method('getPublicKey')->willReturn('pk_test');

        $emptyOrder = $this->createMock(\Magento\Sales\Model\Order::class);
        $emptyOrder->method('getId')->willReturn(null);
        $this->orderInterface->method('loadByIncrementId')->willReturn($emptyOrder);

        $this->eventManager->expects($this->never())->method('dispatch');
        $this->orderRepository->expects($this->never())->method('save');

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(503);
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('order not found');

        $this->controller->execute();
    }

    /**
     * A `paid_at` outside the retry window means order-not-found is treated as
     * permanent (200) — this is what protects the webhook endpoint from Paystack
     * backing it off/disabling it over events that will never have a Magento order
     * (Payment Pages, dashboard charges, subscription renewals, another integration
     * on the same account).
     */
    public function testOrderNotFoundWithStalePaidAtReturns200(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'success',
                'reference' => 'ORDER_090',
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $verifyResponse = (object) [
            'data' => (object) $this->settledVerifyData('ORDER_090', ['paid_at' => date('c', time() - 7200)]),
        ];
        $this->paystackClient->method('verifyTransaction')->willReturn($verifyResponse);
        $this->configProvider->method('getPublicKey')->willReturn('pk_test');

        $emptyOrder = $this->createMock(\Magento\Sales\Model\Order::class);
        $emptyOrder->method('getId')->willReturn(null);
        $this->orderInterface->method('loadByIncrementId')->willReturn($emptyOrder);

        $this->eventManager->expects($this->never())->method('dispatch');
        $this->orderRepository->expects($this->never())->method('save');

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(200);
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('order not found');

        $this->controller->execute();
    }

    /**
     * isRecentTransaction() reads `data.paid_at`, falling back to `data.created_at`
     * when `paid_at` is absent — this pins that fallback specifically, not just the
     * `paid_at`-present cases above.
     */
    public function testOrderNotFoundFallsBackToCreatedAtWhenPaidAtMissing(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'success',
                'reference' => 'ORDER_091',
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        // No paid_at at all; created_at is recent, so the fallback must still
        // yield a transient (503), not a permanent (200), classification.
        $verifyResponse = (object) [
            'data' => (object) $this->settledVerifyData('ORDER_091', ['created_at' => date('c')]),
        ];
        $this->paystackClient->method('verifyTransaction')->willReturn($verifyResponse);
        $this->configProvider->method('getPublicKey')->willReturn('pk_test');

        $emptyOrder = $this->createMock(\Magento\Sales\Model\Order::class);
        $emptyOrder->method('getId')->willReturn(null);
        $this->orderInterface->method('loadByIncrementId')->willReturn($emptyOrder);

        $this->eventManager->expects($this->never())->method('dispatch');
        $this->orderRepository->expects($this->never())->method('save');

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(503);
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('order not found');

        $this->controller->execute();
    }

    /**
     * A stale `created_at` (no `paid_at` at all) must be treated as permanent
     * (200), exactly like a stale `paid_at` — the fallback is not just read, its
     * value is actually compared against the retry window.
     */
    public function testOrderNotFoundWithStaleCreatedAtAndNoPaidAtReturns200(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'success',
                'reference' => 'ORDER_092',
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $verifyResponse = (object) [
            'data' => (object) $this->settledVerifyData('ORDER_092', ['created_at' => date('c', time() - 7200)]),
        ];
        $this->paystackClient->method('verifyTransaction')->willReturn($verifyResponse);
        $this->configProvider->method('getPublicKey')->willReturn('pk_test');

        $emptyOrder = $this->createMock(\Magento\Sales\Model\Order::class);
        $emptyOrder->method('getId')->willReturn(null);
        $this->orderInterface->method('loadByIncrementId')->willReturn($emptyOrder);

        $this->eventManager->expects($this->never())->method('dispatch');
        $this->orderRepository->expects($this->never())->method('save');

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(200);
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('order not found');

        $this->controller->execute();
    }

    /**
     * A `paid_at` value strtotime() cannot parse must not permanently strand a
     * genuine payment — isRecentTransaction() treats an unparseable timestamp as
     * recent (503, retry), the same fail-open behavior as a missing timestamp.
     */
    public function testOrderNotFoundWithUnparseablePaidAtReturns503(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'success',
                'reference' => 'ORDER_093',
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $verifyResponse = (object) [
            'data' => (object) $this->settledVerifyData('ORDER_093', ['paid_at' => 'not-a-real-timestamp']),
        ];
        $this->paystackClient->method('verifyTransaction')->willReturn($verifyResponse);
        $this->configProvider->method('getPublicKey')->willReturn('pk_test');

        $emptyOrder = $this->createMock(\Magento\Sales\Model\Order::class);
        $emptyOrder->method('getId')->willReturn(null);
        $this->orderInterface->method('loadByIncrementId')->willReturn($emptyOrder);

        $this->eventManager->expects($this->never())->method('dispatch');
        $this->orderRepository->expects($this->never())->method('save');

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(503);
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('order not found');

        $this->controller->execute();
    }

    public function testApiExceptionDuringVerifyReturns503WithoutLeakingMessage(): void
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
            ->method('setHttpResponseCode')
            ->with(503);
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with($this->callback(function ($val) {
                return is_string($val) && strpos($val, 'API down') === false;
            }));

        $this->controller->execute();
    }

    /**
     * D6 on the webhook path: a `success` status alone is not settlement — short by a
     * single subunit must reject and record the merchant-visible history comment.
     */
    public function testAmountShortByOneSubunitReturnsRejectedWithHistory(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'success',
                'reference' => 'ORDER_010',
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $verifyResponse = (object) [
            'data' => (object) $this->settledVerifyData('ORDER_010', ['amount' => 499999]),
        ];
        $this->paystackClient->method('verifyTransaction')->willReturn($verifyResponse);
        $this->configProvider->method('getPublicKey')->willReturn('pk_test');

        $order = $this->createSettledOrder('ORDER_010');
        $order->expects($this->once())
            ->method('addStatusToHistory')
            ->with('pending', $this->stringContains('amount_mismatch'));
        $this->orderInterface->method('loadByIncrementId')->willReturn($order);

        $this->orderRepository->expects($this->once())->method('save')->with($order);
        $this->eventManager->expects($this->never())->method('dispatch');
        // logTransactionSuccess() only fires once the settlement gate passes —
        // a rejected settlement must not tell Paystack's plugin tracker that a
        // charge succeeded.
        $this->paystackClient->expects($this->never())->method('logTransactionSuccess');

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(200);
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('rejected');

        $this->controller->execute();
    }

    /**
     * A `success` status paid in the wrong currency does not settle an order priced
     * in another currency, however close the numbers look.
     */
    public function testCurrencyMismatchReturnsRejectedWithHistory(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'success',
                'reference' => 'ORDER_020',
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $verifyResponse = (object) [
            'data' => (object) $this->settledVerifyData('ORDER_020', ['currency' => 'USD']),
        ];
        $this->paystackClient->method('verifyTransaction')->willReturn($verifyResponse);
        $this->configProvider->method('getPublicKey')->willReturn('pk_test');

        $order = $this->createSettledOrder('ORDER_020');
        $order->expects($this->once())
            ->method('addStatusToHistory')
            ->with('pending', $this->stringContains('currency_mismatch'));
        $this->orderInterface->method('loadByIncrementId')->willReturn($order);

        $this->orderRepository->expects($this->once())->method('save')->with($order);
        $this->eventManager->expects($this->never())->method('dispatch');

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(200);
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('rejected');

        $this->controller->execute();
    }

    /**
     * Overpayment by a single subunit still settles the order — required for
     * Paystack's customer-bears-fee configuration — but the surplus is recorded so
     * the merchant has visibility into it.
     */
    public function testOverpayByOneSubunitDispatchesAndRecordsHistory(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'success',
                'reference' => 'ORDER_030',
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $verifyResponse = (object) [
            'data' => (object) $this->settledVerifyData('ORDER_030', ['amount' => 500001]),
        ];
        $this->paystackClient->method('verifyTransaction')->willReturn($verifyResponse);
        $this->configProvider->method('getPublicKey')->willReturn('pk_test');

        $order = $this->createSettledOrder('ORDER_030');
        $order->expects($this->once())
            ->method('addStatusToHistory')
            ->with('pending', $this->stringContains('overpaid'));
        $this->orderInterface->method('loadByIncrementId')->willReturn($order);

        $this->orderRepository->expects($this->once())->method('save')->with($order);
        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with('paystack_payment_verify_after', ['paystack_order' => $order]);

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(200);
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('success');

        $this->controller->execute();
    }

    public function testOrderNotFoundByEitherLookupReturns503(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'success',
                'reference' => 'ORDER_404',
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $verifyResponse = (object) [
            'data' => (object) $this->settledVerifyData('ORDER_404'),
        ];
        $this->paystackClient->method('verifyTransaction')->willReturn($verifyResponse);
        $this->configProvider->method('getPublicKey')->willReturn('pk_test');

        // No metadata.quoteId, so the fallback lookup is never attempted.
        $emptyOrder = $this->createMock(\Magento\Sales\Model\Order::class);
        $emptyOrder->method('getId')->willReturn(null);
        $this->orderInterface->method('loadByIncrementId')->willReturn($emptyOrder);

        $this->eventManager->expects($this->never())->method('dispatch');
        $this->orderRepository->expects($this->never())->method('save');

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(503);
        // Without this, a code path that throws (and is swallowed by the outer
        // \Throwable catch, which also sets 503) would satisfy the response-code
        // assertion above for the wrong reason — this is the only thing that
        // actually discriminates "order not found, recent" from "an exception
        // occurred and got caught".
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('order not found');

        $this->controller->execute();
    }

    /**
     * A signed-but-malformed payload (`data` present, `status` missing) must take a
     * clean rejection branch — never call verify or dispatch, and never emit a PHP
     * warning while doing it.
     */
    /**
     * @dataProvider malformedSignedPayloadProvider
     */
    public function testSignedMalformedEventDataIsCleanlyRejected(array $data): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => $data,
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $this->paystackClient->expects($this->never())->method('verifyTransaction');
        $this->eventManager->expects($this->never())->method('dispatch');

        // The `?? null` guards exist precisely so a malformed payload never touches an
        // undefined property. Fail the test on the PHP warning that regression would
        // reintroduce, rather than trusting the mock expectations alone to catch it.
        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        }, E_WARNING | E_NOTICE | E_DEPRECATED);

        try {
            $this->controller->execute();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings, 'Malformed webhook payload must not emit PHP warnings/notices.');
    }

    public static function malformedSignedPayloadProvider(): array
    {
        return [
            'status missing' => [['reference' => 'ORDER_012']],
        ];
    }

    /**
     * `data` absent from the event entirely, or explicitly `null`, are the shapes that
     * most stress the `$event->data->status ?? null` chain — no intermediate object
     * exists at all, not just a missing property on one.
     *
     * @dataProvider missingDataPropertyProvider
     */
    public function testSignedPayloadWithNoDataPropertyIsCleanlyRejected(array $eventPayload): void
    {
        $rawBody = json_encode($eventPayload);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $this->paystackClient->expects($this->never())->method('verifyTransaction');
        $this->eventManager->expects($this->never())->method('dispatch');

        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        }, E_WARNING | E_NOTICE | E_DEPRECATED);

        try {
            $this->controller->execute();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings, 'A webhook payload with no usable `data` property must not emit PHP warnings/notices.');
    }

    public static function missingDataPropertyProvider(): array
    {
        return [
            'data key absent entirely' => [['event' => 'charge.success']],
            'data explicitly null' => [['event' => 'charge.success', 'data' => null]],
        ];
    }

    /**
     * The signature-verified path writes a merchant-visible history comment on
     * rejection. If that write itself throws — including a bare `\Error`, not just
     * `\Exception` — it must not turn a clean permanent rejection into a 503: Paystack
     * would retry a rejection retrying can never fix.
     *
     * @dataProvider historyWriteFailureProvider
     */
    public function testHistoryWriteFailureDoesNotTurnRejectionIntoRetry(\Throwable $thrown): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'success',
                'reference' => 'ORDER_040',
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $verifyResponse = (object) [
            'data' => (object) $this->settledVerifyData('ORDER_040', ['amount' => 499999]),
        ];
        $this->paystackClient->method('verifyTransaction')->willReturn($verifyResponse);
        $this->configProvider->method('getPublicKey')->willReturn('pk_test');

        $order = $this->createSettledOrder('ORDER_040');
        $this->orderInterface->method('loadByIncrementId')->willReturn($order);
        $this->orderRepository->method('save')->willThrowException($thrown);

        $this->eventManager->expects($this->never())->method('dispatch');

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(200);
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('rejected');

        $this->controller->execute();
    }

    public static function historyWriteFailureProvider(): array
    {
        return [
            'RuntimeException from save()' => [new \RuntimeException('deadlock')],
            'TypeError-shaped failure' => [new \TypeError('unexpected type')],
        ];
    }

    /**
     * WRONG_METHOD and MALFORMED are transient (503) and on the history
     * deny-list, so Paystack's ~72h retry window redelivers the exact same
     * event repeatedly. recordHistory() must not append a duplicate comment on
     * a second delivery it has already recorded a comment for — this is the
     * regression test for that amplification.
     */
    public function testDuplicateWebhookDeliveryWritesHistoryCommentOnlyOnce(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'status' => 'success',
                'reference' => 'ORDER_045',
            ],
        ]);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn('valid_sig');
        $this->paystackClient->method('validateWebhookSignature')->willReturn(true);

        $verifyResponse = (object) [
            'data' => (object) $this->settledVerifyData('ORDER_045', ['amount' => 499999]),
        ];
        $this->paystackClient->method('verifyTransaction')->willReturn($verifyResponse);
        $this->configProvider->method('getPublicKey')->willReturn('pk_test');

        $order = $this->createSettledOrder('ORDER_045');

        // Simulate a prior delivery of this exact event having already written
        // the rejection comment for this (reference, reason) pair.
        $existingHistory = $this->createMock(\Magento\Sales\Api\Data\OrderStatusHistoryInterface::class);
        $existingHistory->method('getComment')->willReturn(
            'Paystack: payment rejected — amount_mismatch: paid 499999 NGN, expected 500000 NGN, reference ORDER_045'
        );
        $order->method('getStatusHistories')->willReturn([$existingHistory]);

        $order->expects($this->never())->method('addStatusToHistory');
        $this->orderInterface->method('loadByIncrementId')->willReturn($order);

        $this->orderRepository->expects($this->never())->method('save');
        $this->eventManager->expects($this->never())->method('dispatch');

        $this->rawResult->expects($this->atLeastOnce())
            ->method('setHttpResponseCode')
            ->with(200);
        $this->rawResult->expects($this->atLeastOnce())
            ->method('setContents')
            ->with('rejected');

        $this->controller->execute();
    }
}
