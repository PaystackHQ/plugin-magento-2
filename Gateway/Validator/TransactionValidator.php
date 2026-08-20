<?php

namespace Pstk\Paystack\Gateway\Validator;

use Magento\Sales\Api\Data\OrderInterface;
use Pstk\Paystack\Model\Payment\Paystack;
use Psr\Log\LoggerInterface;

/**
 * Confirms a Paystack verify response actually settles a given order before any
 * caller advances it: same currency, `paid >= expected` subunits, `success` status,
 * placed with the Paystack payment method.
 *
 * expectedSubunits() must always mirror the amount formula transaction
 * initialization sends (Controller/Payment/Setup.php and the inline checkout JS) —
 * it is the single owner of that formula. Zero-decimal currencies (e.g. XOF, RWF)
 * inherit the pre-existing `x100` convention from initialization as-is, so the
 * comparison here stays consistent with init by construction even though Paystack's
 * own subunit convention for those currencies is not separately modeled.
 */
class TransactionValidator
{
    public const REASON_MALFORMED = 'malformed';
    public const REASON_IN_FLIGHT = 'in_flight';
    public const REASON_NOT_SUCCESSFUL = 'not_successful';
    public const REASON_WRONG_METHOD = 'wrong_method';
    public const REASON_ZERO_TOTAL = 'zero_total';
    public const REASON_CURRENCY_MISMATCH = 'currency_mismatch';
    public const REASON_AMOUNT_MISMATCH = 'amount_mismatch';

    private const STATUSES_IN_FLIGHT = ['pending', 'ongoing', 'queued'];

    /** @var LoggerInterface */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * The subunit amount initialization sent Paystack for this order.
     *
     * @param OrderInterface $order
     * @return int
     */
    public function expectedSubunits(OrderInterface $order): int
    {
        return (int) round($order->getGrandTotal() * 100);
    }

    /**
     * @param object         $verifyResponse Full envelope PaystackApiClient::verifyTransaction() returns
     * @param OrderInterface $order
     * @return string|null Null when settled for this order; otherwise the first failing REASON_* constant
     */
    public function settlementFailureReason(object $verifyResponse, OrderInterface $order): ?string
    {
        $data = $verifyResponse->data ?? null;
        if (!is_object($data)) {
            return self::REASON_MALFORMED;
        }

        $status = $data->status ?? null;
        if (!is_string($status) || $status === '') {
            return self::REASON_MALFORMED;
        }

        $rawAmount = $data->amount ?? null;
        if ($rawAmount === null || !$this->isIntegral($rawAmount)) {
            return self::REASON_MALFORMED;
        }
        $paid = (int) $rawAmount;

        $paidCurrency = $data->currency ?? null;
        if (!is_string($paidCurrency) || $paidCurrency === '') {
            return self::REASON_MALFORMED;
        }

        if (in_array($status, self::STATUSES_IN_FLIGHT, true)) {
            return self::REASON_IN_FLIGHT;
        }

        if ($status !== 'success') {
            return self::REASON_NOT_SUCCESSFUL;
        }

        $expected = $this->expectedSubunits($order);
        $orderCurrency = $order->getOrderCurrencyCode();

        $payment = $order->getPayment();
        if ($payment === null || $payment->getMethod() !== Paystack::CODE) {
            $this->logSettlementProblem(
                self::REASON_WRONG_METHOD,
                $data,
                $order,
                $paid,
                $expected,
                $paidCurrency,
                $orderCurrency
            );
            return self::REASON_WRONG_METHOD;
        }

        if ($expected <= 0 || $paid <= 0) {
            $this->logSettlementProblem(
                self::REASON_ZERO_TOTAL,
                $data,
                $order,
                $paid,
                $expected,
                $paidCurrency,
                $orderCurrency
            );
            return self::REASON_ZERO_TOTAL;
        }

        if (empty($orderCurrency) || strtoupper($paidCurrency) !== strtoupper($orderCurrency)) {
            $this->logSettlementProblem(
                self::REASON_CURRENCY_MISMATCH,
                $data,
                $order,
                $paid,
                $expected,
                $paidCurrency,
                $orderCurrency
            );
            return self::REASON_CURRENCY_MISMATCH;
        }

        if ($paid < $expected) {
            $this->logSettlementProblem(
                self::REASON_AMOUNT_MISMATCH,
                $data,
                $order,
                $paid,
                $expected,
                $paidCurrency,
                $orderCurrency
            );
            return self::REASON_AMOUNT_MISMATCH;
        }

        if ($paid > $expected) {
            // Normal for Paystack's customer-bears-fee configuration, where
            // data.amount = requested + fee. Info level keeps the signal usable.
            $this->logger->info('Paystack: settlement overpayment', $this->allowListedContext(
                $data,
                $order,
                $paid,
                $expected,
                $paidCurrency,
                $orderCurrency
            ));
        }

        return null;
    }

    /**
     * @param string|int|float $value
     * @return bool
     */
    private function isIntegral($value): bool
    {
        if (is_int($value)) {
            return true;
        }
        if (is_float($value)) {
            return $value === floor($value);
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value === floor((float) $value);
        }
        return false;
    }

    /**
     * @param string          $reason
     * @param object          $data
     * @param OrderInterface  $order
     * @param int             $paid
     * @param int             $expected
     * @param string          $paidCurrency
     * @param string|null     $orderCurrency
     * @return void
     */
    private function logSettlementProblem(
        string $reason,
        object $data,
        OrderInterface $order,
        int $paid,
        int $expected,
        string $paidCurrency,
        ?string $orderCurrency
    ): void {
        $this->logger->warning(
            'Paystack: settlement check failed',
            $this->allowListedContext($data, $order, $paid, $expected, $paidCurrency, $orderCurrency) + [
                'reason' => $reason,
            ]
        );
    }

    /**
     * Allow-listed log context only — never the raw response object, which carries
     * card BIN/last4, customer email/phone, IP.
     *
     * @param object      $data
     * @param OrderInterface $order
     * @param int         $paid
     * @param int         $expected
     * @param string      $paidCurrency
     * @param string|null $orderCurrency
     * @return array
     */
    private function allowListedContext(
        object $data,
        OrderInterface $order,
        int $paid,
        int $expected,
        string $paidCurrency,
        ?string $orderCurrency
    ): array {
        return [
            'reference' => $data->reference ?? null,
            'order_increment_id' => $order->getIncrementId(),
            'expected_amount' => $expected,
            'paid_amount' => $paid,
            'expected_currency' => $orderCurrency,
            'paid_currency' => $paidCurrency,
        ];
    }
}
