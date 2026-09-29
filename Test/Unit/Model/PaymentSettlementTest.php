<?php

namespace Pstk\Paystack\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Pstk\Paystack\Model\PaymentSettlement;
use Pstk\Paystack\Gateway\Validator\TransactionValidator;
use Pstk\Paystack\Model\Payment\Paystack;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Api\Data\TransactionSearchResultInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Psr\Log\LoggerInterface;

/**
 * Ported from this session's abandoned, mutation-tested `PaymentVerificationTest`
 * (git commit fc1b67e) onto `PaymentSettlement::register()`'s method name/
 * signature — that class's binding-check and stale-order-refetch tests are the
 * reference implementation for test quality/coverage here; this suite adapts
 * them to `settlementFailureReason()`'s `?string` reason contract (rather than
 * the abandoned class's `validate()` array-of-errors contract) and adds the
 * cases specific to this reconciliation: the compound idempotency key, the
 * `OrderInterface`-but-not-concrete-`Order` guard, and the order-state guard's
 * position after the idempotency short-circuit.
 */
class PaymentSettlementTest extends TestCase
{
    /** @var MockObject|OrderRepositoryInterface */
    private $orderRepository;

    /** @var MockObject|TransactionRepositoryInterface */
    private $transactionRepository;

    /** @var MockObject|SearchCriteriaBuilder */
    private $searchCriteriaBuilder;

    /** @var MockObject|LoggerInterface */
    private $logger;

    /** @var PaymentSettlement */
    private $paymentSettlement;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn(
            $this->createMock(SearchCriteriaInterface::class)
        );

        $this->paymentSettlement = new PaymentSettlement(
            new TransactionValidator($this->createMock(LoggerInterface::class)),
            $this->orderRepository,
            $this->transactionRepository,
            $this->searchCriteriaBuilder,
            $this->logger
        );
    }

    /**
     * @return MockObject|TransactionSearchResultInterface
     */
    private function searchResult(array $items)
    {
        $result = $this->createMock(TransactionSearchResultInterface::class);
        $result->method('getItems')->willReturn($items);

        return $result;
    }

    private function transactionItem(int $orderId): MockObject
    {
        $item = $this->createMock(TransactionInterface::class);
        $item->method('getOrderId')->willReturn($orderId);

        return $item;
    }

    private function makeVerifyData(array $overrides = []): object
    {
        return (object) array_merge([
            'status' => 'success',
            'currency' => 'NGN',
            'amount' => 10000,
            'domain' => 'test',
            'reference' => 'PSK_ref_123',
        ], $overrides);
    }

    /**
     * @return MockObject|Order The order the caller passes to register() —
     *     `orderRepository->get()` is wired to return a DISTINCT fresh mock
     *     (see below), so reject/success-path assertions are made against the
     *     actual instance register() writes/saves, not this stale parameter.
     */
    private function makeOrder(
        ?Payment $payment,
        int $entityId = 1,
        string $state = Order::STATE_NEW,
        float $baseTotalDue = 100.00
    ): MockObject {
        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn($entityId);
        $order->method('getIncrementId')->willReturn('000000001');
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn($state);
        $order->method('getBaseTotalDue')->willReturn($baseTotalDue);
        $order->method('getGrandTotal')->willReturn($baseTotalDue);
        $order->method('getOrderCurrencyCode')->willReturn('NGN');

        $freshOrder = $this->createMock(Order::class);
        $freshOrder->method('getEntityId')->willReturn($entityId);
        $freshOrder->method('getIncrementId')->willReturn('000000001');
        $freshOrder->method('getPayment')->willReturn($payment);
        $freshOrder->method('getState')->willReturn($state);
        $freshOrder->method('getBaseTotalDue')->willReturn($baseTotalDue);
        $freshOrder->method('getGrandTotal')->willReturn($baseTotalDue);
        $freshOrder->method('getOrderCurrencyCode')->willReturn('NGN');
        $this->orderRepository->method('get')->with($entityId)->willReturn($freshOrder);

        return $order;
    }

    private function makePaystackPayment(): MockObject
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(Paystack::CODE);

        return $payment;
    }

    private function noExistingBindings(): void
    {
        $this->transactionRepository->method('getList')->willReturn($this->searchResult([]));
    }

    public function testSuccessfulRegistrationBindsAndCaptures(): void
    {
        $this->noExistingBindings();

        $order = $this->makeOrder($this->makePaystackPayment(), 1, Order::STATE_NEW, 100.00);
        $freshOrder = $this->orderRepository->get(1);

        $freshOrder->getPayment()->expects($this->once())
            ->method('setTransactionId')
            ->with('PSK_ref_123');
        $freshOrder->getPayment()->expects($this->once())
            ->method('registerCaptureNotification')
            ->with(100.00, true);
        $this->orderRepository->expects($this->once())->method('save')->with($freshOrder);

        $result = $this->paymentSettlement->register(
            (object) ['data' => $this->makeVerifyData()],
            $order,
            true
        );

        $this->assertNull($result['reason']);
        $this->assertTrue($result['historyRecorded']);
        // The settled instance a caller must dispatch/reference — not the
        // stale $order it called register() with.
        $this->assertSame($freshOrder, $result['order']);
    }

    public function testSettlementFailureIsRejectedAndRecordedAgainstFreshOrder(): void
    {
        $this->noExistingBindings();

        $wrongPayment = $this->createMock(Payment::class);
        $wrongPayment->method('getMethod')->willReturn('checkmo');

        $order = $this->makeOrder($wrongPayment);
        $freshOrder = $this->orderRepository->get(1);

        // The reject path writes against the fresh instance, not the stale
        // caller-supplied $order.
        $order->expects($this->never())->method('addStatusToHistory');
        $freshOrder->expects($this->once())
            ->method('addStatusToHistory')
            ->with($this->anything(), $this->stringContains('wrong_method'));
        $this->orderRepository->expects($this->once())->method('save')->with($freshOrder);

        $result = $this->paymentSettlement->register(
            (object) ['data' => $this->makeVerifyData()],
            $order,
            true
        );

        $this->assertSame(TransactionValidator::REASON_WRONG_METHOD, $result['reason']);
        $this->assertTrue($result['historyRecorded']);
        $this->assertSame($freshOrder, $result['order']);
    }

    /**
     * REASON_IN_FLIGHT means the charge is still settling, not rejected — no
     * merchant-visible history comment on every retry of an event that
     * resolves on its own (deny-list behavior carried over from Webhook.php's
     * original recordHistory()).
     */
    public function testInFlightReasonIsReturnedWithoutRecordingHistory(): void
    {
        $order = $this->makeOrder($this->makePaystackPayment());
        $freshOrder = $this->orderRepository->get(1);

        $freshOrder->expects($this->never())->method('addStatusToHistory');
        $this->orderRepository->expects($this->never())->method('save');

        $result = $this->paymentSettlement->register(
            (object) ['data' => $this->makeVerifyData(['status' => 'pending'])],
            $order,
            true
        );

        $this->assertSame(TransactionValidator::REASON_IN_FLIGHT, $result['reason']);
        $this->assertFalse($result['historyRecorded']);
    }

    /**
     * The D7 attack: this exact reference is already bound to a different
     * order. Money moved, just not attributable to this order.
     */
    public function testReferenceBoundToDifferentOrderIsRejected(): void
    {
        $this->transactionRepository->method('getList')->willReturn(
            $this->searchResult([$this->transactionItem(999)])
        );

        $order = $this->makeOrder($this->makePaystackPayment(), 1);
        $freshOrder = $this->orderRepository->get(1);

        $freshOrder->getPayment()->expects($this->never())->method('registerCaptureNotification');
        $freshOrder->expects($this->once())
            ->method('addStatusToHistory')
            ->with($this->anything(), $this->stringContains('reference_bound_elsewhere'));
        $this->orderRepository->expects($this->once())->method('save')->with($freshOrder);

        $result = $this->paymentSettlement->register(
            (object) ['data' => $this->makeVerifyData()],
            $order,
            true
        );

        $this->assertSame(TransactionValidator::REASON_REFERENCE_BOUND_ELSEWHERE, $result['reason']);
        $this->assertTrue($result['historyRecorded']);
        $this->assertSame($freshOrder, $result['order']);
    }

    /**
     * Same-order idempotent short-circuit: the exact compound key (txn_id
     * AND order_id both match this order) — a re-verify (webhook/inline race,
     * retried callback) of an already-bound reference is a no-op, not an
     * error, and must not repeat registerCaptureNotification()/save().
     */
    public function testIdempotentReVerifyOfSameOrderIsANoOp(): void
    {
        $this->transactionRepository->method('getList')->willReturn(
            $this->searchResult([$this->transactionItem(1)])
        );

        // State already advanced by the earlier, successful call —
        // deliberately NOT STATE_NEW/STATE_PENDING_PAYMENT, to prove the
        // idempotency check is reached and short-circuits BEFORE the
        // order-state guard could misclassify this as REASON_ORDER_NOT_PAYABLE.
        $order = $this->makeOrder($this->makePaystackPayment(), 1, Order::STATE_PROCESSING);
        $freshOrder = $this->orderRepository->get(1);

        $freshOrder->getPayment()->expects($this->never())->method('setTransactionId');
        $freshOrder->getPayment()->expects($this->never())->method('registerCaptureNotification');
        $freshOrder->expects($this->never())->method('addStatusToHistory');
        $this->orderRepository->expects($this->never())->method('save');

        $result = $this->paymentSettlement->register(
            (object) ['data' => $this->makeVerifyData()],
            $order,
            true
        );

        $this->assertNull($result['reason']);
        $this->assertTrue($result['historyRecorded']);
        $this->assertSame($freshOrder, $result['order']);
    }

    /**
     * The compound key is (txn_id === $reference) AND (order_id matches) —
     * not a vaguer "does this order already have some capture" key. A
     * DIFFERENT, new reference against an already-registered order must not
     * be silently treated as settled for a charge that was never applied to
     * anything: this reference's own (filtered) lookup finds nothing, so
     * registration proceeds for the new reference.
     */
    public function testDifferentReferenceOnAlreadyRegisteredOrderIsNotTreatedAsIdempotent(): void
    {
        // The txn_id-filtered query for THIS reference finds nothing — the
        // order's other, already-registered reference is a different txn_id
        // and so is invisible to this lookup, exactly as it should be.
        $this->noExistingBindings();

        // grandTotal stays positive so settlementFailureReason()'s own
        // ZERO_TOTAL check (driven by getGrandTotal(), not getBaseTotalDue())
        // doesn't fire first — this test is specifically about
        // PaymentSettlement's OWN order-state guard, reached only once
        // settlementFailureReason() has already passed.
        $order = $this->makeOrder($this->makePaystackPayment(), 1, Order::STATE_PROCESSING, 100.00);
        $freshOrder = $this->orderRepository->get(1);
        // STATE_PROCESSING (not payable) is what this test actually exercises;
        // the point is it is NOT the idempotent null short-circuit, proving
        // the compound key didn't fire on order id alone for a brand-new
        // reference this order has never seen.
        $freshOrder->expects($this->once())
            ->method('addStatusToHistory')
            ->with($this->anything(), $this->stringContains('order_not_payable'));

        $result = $this->paymentSettlement->register(
            (object) ['data' => $this->makeVerifyData(['reference' => 'PSK_new_ref'])],
            $order,
            true
        );

        $this->assertSame(TransactionValidator::REASON_ORDER_NOT_PAYABLE, $result['reason']);
        $this->assertTrue($result['historyRecorded']);
    }

    /**
     * @dataProvider terminalStateProvider
     */
    public function testTerminalOrderStateIsRejectedAsOrderClosed(string $state): void
    {
        $this->noExistingBindings();

        $order = $this->makeOrder($this->makePaystackPayment(), 1, $state);
        $freshOrder = $this->orderRepository->get(1);

        $freshOrder->getPayment()->expects($this->never())->method('registerCaptureNotification');
        $freshOrder->expects($this->once())
            ->method('addStatusToHistory')
            ->with($this->anything(), $this->stringContains('[paystack:PSK_ref_123:order_closed]'));
        $this->orderRepository->expects($this->once())->method('save')->with($freshOrder);

        $result = $this->paymentSettlement->register(
            (object) ['data' => $this->makeVerifyData()],
            $order,
            true
        );

        $this->assertSame(TransactionValidator::REASON_ORDER_CLOSED, $result['reason']);
        $this->assertTrue($result['historyRecorded']);
        $this->assertSame($freshOrder, $result['order']);
    }

    public static function terminalStateProvider(): array
    {
        return [
            'canceled' => [Order::STATE_CANCELED],
            'closed' => [Order::STATE_CLOSED],
            'complete' => [Order::STATE_COMPLETE],
        ];
    }

    /**
     * @dataProvider recoverableNotPayableStateProvider
     */
    public function testNonTerminalNotPayableStateStaysOrderNotPayable(string $state): void
    {
        $this->noExistingBindings();

        $order = $this->makeOrder($this->makePaystackPayment(), 1, $state);
        $freshOrder = $this->orderRepository->get(1);

        $freshOrder->getPayment()->expects($this->never())->method('registerCaptureNotification');
        $freshOrder->expects($this->once())
            ->method('addStatusToHistory')
            ->with($this->anything(), $this->stringContains('order_not_payable'));

        $result = $this->paymentSettlement->register(
            (object) ['data' => $this->makeVerifyData()],
            $order,
            true
        );

        $this->assertSame(TransactionValidator::REASON_ORDER_NOT_PAYABLE, $result['reason']);
        $this->assertTrue($result['historyRecorded']);
    }

    public static function recoverableNotPayableStateProvider(): array
    {
        return [
            'holded' => [Order::STATE_HOLDED],
            'payment_review' => [Order::STATE_PAYMENT_REVIEW],
        ];
    }

    /**
     * Terminal, but the rejection history could not be saved: the reason stays
     * the deterministic ORDER_CLOSED; `historyRecorded` false tells the webhook
     * to keep retrying until it can be recorded.
     */
    public function testTerminalOrderWhoseHistoryCannotBeSavedIsClosedButNotRecorded(): void
    {
        $this->noExistingBindings();

        $order = $this->makeOrder($this->makePaystackPayment(), 1, Order::STATE_CANCELED);
        $this->orderRepository->method('save')->willThrowException(new \RuntimeException('db down'));

        $result = $this->paymentSettlement->register(
            (object) ['data' => $this->makeVerifyData()],
            $order,
            true
        );

        $this->assertSame(TransactionValidator::REASON_ORDER_CLOSED, $result['reason']);
        $this->assertFalse($result['historyRecorded']);
    }

    /**
     * A failing history read or append on a terminal order is swallowed the
     * same way: ORDER_CLOSED, not recorded, and nothing to save.
     *
     * @dataProvider historyWriteThrowsProvider
     */
    public function testTerminalOrderWhoseHistoryCannotBeWrittenIsClosedButNotRecorded(string $failingMethod): void
    {
        $this->noExistingBindings();

        $order = $this->makeOrder($this->makePaystackPayment(), 1, Order::STATE_CANCELED);
        $freshOrder = $this->orderRepository->get(1);
        $freshOrder->method($failingMethod)->willThrowException(new \RuntimeException('history broken'));
        $this->orderRepository->expects($this->never())->method('save');

        $result = $this->paymentSettlement->register(
            (object) ['data' => $this->makeVerifyData()],
            $order,
            true
        );

        $this->assertSame(TransactionValidator::REASON_ORDER_CLOSED, $result['reason']);
        $this->assertFalse($result['historyRecorded']);
    }

    public static function historyWriteThrowsProvider(): array
    {
        return [
            'getStatusHistories throws' => ['getStatusHistories'],
            'addStatusToHistory throws' => ['addStatusToHistory'],
        ];
    }

    /**
     * ORDER_CLOSED gets its own wording: the charge was received but applied
     * nowhere, so the comment says so and asks for a refund/reconcile — and it
     * is logged at critical. The dedupe marker is unchanged.
     */
    public function testOrderClosedHistoryCommentSaysPaymentWasNotAppliedAndLogsCritical(): void
    {
        $this->noExistingBindings();

        $order = $this->makeOrder($this->makePaystackPayment(), 1, Order::STATE_CANCELED);
        $freshOrder = $this->orderRepository->get(1);

        $freshOrder->expects($this->once())
            ->method('addStatusToHistory')
            ->with(
                $this->anything(),
                $this->logicalAnd(
                    $this->stringContains('Paystack: payment received but NOT applied — order is closed (order_closed): paid 10000 NGN, expected 10000 NGN, reference PSK_ref_123. Refund or reconcile this charge.'),
                    $this->stringContains('[paystack:PSK_ref_123:order_closed]')
                )
            );
        $this->logger->expects($this->once())->method('critical');

        $this->paymentSettlement->register(
            (object) ['data' => $this->makeVerifyData()],
            $order,
            true
        );
    }

    /**
     * Retry of an already-recorded terminal rejection: the marker is durable
     * proof, so ORDER_CLOSED is returned with no second comment or save.
     */
    public function testTerminalOrderWithMarkerAlreadyPresentIsClosedWithoutRewriting(): void
    {
        $this->noExistingBindings();

        $order = $this->makeOrder($this->makePaystackPayment(), 1, Order::STATE_CANCELED);
        $freshOrder = $this->orderRepository->get(1);

        $existingHistory = $this->createMock(OrderStatusHistoryInterface::class);
        $existingHistory->method('getComment')->willReturn(
            'Paystack: payment rejected. [paystack:PSK_ref_123:order_closed]'
        );
        $freshOrder->method('getStatusHistories')->willReturn([$existingHistory]);

        $freshOrder->expects($this->never())->method('addStatusToHistory');
        $this->orderRepository->expects($this->never())->method('save');

        $result = $this->paymentSettlement->register(
            (object) ['data' => $this->makeVerifyData()],
            $order,
            true
        );

        $this->assertSame(TransactionValidator::REASON_ORDER_CLOSED, $result['reason']);
        $this->assertTrue($result['historyRecorded']);
    }

    public function testZeroBaseTotalDueIsRejectedAsOrderClosed(): void
    {
        $this->noExistingBindings();

        // grandTotal stays positive so settlementFailureReason()'s own
        // ZERO_TOTAL check (driven by getGrandTotal()) doesn't fire first —
        // this test is specifically about PaymentSettlement's OWN
        // getBaseTotalDue() <= 0 guard, a distinct signal (e.g. a partial
        // prior payment) reached only once settlementFailureReason() has
        // already passed. Built directly (not via makeOrder()) so the fresh
        // instance's getBaseTotalDue() can differ from the caller's $order.
        $payment = $this->makePaystackPayment();

        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000000001');
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn(Order::STATE_NEW);
        $order->method('getBaseTotalDue')->willReturn(100.00);
        $order->method('getGrandTotal')->willReturn(100.00);
        $order->method('getOrderCurrencyCode')->willReturn('NGN');

        $freshOrder = $this->createMock(Order::class);
        $freshOrder->method('getEntityId')->willReturn(1);
        $freshOrder->method('getIncrementId')->willReturn('000000001');
        $freshOrder->method('getPayment')->willReturn($payment);
        $freshOrder->method('getState')->willReturn(Order::STATE_NEW);
        $freshOrder->method('getBaseTotalDue')->willReturn(0.0);
        $freshOrder->method('getGrandTotal')->willReturn(100.00);
        $freshOrder->method('getOrderCurrencyCode')->willReturn('NGN');
        $this->orderRepository->method('get')->with(1)->willReturn($freshOrder);

        $result = $this->paymentSettlement->register(
            (object) ['data' => $this->makeVerifyData()],
            $order,
            true
        );

        $this->assertSame(TransactionValidator::REASON_ORDER_CLOSED, $result['reason']);
        $this->assertTrue($result['historyRecorded']);
    }

    /**
     * `register()` takes `OrderInterface`, not concrete `Order` — a caller
     * that hands it a plain `OrderInterface` (not the concrete Magento
     * class) must get a clean REASON_MALFORMED, not a fatal, when the
     * fresh-refetch also can't produce a concrete instance.
     */
    public function testNonConcreteOrderInterfaceInputReturnsMalformedNotFatal(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(42);

        // orderRepository->get() also returns a non-concrete stub (the
        // contractual OrderRepositoryInterface::get() return type is
        // OrderInterface, not Order) — both fetch paths fail to produce a
        // concrete instance, so there is nothing safe to validate/register
        // against.
        $this->orderRepository->method('get')->with(42)->willReturn(
            $this->createMock(OrderInterface::class)
        );

        $result = $this->paymentSettlement->register(
            (object) ['data' => $this->makeVerifyData()],
            $order,
            true
        );

        $this->assertSame(TransactionValidator::REASON_MALFORMED, $result['reason']);
        $this->assertFalse($result['historyRecorded']);
    }

    /**
     * A `NoSuchEntityException`/`InputException` from the fresh re-fetch is a
     * DEFINITE negative answer — the repository is certain this order does
     * not exist (or the id is unusable) — not a transient failure, so this
     * must NOT fall back to the caller's own, unvalidated-by-the-repository
     * $order: doing so would silently register a capture against an instance
     * the repository just said doesn't exist.
     */
    public function testFreshRefetchNoSuchEntityDoesNotFallBackAndReturnsMalformed(): void
    {
        $this->noExistingBindings();

        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000000001');
        $order->method('getPayment')->willReturn($this->makePaystackPayment());
        $order->method('getState')->willReturn(Order::STATE_NEW);
        $order->method('getBaseTotalDue')->willReturn(100.00);
        $order->method('getGrandTotal')->willReturn(100.00);
        $order->method('getOrderCurrencyCode')->willReturn('NGN');

        $this->orderRepository->method('get')->with(1)->willThrowException(
            new \Magento\Framework\Exception\NoSuchEntityException(__('not found'))
        );

        $order->getPayment()->expects($this->never())->method('registerCaptureNotification');
        $this->orderRepository->expects($this->never())->method('save');

        $result = $this->paymentSettlement->register(
            (object) ['data' => $this->makeVerifyData()],
            $order,
            true
        );

        $this->assertSame(TransactionValidator::REASON_MALFORMED, $result['reason']);
        $this->assertFalse($result['historyRecorded']);
    }

    /**
     * A genuinely transient throwable (anything other than
     * NoSuchEntityException/InputException) from the fresh re-fetch must not
     * escape — this is a public method on a class shipped in a Marketplace
     * module. Falls through to the caller's own $order if that is concrete,
     * same as an unusable fetch result.
     */
    public function testFreshRefetchTransientThrowFallsBackToCallersConcreteOrder(): void
    {
        $this->noExistingBindings();

        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000000001');
        $order->method('getPayment')->willReturn($this->makePaystackPayment());
        $order->method('getState')->willReturn(Order::STATE_NEW);
        $order->method('getBaseTotalDue')->willReturn(100.00);
        $order->method('getGrandTotal')->willReturn(100.00);
        $order->method('getOrderCurrencyCode')->willReturn('NGN');

        $this->orderRepository->method('get')->with(1)->willThrowException(
            new \RuntimeException('deadlock')
        );

        $order->getPayment()->expects($this->once())->method('registerCaptureNotification');
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $result = $this->paymentSettlement->register(
            (object) ['data' => $this->makeVerifyData()],
            $order,
            true
        );

        $this->assertNull($result['reason']);
        $this->assertTrue($result['historyRecorded']);
        $this->assertSame($order, $result['order']);
    }

    /**
     * Overpayment is accepted (any excess is fee) and recorded as a single
     * merchant-visible history comment, folded into the same save() as the
     * registration itself — not a second, separate save.
     */
    public function testOverpaymentIsAcceptedAndRecordedInOneSave(): void
    {
        $this->noExistingBindings();

        $order = $this->makeOrder($this->makePaystackPayment(), 1, Order::STATE_NEW, 100.00);
        $freshOrder = $this->orderRepository->get(1);

        $freshOrder->expects($this->once())
            ->method('addStatusToHistory')
            ->with($this->anything(), $this->stringContains('overpaid'));
        $this->orderRepository->expects($this->once())->method('save')->with($freshOrder);

        $result = $this->paymentSettlement->register(
            (object) ['data' => $this->makeVerifyData(['amount' => 10100])],
            $order,
            true
        );

        $this->assertNull($result['reason']);
        $this->assertTrue($result['historyRecorded']);
    }

    /**
     * Fix E: a throw from the bind/register/save steps — after every
     * validation and binding check has already passed — must not escape
     * uncaught. It must be recorded as merchant-visible history (money may
     * already have moved) and reported as a distinct, retry-safe-for-Paystack
     * reason, not silently swallowed with zero trace.
     */
    public function testRegistrationThrowingIsCaughtRecordedAndReturnsRegistrationFailed(): void
    {
        $this->noExistingBindings();

        $payment = $this->makePaystackPayment();
        $payment->method('registerCaptureNotification')
            ->willThrowException(new \RuntimeException('invoice creation failed'));

        $order = $this->makeOrder($payment, 1, Order::STATE_NEW, 100.00);
        $freshOrder = $this->orderRepository->get(1);

        $freshOrder->expects($this->once())
            ->method('addStatusToHistory')
            ->with($this->anything(), $this->stringContains('registration_failed'));
        $this->orderRepository->expects($this->once())->method('save')->with($freshOrder);

        $result = $this->paymentSettlement->register(
            (object) ['data' => $this->makeVerifyData()],
            $order,
            true
        );

        $this->assertSame(TransactionValidator::REASON_REGISTRATION_FAILED, $result['reason']);
        $this->assertTrue($result['historyRecorded']);
        $this->assertSame($freshOrder, $result['order']);
    }

    /**
     * A retry of an already-recorded rejection must not append a duplicate
     * comment, and — since nothing new was written — must not save() either.
     */
    public function testDuplicateRejectionDoesNotAppendOrSaveAgain(): void
    {
        $this->noExistingBindings();

        $wrongPayment = $this->createMock(Payment::class);
        $wrongPayment->method('getMethod')->willReturn('checkmo');

        $order = $this->makeOrder($wrongPayment);
        $freshOrder = $this->orderRepository->get(1);

        $existingHistory = $this->createMock(OrderStatusHistoryInterface::class);
        $existingHistory->method('getComment')->willReturn(
            'Some other prose. [paystack:PSK_ref_123:wrong_method]'
        );
        $freshOrder->method('getStatusHistories')->willReturn([$existingHistory]);

        $freshOrder->expects($this->never())->method('addStatusToHistory');
        $this->orderRepository->expects($this->never())->method('save');

        $result = $this->paymentSettlement->register(
            (object) ['data' => $this->makeVerifyData()],
            $order,
            true
        );

        $this->assertSame(TransactionValidator::REASON_WRONG_METHOD, $result['reason']);
        $this->assertTrue($result['historyRecorded']);
    }
}
