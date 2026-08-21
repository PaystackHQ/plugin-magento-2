<?php

namespace Pstk\Paystack\Test\Unit\Controller\Payment;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Pstk\Paystack\Controller\Payment\Callback;
use Pstk\Paystack\Gateway\PaystackApiClient;
use Pstk\Paystack\Gateway\Exception\ApiException;
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

    /** @var MockObject|LoggerInterface */
    private $logger;

    private function createController(): Callback
    {
        $this->paystackClient = $this->createMock(PaystackApiClient::class);
        $this->eventManager = $this->createMock(EventManager::class);
        $this->request = $this->createMock(HttpRequest::class);
        $this->orderInterface = $this->createMock(\Magento\Sales\Model\Order::class);
        $this->messageManager = $this->createMock(MessageManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

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
            $this->logger,
            $this->paystackClient,
            new TransactionValidator($this->createMock(LoggerInterface::class))
        );
    }

    /**
     * A settled order: same payment method, currency and grand total the
     * matching-currency `settledVerifyData()` amount pays for in full.
     */
    private function createSettledOrder(string $incrementId): MockObject
    {
        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getIncrementId')->willReturn($incrementId);

        $payment = $this->createMock(\Magento\Sales\Model\Order\Payment::class);
        $payment->method('getMethod')->willReturn(\Pstk\Paystack\Model\Payment\Paystack::CODE);
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

    public function testSuccessfulCallbackDispatchesEvent(): void
    {
        $controller = $this->createController();

        $this->request->method('get')
            ->with('reference')
            ->willReturn('000000001_suffix');

        $verifyResponse = (object) [
            'data' => (object) $this->settledVerifyData('000000001_suffix'),
        ];
        $this->paystackClient->method('verifyTransaction')
            ->with('000000001_suffix')
            ->willReturn($verifyResponse);

        $order = $this->createSettledOrder('000000001');
        $this->orderInterface->method('loadByIncrementId')
            ->with('000000001')
            ->willReturn($order);

        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with('paystack_payment_verify_after', ['paystack_order' => $order]);

        $controller->execute();
    }

    /**
     * The load-bearing half of the gate: which order is settled comes from Paystack's
     * reply, never from the caller's query string. With both set to the same value a
     * refactor could swap them silently, so here they differ.
     */
    public function testOrderIsLoadedFromTheVerifyResponseNotTheRequest(): void
    {
        $controller = $this->createController();

        $this->request->method('get')->willReturn('000000099_attacker');

        $this->paystackClient->method('verifyTransaction')
            ->with('000000099_attacker')
            ->willReturn((object) ['data' => (object) $this->settledVerifyData('000000001_suffix')]);

        $order = $this->createSettledOrder('000000001');

        $this->orderInterface->expects($this->once())
            ->method('loadByIncrementId')
            ->with('000000001')
            ->willReturn($order);

        $this->eventManager->expects($this->once())->method('dispatch');

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

    /**
     * D5: the callback route is anonymous, so an unverified transaction must never
     * advance an order. Before this gate existed, any reference at all — including
     * one whose transaction was abandoned and never paid for — dispatched the event
     * and flipped the order to Processing.
     *
     * @dataProvider nonSuccessStatusProvider
     */
    public function testNonSuccessfulTransactionDoesNotDispatchEvent($hasStatus, $status): void
    {
        $controller = $this->createController();

        $this->request->method('get')->willReturn('000000001');

        $data = ['reference' => '000000001'];
        if ($hasStatus) {
            $data['status'] = $status;
        }
        $this->paystackClient->method('verifyTransaction')
            ->willReturn((object) ['data' => (object) $data]);

        // The order exists and its increment ID matches, so the only thing standing
        // between this request and an advanced order is the status gate.
        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getIncrementId')->willReturn('000000001');
        $this->orderInterface->method('loadByIncrementId')->willReturn($order);

        $this->eventManager->expects($this->never())->method('dispatch');
        $this->messageManager->expects($this->once())->method('addErrorMessage');
        // The other half of the observable: the customer must not be told it worked.
        $this->messageManager->expects($this->never())->method('addSuccessMessage');

        $controller->execute();
    }

    public static function nonSuccessStatusProvider(): array
    {
        return [
            'abandoned' => [true, 'abandoned'],
            'failed' => [true, 'failed'],
            'pending' => [true, 'pending'],
            'reversed' => [true, 'reversed'],
            'no status field at all' => [false, null],
        ];
    }

    /**
     * A verify response with no `data` object at all must fail closed too — this is what
     * stops a later refactor from reading the status through anything but `??`.
     */
    public function testResponseWithoutDataIsRejected(): void
    {
        $controller = $this->createController();

        $this->request->method('get')->willReturn('000000001');
        $this->paystackClient->method('verifyTransaction')
            ->willReturn((object) ['status' => true, 'message' => 'Verification successful']);

        $this->eventManager->expects($this->never())->method('dispatch');
        $this->messageManager->expects($this->never())->method('addSuccessMessage');

        $controller->execute();
    }

    /**
     * The generic catch was `catch (Exception $e)` — unqualified in a namespaced file
     * with no `use`, so it caught nothing and anything but an ApiException escaped as
     * a 500. It is `\Throwable` now.
     */
    public function testUnexpectedThrowableIsCaughtAndDoesNotDispatch(): void
    {
        $controller = $this->createController();

        $this->request->method('get')->willReturn('000000001');

        $this->paystackClient->method('verifyTransaction')
            ->willThrowException(new \RuntimeException('connection reset'));

        $this->eventManager->expects($this->never())->method('dispatch');
        $this->messageManager->expects($this->once())->method('addErrorMessage');
        $this->messageManager->expects($this->never())->method('addSuccessMessage');

        $controller->execute();
    }

    /**
     * The customer-facing message must not carry internal exception detail.
     */
    public function testThrowableMessageIsNotShownToTheCustomer(): void
    {
        $controller = $this->createController();

        $this->request->method('get')->willReturn('000000001');

        $this->paystackClient->method('verifyTransaction')
            ->willThrowException(new \RuntimeException('SQLSTATE[HY000] internal detail'));

        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->callback(function ($message) {
                return strpos((string) $message, 'internal detail') === false;
            }));

        $controller->execute();
    }

    /**
     * `pending`/`ongoing`/`queued` are in-flight (bank transfer, USSD), not failed. The
     * customer must not be told to try again while a charge is on its way.
     *
     * @dataProvider inFlightStatusProvider
     */
    public function testInFlightTransactionDoesNotInviteASecondPayment($status): void
    {
        $controller = $this->createController();

        $this->request->method('get')->willReturn('000000001');
        $this->paystackClient->method('verifyTransaction')
            ->willReturn((object) ['data' => (object) [
                'reference' => '000000001',
                'status' => $status,
            ]]);

        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->callback(function ($message) {
                return stripos((string) $message, 'do not pay again') !== false;
            }));

        $controller->execute();
    }

    public static function inFlightStatusProvider(): array
    {
        return [['pending'], ['ongoing'], ['queued']];
    }

    /**
     * ApiException messages are built from curl_error() and Paystack's raw response, so
     * reflecting them leaks internal detail and turns this anonymous route into an
     * oracle for which references and increment IDs exist.
     */
    public function testApiExceptionMessageIsNotShownToTheCustomer(): void
    {
        $controller = $this->createController();

        $this->request->method('get')->willReturn('000000001');
        $this->paystackClient->method('verifyTransaction')
            ->willThrowException(new ApiException('Transaction reference not found'));

        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->callback(function ($message) {
                return stripos((string) $message, 'reference not found') === false;
            }));

        $controller->execute();
    }

    /**
     * The observer saves the order before it can throw, so a throwable raised after the
     * dispatch leaves a paid, advanced order — reporting failure there would invite a
     * second payment for it.
     */
    public function testThrowableAfterDispatchDoesNotReportFailure(): void
    {
        $controller = $this->createController();

        $this->request->method('get')->willReturn('000000001');
        $this->paystackClient->method('verifyTransaction')
            ->willReturn((object) ['data' => (object) $this->settledVerifyData('000000001')]);

        $order = $this->createSettledOrder('000000001');
        $this->orderInterface->method('loadByIncrementId')->willReturn($order);

        $this->eventManager->method('dispatch')
            ->willThrowException(new \RuntimeException('observer blew up after saving'));

        $this->messageManager->expects($this->never())->method('addErrorMessage');
        $this->messageManager->expects($this->once())
            ->method('addWarningMessage')
            ->with($this->callback(function ($message) {
                return stripos((string) $message, 'do not pay again') !== false;
            }));

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

    /**
     * D6: a `success` status alone is not settlement — the amount paid must cover the
     * order. Short by a single subunit must fail closed exactly like the D5 status gate,
     * and must never reach the "payment received" warning branch at :127.
     */
    public function testAmountShortByOneSubunitDoesNotDispatchEvent(): void
    {
        $controller = $this->createController();

        $this->request->method('get')->willReturn('000000001');
        $this->paystackClient->method('verifyTransaction')
            ->willReturn((object) ['data' => (object) $this->settledVerifyData('000000001', [
                'amount' => 499999,
            ])]);

        $order = $this->createSettledOrder('000000001');
        $this->orderInterface->method('loadByIncrementId')->willReturn($order);

        $this->eventManager->expects($this->never())->method('dispatch');
        // AMOUNT_MISMATCH means the payment DID complete, just not for enough —
        // asserting the copy is the regression test for the bug where this
        // branch used to say "was not completed", which is false here.
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->callback(function ($m) {
                return str_contains((string) $m, 'do not pay again');
            }));
        $this->messageManager->expects($this->never())->method('addSuccessMessage');
        $this->messageManager->expects($this->never())->method('addWarningMessage');

        $controller->execute();
    }

    /**
     * A `success` status paid in the wrong currency does not settle an order priced in
     * another currency, however close the numbers look.
     */
    public function testCurrencyMismatchDoesNotDispatchEvent(): void
    {
        $controller = $this->createController();

        $this->request->method('get')->willReturn('000000001');
        $this->paystackClient->method('verifyTransaction')
            ->willReturn((object) ['data' => (object) $this->settledVerifyData('000000001', [
                'currency' => 'USD',
            ])]);

        $order = $this->createSettledOrder('000000001');
        $this->orderInterface->method('loadByIncrementId')->willReturn($order);

        $this->eventManager->expects($this->never())->method('dispatch');
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->callback(function ($m) {
                return str_contains((string) $m, 'do not pay again');
            }));
        $this->messageManager->expects($this->never())->method('addSuccessMessage');
        $this->messageManager->expects($this->never())->method('addWarningMessage');

        $controller->execute();
    }

    /**
     * A charge settled against an order that was not placed with Paystack must not
     * advance it — narrows the surface a stray/forged reference can act on.
     */
    public function testWrongPaymentMethodDoesNotDispatchEvent(): void
    {
        $controller = $this->createController();

        $this->request->method('get')->willReturn('000000001');
        $this->paystackClient->method('verifyTransaction')
            ->willReturn((object) ['data' => (object) $this->settledVerifyData('000000001')]);

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getIncrementId')->willReturn('000000001');
        $payment = $this->createMock(\Magento\Sales\Model\Order\Payment::class);
        $payment->method('getMethod')->willReturn('checkmo');
        $order->method('getPayment')->willReturn($payment);
        $order->method('getGrandTotal')->willReturn(5000.00);
        $order->method('getOrderCurrencyCode')->willReturn('NGN');
        $this->orderInterface->method('loadByIncrementId')->willReturn($order);

        $this->eventManager->expects($this->never())->method('dispatch');
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->callback(function ($m) {
                return str_contains((string) $m, 'do not pay again');
            }));
        $this->messageManager->expects($this->never())->method('addSuccessMessage');
        $this->messageManager->expects($this->never())->method('addWarningMessage');

        $controller->execute();
    }

    /**
     * A settled-looking `success` status against a zero/negative expected or paid
     * total must not settle the order — the fourth settlement-gate reason,
     * completing copy coverage for all of customerMessage()'s default-bucket
     * reasons through the callback path.
     */
    public function testZeroTotalDoesNotDispatchEvent(): void
    {
        $controller = $this->createController();

        $this->request->method('get')->willReturn('000000001');
        $this->paystackClient->method('verifyTransaction')
            ->willReturn((object) ['data' => (object) $this->settledVerifyData('000000001')]);

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getIncrementId')->willReturn('000000001');
        $payment = $this->createMock(\Magento\Sales\Model\Order\Payment::class);
        $payment->method('getMethod')->willReturn(\Pstk\Paystack\Model\Payment\Paystack::CODE);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getGrandTotal')->willReturn(0.00);
        $order->method('getOrderCurrencyCode')->willReturn('NGN');
        $this->orderInterface->method('loadByIncrementId')->willReturn($order);

        $this->eventManager->expects($this->never())->method('dispatch');
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->callback(function ($m) {
                return str_contains((string) $m, 'do not pay again');
            }));
        $this->messageManager->expects($this->never())->method('addSuccessMessage');
        $this->messageManager->expects($this->never())->method('addWarningMessage');

        $controller->execute();
    }

    /**
     * Overpayment by a single subunit still settles the order — required for
     * Paystack's customer-bears-fee configuration, where `data.amount` includes the
     * fee on top of the order total. The `paid >= expected` window passes.
     */
    public function testOverpayByOneSubunitStillDispatchesEvent(): void
    {
        $controller = $this->createController();

        $this->request->method('get')->willReturn('000000001');
        $this->paystackClient->method('verifyTransaction')
            ->willReturn((object) ['data' => (object) $this->settledVerifyData('000000001', [
                'amount' => 500001,
            ])]);

        $order = $this->createSettledOrder('000000001');
        $this->orderInterface->method('loadByIncrementId')->willReturn($order);

        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with('paystack_payment_verify_after', ['paystack_order' => $order]);

        $controller->execute();
    }
}
