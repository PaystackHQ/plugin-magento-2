<?php

namespace Pstk\Paystack\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Pstk\Paystack\Gateway\Validator\TransactionValidator;
use Pstk\Paystack\Model\Payment\Paystack;
use Pstk\Paystack\Model\WebhookOrderResolver;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\Data\TransactionSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

class WebhookOrderResolverTest extends TestCase
{
    /** @var WebhookOrderResolver */
    private $resolver;

    /** @var MockObject|OrderRepositoryInterface */
    private $orderRepository;

    /** @var MockObject|TransactionRepositoryInterface */
    private $transactionRepository;

    /** @var MockObject|Order */
    private $orderInterface;

    /** @var array Filters addFilter() has collected since the last create() */
    private $pendingFilters = [];

    /** @var \SplObjectStorage Built criteria => their field=>value filters */
    private $criteriaFilters;

    /** @var callable|null fn(array $filters): array — orders getList() returns */
    private $orderQuery;

    /** @var MockObject|LoggerInterface */
    private $logger;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->orderInterface = $this->createMock(Order::class);
        $this->orderInterface->method('getId')->willReturn(null);
        $this->criteriaFilters = new \SplObjectStorage();
        $this->logger = $this->createMock(LoggerInterface::class);

        // Stateful like the real builder: filters accumulate until create().
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnCallback(function ($field, $value) use ($builder) {
            $this->pendingFilters[$field] = $value;
            return $builder;
        });
        $builder->method('create')->willReturnCallback(function () {
            $criteria = $this->createMock(SearchCriteriaInterface::class);
            $this->criteriaFilters[$criteria] = $this->pendingFilters;
            $this->pendingFilters = [];
            return $criteria;
        });

        $this->givenNoBoundTransaction();
        $this->orderRepository->method('getList')->willReturnCallback(function ($criteria) {
            $result = $this->createMock(OrderSearchResultInterface::class);
            $result->method('getItems')->willReturn(
                $this->orderQuery ? ($this->orderQuery)($this->criteriaFilters[$criteria]) : []
            );
            return $result;
        });

        $this->resolver = new WebhookOrderResolver(
            $this->orderRepository,
            $builder,
            $this->transactionRepository,
            $this->orderInterface,
            new TransactionValidator($this->createMock(LoggerInterface::class)),
            $this->logger
        );
    }

    private function givenNoBoundTransaction(): void
    {
        $none = $this->createMock(TransactionSearchResultInterface::class);
        $none->method('getItems')->willReturn([]);
        $this->transactionRepository->method('getList')->willReturn($none);
    }

    private function makeOrder(
        int $id,
        string $state = Order::STATE_NEW,
        float $due = 100.0,
        string $method = Paystack::CODE
    ): MockObject {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($id);
        $order->method('getEntityId')->willReturn($id);
        $order->method('getState')->willReturn($state);
        $order->method('getBaseTotalDue')->willReturn($due);
        $payment = $this->createMock(Order\Payment::class);
        $payment->method('getMethod')->willReturn($method);
        $order->method('getPayment')->willReturn($payment);
        return $order;
    }

    private function details(array $data): object
    {
        return json_decode(json_encode(['data' => $data]));
    }

    private function withMetadata($metadata): object
    {
        return (object) ['data' => (object) ['reference' => 'PSK_1', 'metadata' => $metadata]];
    }

    public function testIncrementIdWinsWithoutFurtherLookups(): void
    {
        $order = $this->makeOrder(1);
        $orderInterface = $this->createMock(Order::class);
        $orderInterface->method('getId')->willReturn(1);
        $resolver = $this->rebuildWith($orderInterface);

        $this->orderRepository->expects($this->never())->method('getList');
        $this->transactionRepository->expects($this->never())->method('getList');

        $this->assertSame(
            $orderInterface,
            $resolver->resolve('000000001', $this->withMetadata((object) ['quoteId' => '5']))
        );
    }

    /** Same wiring as setUp() but with a different increment-id loader. */
    private function rebuildWith(MockObject $orderInterface): WebhookOrderResolver
    {
        $orderInterface->method('loadByIncrementId')->willReturnSelf();
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('create')->willReturn($this->createMock(SearchCriteriaInterface::class));
        return new WebhookOrderResolver(
            $this->orderRepository,
            $builder,
            $this->transactionRepository,
            $orderInterface,
            new TransactionValidator($this->createMock(LoggerInterface::class)),
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testBoundReferenceWinsOverMetadata(): void
    {
        $bound = $this->makeOrder(5, Order::STATE_PROCESSING, 0.0);
        $txn = $this->createMock(TransactionInterface::class);
        $txn->method('getOrderId')->willReturn(5);
        $found = $this->createMock(TransactionSearchResultInterface::class);
        $found->method('getItems')->willReturn([$txn]);

        // Fresh mock: setUp() already configured getList() with "no bindings".
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->transactionRepository->method('getList')->willReturn($found);
        // Loaded via getList() (not get(), whose registry would hand register()
        // this same instance back as its "fresh" re-fetch) — exactly one
        // lookup, for the bound order; no metadata/quote lookups follow.
        $boundResult = $this->createMock(OrderSearchResultInterface::class);
        $boundResult->method('getItems')->willReturn([$bound]);
        // Fresh mock: setUp() already stubbed getList() with no orders.
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderRepository->expects($this->never())->method('get');
        $this->orderRepository->expects($this->once())->method('getList')->willReturn($boundResult);

        $this->assertSame(
            $bound,
            $this->resolverWithTransactions()->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => '9', 'orderId' => '7']))
        );
    }

    private function resolverWithTransactions(): WebhookOrderResolver
    {
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('create')->willReturn($this->createMock(SearchCriteriaInterface::class));
        return new WebhookOrderResolver(
            $this->orderRepository,
            $builder,
            $this->transactionRepository,
            $this->orderInterface,
            new TransactionValidator($this->createMock(LoggerInterface::class)),
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testOrderIdAndQuoteIdMatchReturnsThatOrderEvenWhenCanceled(): void
    {
        $cancelled = $this->makeOrder(12, Order::STATE_CANCELED);
        $live = $this->makeOrder(13);
        $seen = [];
        $this->orderQuery = function (array $f) use ($cancelled, $live, &$seen) {
            $seen[] = $f;
            return ($f['entity_id'] ?? null) === '12' && ($f['quote_id'] ?? null) === '55' ? [$cancelled] : [$cancelled, $live];
        };

        $this->assertSame(
            $cancelled,
            $this->resolver->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => '55', 'orderId' => '12']))
        );
        $this->assertCount(1, $seen, 'A step-3 hit must not go on to the quote lookup.');
    }

    public function testOrderIdNotMatchingQuoteFallsBackToQuotePath(): void
    {
        $live = $this->makeOrder(13);
        $cancelled = $this->makeOrder(12, Order::STATE_CANCELED);
        $this->orderQuery = function (array $f) use ($live, $cancelled) {
            return isset($f['entity_id']) ? [] : [$cancelled, $live];
        };

        $this->assertSame(
            $live,
            $this->resolver->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => '55', 'orderId' => '99']))
        );
    }

    public function testOrderIdMatchOnNonPaystackOrderFallsBackToQuotePath(): void
    {
        $other = $this->makeOrder(12, Order::STATE_NEW, 100.0, 'checkmo');
        $live = $this->makeOrder(13);
        $this->orderQuery = function (array $f) use ($other, $live) {
            return isset($f['entity_id']) ? [$other] : [$other, $live];
        };

        $this->assertSame(
            $live,
            $this->resolver->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => '55', 'orderId' => '12']))
        );
    }

    public function testOrderIdNotFoundAndNothingOnQuoteReturnsNull(): void
    {
        $this->orderQuery = function (array $f) {
            return [];
        };

        $this->assertNull(
            $this->resolver->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => '55', 'orderId' => '12']))
        );
    }

    public function testOrderIdWithoutQuoteIdIsIgnoredAndReturnsNull(): void
    {
        $this->orderRepository->expects($this->never())->method('getList');

        $this->assertNull($this->resolver->resolve('PSK_1', $this->withMetadata((object) ['orderId' => '12'])));
    }

    public function testMissingOrderIdLogsQuoteLookupFallback(): void
    {
        $lone = $this->makeOrder(3);
        $this->orderQuery = function () use ($lone) {
            return [$lone];
        };
        $messages = [];
        $this->logger->method('info')->willReturnCallback(function (string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $this->logger->expects($this->never())->method('error');

        $this->assertSame($lone, $this->resolver->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => '55'])));
        $this->assertContains('Paystack Webhook: no metadata.orderId, using quote lookup', $messages);
    }

    public function testUnresolvableQuoteCandidatesAreLoggedAtError(): void
    {
        // Two canceled siblings: neither is payable, so none can be chosen.
        $first = $this->makeOrder(3, Order::STATE_CANCELED);
        $second = $this->makeOrder(4, Order::STATE_CANCELED);
        $this->orderQuery = function () use ($first, $second) {
            return [$first, $second];
        };
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('none can be chosen'),
                $this->callback(function (array $context): bool {
                    return 'PSK_1' === $context['reference'] && 2 === count($context['candidates']);
                })
            );

        $this->assertNull($this->resolver->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => '55'])));
    }

    public function testBoundReferenceOnNonPaystackOrderIsSkipped(): void
    {
        $bound = $this->makeOrder(5, Order::STATE_PROCESSING, 0.0, 'checkmo');
        $txn = $this->createMock(TransactionInterface::class);
        $txn->method('getOrderId')->willReturn(5);
        $found = $this->createMock(TransactionSearchResultInterface::class);
        $found->method('getItems')->willReturn([$txn]);

        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->transactionRepository->method('getList')->willReturn($found);
        $boundResult = $this->createMock(OrderSearchResultInterface::class);
        $boundResult->method('getItems')->willReturn([$bound]);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderRepository->expects($this->once())->method('getList')->willReturn($boundResult);

        // No usable metadata to fall through to, so skipping the binding
        // leaves nothing to resolve — it must not settle a non-Paystack order.
        $this->assertNull(
            $this->resolverWithTransactions()->resolve('PSK_1', $this->withMetadata((object) []))
        );
    }

    public function testOrphanBoundTransactionFallsThroughToQuoteLookup(): void
    {
        // A sales_payment_transaction names an order that no longer loads:
        // step 2 must not return null or throw, it continues to the quote path.
        $txn = $this->createMock(TransactionInterface::class);
        $txn->method('getOrderId')->willReturn(404);
        $found = $this->createMock(TransactionSearchResultInterface::class);
        $found->method('getItems')->willReturn([$txn]);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->transactionRepository->method('getList')->willReturn($found);

        $lone = $this->makeOrder(3, Order::STATE_NEW);
        $empty = $this->createMock(OrderSearchResultInterface::class);
        $empty->method('getItems')->willReturn([]);
        $loneResult = $this->createMock(OrderSearchResultInterface::class);
        $loneResult->method('getItems')->willReturn([$lone]);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderRepository->expects($this->exactly(2))->method('getList')
            ->willReturnOnConsecutiveCalls($empty, $loneResult);

        $this->assertSame(
            $lone,
            $this->resolverWithTransactions()->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => '9']))
        );
    }

    public function testLoneOrderOnQuoteIsUsedInAnyStateAndMethod(): void
    {
        $lone = $this->makeOrder(3, Order::STATE_CANCELED, 0.0, 'checkmo');
        $this->orderQuery = function () use ($lone) {
            return [$lone];
        };

        $this->assertSame($lone, $this->resolver->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => '55'])));
    }

    public function testCanceledPlusNewPicksTheNewOne(): void
    {
        $cancelled = $this->makeOrder(3, Order::STATE_CANCELED);
        $live = $this->makeOrder(4, Order::STATE_PENDING_PAYMENT);
        $this->orderQuery = function () use ($cancelled, $live) {
            return [$cancelled, $live];
        };

        $this->assertSame($live, $this->resolver->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => '55'])));
    }

    public function testTwoPayableOrdersReturnNull(): void
    {
        $this->orderQuery = function () {
            return [$this->makeOrder(3), $this->makeOrder(4)];
        };

        $this->assertNull($this->resolver->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => '55'])));
    }

    public function testSeveralCanceledOrdersReturnNull(): void
    {
        $this->orderQuery = function () {
            return [
                $this->makeOrder(3, Order::STATE_CANCELED),
                $this->makeOrder(4, Order::STATE_CANCELED),
            ];
        };

        $this->assertNull($this->resolver->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => '55'])));
    }

    public function testPayableStateWithZeroDueIsExcluded(): void
    {
        $zeroDue = $this->makeOrder(3, Order::STATE_NEW, 0.0);
        $live = $this->makeOrder(4);
        $this->orderQuery = function () use ($zeroDue, $live) {
            return [$zeroDue, $live];
        };
        $this->assertSame($live, $this->resolver->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => '55'])));

        $this->orderQuery = function () use ($zeroDue) {
            return [$zeroDue, $this->makeOrder(5, Order::STATE_CANCELED)];
        };
        $this->assertNull($this->resolver->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => '55'])));
    }

    public function testNonPaystackPayableSiblingIsExcluded(): void
    {
        $live = $this->makeOrder(4);
        $this->orderQuery = function () use ($live) {
            return [$this->makeOrder(3, Order::STATE_NEW, 100.0, 'checkmo'), $live];
        };

        $this->assertSame($live, $this->resolver->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => '55'])));
    }

    /**
     * @dataProvider metadataShapeProvider
     */
    public function testMetadataShapes($metadata, bool $expectFound): void
    {
        $lone = $this->makeOrder(3);
        $this->orderQuery = function () use ($lone) {
            return [$lone];
        };

        $result = $this->resolver->resolve('PSK_1', $this->withMetadata($metadata));

        $expectFound ? $this->assertSame($lone, $result) : $this->assertNull($result);
    }

    public static function metadataShapeProvider(): array
    {
        return [
            'object' => [(object) ['quoteId' => '55'], true],
            'JSON string' => ['{"quoteId":"55"}', true],
            'empty string' => ['', false],
            'non-object JSON string' => ['"55"', false],
            'invalid JSON string' => ['{quoteId:', false],
            'list array' => [['quoteId' => '55'], false],
            'null' => [null, false],
            'int' => [55, false],
        ];
    }

    public function testMissingMetadataReturnsNull(): void
    {
        $this->orderRepository->expects($this->never())->method('getList');

        $this->assertNull($this->resolver->resolve('PSK_1', (object) ['data' => (object) ['reference' => 'PSK_1']]));
        $this->assertNull($this->resolver->resolve('PSK_1', (object) []));
    }

    /**
     * @dataProvider validIdProvider
     */
    public function testValidQuoteIdForms($quoteId, string $expected): void
    {
        $lone = $this->makeOrder(3);
        $seen = null;
        $this->orderQuery = function (array $f) use ($lone, &$seen) {
            $seen = $f;
            return [$lone];
        };

        $this->assertSame($lone, $this->resolver->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => $quoteId])));
        $this->assertSame(['quote_id' => $expected], $seen);
    }

    public static function validIdProvider(): array
    {
        return [
            'digit string' => ['12', '12'],
            'int' => [12, '12'],
        ];
    }

    /**
     * @dataProvider invalidIdProvider
     */
    public function testInvalidQuoteIdIsRejectedWithoutAnyOrderLookup($quoteId): void
    {
        $this->orderRepository->expects($this->never())->method('getList');

        $this->assertNull($this->resolver->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => $quoteId])));
    }

    /**
     * An invalid orderId is dropped (never coerced) and the quote lookup runs
     * with the valid quoteId alone.
     *
     * @dataProvider invalidIdProvider
     */
    public function testInvalidOrderIdSkipsStepThree($orderId): void
    {
        $lone = $this->makeOrder(3);
        $queries = [];
        $this->orderQuery = function (array $f) use ($lone, &$queries) {
            $queries[] = $f;
            return [$lone];
        };

        $this->assertSame(
            $lone,
            $this->resolver->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => '55', 'orderId' => $orderId]))
        );
        $this->assertSame([['quote_id' => '55']], $queries);
    }

    public static function invalidIdProvider(): array
    {
        return [
            'trailing junk' => ['12abc'],
            'exponent' => ['1e1'],
            'int zero' => [0],
            'string zero' => ['0'],
            'negative int' => [-1],
            'negative string' => ['-1'],
            'float' => [12.0],
            'bool' => [true],
            'leading zero' => ['012'],
            'array' => [[12]],
            'empty' => [''],
        ];
    }

    public function testRepositoryErrorOnOrderIdLookupPropagates(): void
    {
        $this->orderQuery = function (array $f) {
            throw new \RuntimeException('db gone away');
        };

        $this->expectException(\RuntimeException::class);
        $this->resolver->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => '55', 'orderId' => '12']));
    }

    public function testRepositoryErrorOnQuoteLookupPropagates(): void
    {
        $this->orderQuery = function (array $f) {
            throw new \RuntimeException('db gone away');
        };

        $this->expectException(\RuntimeException::class);
        $this->resolver->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => '55']));
    }

    public function testTransactionRepositoryErrorPropagates(): void
    {
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->transactionRepository->method('getList')->willThrowException(new \RuntimeException('db gone away'));

        $this->expectException(\RuntimeException::class);
        $this->resolverWithTransactions()->resolve('PSK_1', $this->withMetadata((object) ['quoteId' => '55']));
    }
}
