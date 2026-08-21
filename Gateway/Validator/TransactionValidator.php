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
 * expectedSubunits() is the server-side owner of the amount formula
 * Controller/Payment/Setup.php uses to initialize a transaction. The inline
 * checkout path does not call this method: it computes the same ×100 rounding
 * client-side from `quote.totals().grand_total`, so the two formulas must be
 * kept in step by hand. This class does not prevent that divergence — the
 * settlement gate below is what catches it, by rejecting a paid amount that
 * doesn't match what this method says was expected. Zero-decimal currencies
 * (e.g. XOF, RWF) inherit the pre-existing `x100` convention from
 * initialization as-is, so the comparison here stays consistent with init by
 * construction even though Paystack's own subunit convention for those
 * currencies is not separately modeled.
 */
class TransactionValidator
{
    /**
     * REASON_MALFORMED means Paystack's own verify response could not be read
     * (status/amount/currency missing or unusable) — money's fate is unknown, so
     * it is never safe to retry. REASON_BAD_REFERENCE means the opposite: a
     * reference the *client* sent us was unusable before any verify call was even
     * made (e.g. PaymentManagement's `_-~-_` split guard) — nothing was charged
     * through us, so it is safe to retry. Keeping them distinct lets
     * isTerminalForCustomer() fail closed on the former and open on the latter.
     */
    public const REASON_MALFORMED = 'malformed';
    public const REASON_BAD_REFERENCE = 'bad_reference';
    public const REASON_IN_FLIGHT = 'in_flight';
    public const REASON_NOT_SUCCESSFUL = 'not_successful';
    public const REASON_WRONG_METHOD = 'wrong_method';
    public const REASON_ZERO_TOTAL = 'zero_total';
    public const REASON_CURRENCY_MISMATCH = 'currency_mismatch';
    public const REASON_AMOUNT_MISMATCH = 'amount_mismatch';

    /**
     * The single list of "not yet settled" Paystack statuses — callers must
     * read this rather than keep their own copy of the literal.
     */
    public const STATUSES_IN_FLIGHT = ['pending', 'ongoing', 'queued'];

    /**
     * Reasons safe to invite a retry for: nothing was charged through us, or
     * Paystack's own record says the attempt was not charged. Everything else
     * fails closed in isTerminalForCustomer() — money moved, or its fate is
     * unknown, so re-enabling payment risks a double charge.
     */
    private const RETRYABLE_FOR_CUSTOMER = [
        self::REASON_NOT_SUCCESSFUL,
        self::REASON_BAD_REFERENCE,
    ];

    /**
     * Reasons that describe what the money actually did — a retry of the exact
     * same webhook event cannot change the answer, so these are the only ones
     * safe to report permanent (200, no retry) to Paystack. Everything else,
     * including any reason this list does not recognise, is transient (503) in
     * isPermanentForWebhook(): defaulting to transient is what keeps an unread
     * response or a not-yet-hydrated payment relation from destroying a real
     * payment's retry window.
     */
    private const PERMANENT_FOR_WEBHOOK = [
        self::REASON_NOT_SUCCESSFUL,
        self::REASON_AMOUNT_MISMATCH,
        self::REASON_CURRENCY_MISMATCH,
        self::REASON_ZERO_TOTAL,
    ];

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
     * True when the order's payment method is Paystack — the single
     * definition of "wrong method" used by settlementFailureReason()'s
     * WRONG_METHOD guard.
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function isPaystackOrder(OrderInterface $order): bool
    {
        $payment = $order->getPayment();
        return $payment !== null && $payment->getMethod() === Paystack::CODE;
    }

    /**
     * The integral subunit amount a verify response reports as paid, or null
     * when the envelope is unreadable or the amount is not a whole number —
     * the single parse settlementFailureReason() and isOverpayment() both
     * build on. Note: 0 is a valid, readable amount and is returned as such;
     * callers distinguishing "unreadable" from "zero paid" must check for
     * `=== null`, not falsiness.
     *
     * @param object $verifyResponse Full envelope PaystackApiClient::verifyTransaction() returns
     * @return int|null
     */
    public function paidSubunits(object $verifyResponse): ?int
    {
        $data = $verifyResponse->data ?? null;
        if (!is_object($data)) {
            return null;
        }

        $rawAmount = $data->amount ?? null;
        if ($rawAmount === null || !$this->isIntegral($rawAmount)) {
            return null;
        }

        return (int) $rawAmount;
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

        $paid = $this->paidSubunits($verifyResponse);
        if ($paid === null) {
            return self::REASON_MALFORMED;
        }

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

        // All six values are settled from here on, so the context for every
        // guard below (and the overpayment info-log) is built once.
        $context = $this->allowListedContext($data, $order, $paid, $expected, $paidCurrency, $orderCurrency);

        if (!$this->isPaystackOrder($order)) {
            $this->logSettlementProblem(self::REASON_WRONG_METHOD, $context);
            return self::REASON_WRONG_METHOD;
        }

        if ($expected <= 0 || $paid <= 0) {
            $this->logSettlementProblem(self::REASON_ZERO_TOTAL, $context);
            return self::REASON_ZERO_TOTAL;
        }

        if (empty($orderCurrency) || strtoupper($paidCurrency) !== strtoupper($orderCurrency)) {
            $this->logSettlementProblem(self::REASON_CURRENCY_MISMATCH, $context);
            return self::REASON_CURRENCY_MISMATCH;
        }

        if ($paid < $expected) {
            $this->logSettlementProblem(self::REASON_AMOUNT_MISMATCH, $context);
            return self::REASON_AMOUNT_MISMATCH;
        }

        if ($paid > $expected) {
            // Normal for Paystack's customer-bears-fee configuration, where
            // data.amount = requested + fee. Info level keeps the signal usable.
            $this->logger->info('Paystack: settlement overpayment', $context);
        }

        return null;
    }

    /**
     * True when the customer must NOT be invited to pay again for this reason.
     * See RETRYABLE_FOR_CUSTOMER above for which reasons return false and why.
     *
     * @param string $reason
     * @return bool
     */
    public function isTerminalForCustomer(string $reason): bool
    {
        return !in_array($reason, self::RETRYABLE_FOR_CUSTOMER, true);
    }

    /**
     * True when this reason is safe to report permanent (200, no retry) to
     * Paystack. See PERMANENT_FOR_WEBHOOK above for which reasons return true
     * and why.
     *
     * @param string $reason
     * @return bool
     */
    public function isPermanentForWebhook(string $reason): bool
    {
        return in_array($reason, self::PERMANENT_FOR_WEBHOOK, true);
    }

    /**
     * Owns the customer-facing rejection copy for the REASON_* classifications
     * reached through a verify response — or any caller-defined reason, since
     * PaymentManagement also passes ad hoc strings ('error', 'quote_mismatch')
     * for outcomes the verify response never even reached. Callback.php,
     * Setup.php and Recreate.php each carry their own copy for non-verify
     * outcomes that have no REASON_* here.
     *
     * @param string $reason
     * @return string
     */
    public function customerMessage(string $reason): string
    {
        switch ($reason) {
            case self::REASON_NOT_SUCCESSFUL:
                return "Your payment was not completed. Please try again.";
            case self::REASON_BAD_REFERENCE:
                return "Payment could not be verified. Please try again.";
            case self::REASON_IN_FLIGHT:
                return "Your payment is still being confirmed. Please do not pay "
                    . "again — we will email you once it is confirmed.";
            default:
                // Any reason not covered above, including one this class does
                // not yet know about: money moved, or its fate is unknown, so
                // the customer must not be told to just try again. Fails
                // closed on wording exactly as isTerminalForCustomer() fails
                // closed on terminality.
                return "We could not confirm your payment. Please do not pay "
                    . "again — contact support with your order number.";
        }
    }

    /**
     * Whether a verify response's paid amount exceeds this order's expected
     * subunits — the single definition callers use to detect the customer-
     * bears-fee overpayment window, built on paidSubunits() rather than ad hoc
     * is_numeric/(int) casts. Meaningful once settlementFailureReason() has
     * already returned null for the same envelope/order pair; called against
     * an unsettled pair it simply answers "no" rather than throwing, since it
     * is a read, not a gate.
     *
     * @param object $verifyResponse Full envelope PaystackApiClient::verifyTransaction() returns
     * @param OrderInterface $order
     * @return bool
     */
    public function isOverpayment(object $verifyResponse, OrderInterface $order): bool
    {
        $paid = $this->paidSubunits($verifyResponse);
        if ($paid === null) {
            return false;
        }

        return $paid > $this->expectedSubunits($order);
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
     * @param string $reason
     * @param array  $context Allow-listed context from allowListedContext()
     * @return void
     */
    private function logSettlementProblem(string $reason, array $context): void
    {
        $this->logger->warning('Paystack: settlement check failed', $context + ['reason' => $reason]);
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
