<?php

namespace Pstk\Paystack\Gateway\Validator;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Pstk\Paystack\Model\Payment\Paystack;
use Psr\Log\LoggerInterface;

/**
 * Confirms a Paystack verify response actually settles a given order before any
 * caller advances it: same currency, `paid >= expected` subunits, `success` status,
 * placed with the Paystack payment method, and live/test domain matching the
 * store's configured mode.
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
    public const REASON_MODE_MISMATCH = 'mode_mismatch';

    /**
     * A reference already bound (via a persisted sales_payment_transaction
     * row) to a *different* order — the D7 cross-order attack. Money moved,
     * definitively, just not attributable to this order; never fixed by a
     * retry, so it is PERMANENT_FOR_WEBHOOK and never RETRYABLE_FOR_CUSTOMER.
     * Lives here, not on Model/PaymentSettlement.php (the class that actually
     * runs the binding check), because keeping every REASON_* constant and its
     * classification in one place matters more than which class detects it.
     */
    public const REASON_REFERENCE_BOUND_ELSEWHERE = 'reference_bound_elsewhere';

    /**
     * The order is not payable right now, but the state may still change
     * (holded, payment_review, ...) — or it is terminal (see
     * REASON_ORDER_CLOSED) but the rejection history could not be durably
     * recorded yet. Never RETRYABLE_FOR_CUSTOMER (money moved or the situation
     * is otherwise unrecoverable by retry — fail closed there), but
     * deliberately NOT PERMANENT_FOR_WEBHOOK, unlike
     * REASON_REFERENCE_BOUND_ELSEWHERE: a bank-transfer/USSD charge that is
     * genuinely `pending` at callback time can settle minutes later via the
     * webhook, and an order that is held or under payment review may become
     * payable again — a late but genuine `charge.success` must keep retrying
     * (Webhook.php's own `NEVER_RECENCY_BOUNDED`), not be permanently
     * dropped, or the money is captured with no order and no refund path.
     */
    public const REASON_ORDER_NOT_PAYABLE = 'order_not_payable';

    /**
     * The order can never take this money: canceled/closed/complete, or nothing
     * left due (e.g. already paid by another reference) — see
     * isClosedForPayment(). Returned only once the rejection has been durably
     * recorded on the order's history, so the webhook acknowledges (permanent,
     * 200) instead of retrying for Paystack's ~72h budget, which risks endpoint
     * back-off (industry standard: ack with 2xx once the problem is recorded).
     * Never RETRYABLE_FOR_CUSTOMER — money moved, fail closed. When the history
     * could not be recorded, PaymentSettlement returns REASON_ORDER_NOT_PAYABLE
     * instead, so the webhook keeps retrying until it can be.
     */
    public const REASON_ORDER_CLOSED = 'order_closed';

    /**
     * A throw from `Model/PaymentSettlement::register()`'s own bind/register/
     * save steps, after every validation and binding check already passed —
     * e.g. a `LocalizedException` from `prepareInvoice()` on some order
     * shape, or a transient DB failure on save. Money may already have moved
     * by this point, so never RETRYABLE_FOR_CUSTOMER; not PERMANENT_FOR_WEBHOOK
     * either (Webhook.php's `NEVER_RECENCY_BOUNDED`) — a transient
     * registration failure should keep retrying, since the same event
     * redelivered later may succeed once the underlying issue clears.
     */
    public const REASON_REGISTRATION_FAILED = 'registration_failed';

    /**
     * Tolerance above the *requested* subunit amount (see the two-field
     * overpayment check in settlementFailureReason()). Covers orders paid by
     * the legacy `Math.ceil` build (pre-R1.2), which could send one subunit
     * more than today's rounding computes for the same order — not
     * unexplained slack, do not delete. Previously (and incorrectly) applied
     * to the *paid* amount instead of the *requested* amount; the two-field
     * design corrects that.
     */
    public const TOLERANCE_SUBUNITS = 1;

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
     * including any reason this list does not recognise (and, deliberately,
     * REASON_MODE_MISMATCH and REASON_WRONG_METHOD — a misconfigured key pair
     * or a payment-method mixup is not "the money definitively didn't
     * settle"), is transient (503) in isPermanentForWebhook(): defaulting to
     * transient is what keeps an unread response or a not-yet-hydrated
     * payment relation from destroying a real payment's retry window.
     */
    private const PERMANENT_FOR_WEBHOOK = [
        self::REASON_NOT_SUCCESSFUL,
        self::REASON_AMOUNT_MISMATCH,
        self::REASON_CURRENCY_MISMATCH,
        self::REASON_ZERO_TOTAL,
        self::REASON_REFERENCE_BOUND_ELSEWHERE,
        self::REASON_ORDER_CLOSED,
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
     * A non-numeric grand total (import/headless/partial-migration order
     * shapes) returns 0 rather than throwing, so it is caught by the
     * existing REASON_ZERO_TOTAL check downstream instead of a TypeError.
     *
     * @param OrderInterface $order
     * @return int
     */
    public function expectedSubunits(OrderInterface $order): int
    {
        $total = $order->getGrandTotal();
        return is_numeric($total) ? (int) round(((float) $total) * 100) : 0;
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
     * True when the order can still be registered as paid: in a pre-payment
     * state (STATE_NEW/STATE_PENDING_PAYMENT) with a positive base amount due.
     * The single definition of that, used by
     * Model/PaymentSettlement::register()'s order-state guard and the webhook
     * order resolver. Allow-list, not deny-list, so a state this list doesn't
     * know about fails closed.
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function isPayable(OrderInterface $order): bool
    {
        return in_array($order->getState(), [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT], true)
            && $order->getBaseTotalDue() > 0;
    }

    /**
     * True when the order can never take a payment: a terminal state
     * (canceled/closed/complete) or nothing left due. Distinct from merely
     * !isPayable(): holded/payment_review orders are not payable *yet* and are
     * deliberately not closed here.
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function isClosedForPayment(OrderInterface $order): bool
    {
        return in_array(
            $order->getState(),
            [Order::STATE_CANCELED, Order::STATE_CLOSED, Order::STATE_COMPLETE],
            true
        ) || $order->getBaseTotalDue() <= 0;
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
     * The integral subunit amount a verify response reports as *requested* at
     * initialize (`data.requested_amount`), or null when the envelope/field is
     * unreadable, not a whole number, or absent on this channel. Same
     * is-integral parse as paidSubunits(). Null is a legitimate, expected
     * outcome here (not every channel populates this field) — callers must
     * treat it as "fall back," not as a malformed response.
     *
     * @param object $verifyResponse Full envelope PaystackApiClient::verifyTransaction() returns
     * @return int|null
     */
    private function requestedSubunits(object $verifyResponse): ?int
    {
        $data = $verifyResponse->data ?? null;
        if (!is_object($data)) {
            return null;
        }

        $rawAmount = $data->requested_amount ?? null;
        if ($rawAmount === null || !$this->isIntegral($rawAmount)) {
            return null;
        }

        return (int) $rawAmount;
    }

    /**
     * @internal Direct use skips reference binding and payment registration —
     *     callers should use `Model\PaymentSettlement::register()` instead.
     * @param object         $verifyResponse Full envelope PaystackApiClient::verifyTransaction() returns
     * @param OrderInterface $order
     * @param bool           $testMode Whether the store is configured for Paystack test
     *     mode. Must come from `PaystackApiClient::isTestMode()`, not from an
     *     independent config read — that would let the two silently disagree.
     * @return string|null Null when settled for this order; otherwise the first failing REASON_* constant
     */
    public function settlementFailureReason(object $verifyResponse, OrderInterface $order, bool $testMode): ?string
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

        // Two-field overpayment check. `data.requested_amount` (the
        // client-supplied figure at initialize) is checked against a tight
        // window around `expected` — this catches a wrong-exponent client bug
        // regardless of any fee added on top. `data.amount` (what was
        // actually charged) only has to be at least what was requested — any
        // excess is an uncapped, customer-borne fee, accepted and logged
        // below. When `requested_amount` is absent/unreadable on this
        // channel, fall back to the original, more permissive `paid >=
        // expected` check rather than reject on a field this class cannot
        // reliably read everywhere.
        $requested = $this->requestedSubunits($verifyResponse);
        if ($requested !== null) {
            if ($requested < $expected - self::TOLERANCE_SUBUNITS || $requested > $expected + self::TOLERANCE_SUBUNITS) {
                $this->logSettlementProblem(self::REASON_AMOUNT_MISMATCH, $context);
                return self::REASON_AMOUNT_MISMATCH;
            }
            if ($paid < $requested) {
                $this->logSettlementProblem(self::REASON_AMOUNT_MISMATCH, $context);
                return self::REASON_AMOUNT_MISMATCH;
            }
        } else {
            if ($paid < $expected - self::TOLERANCE_SUBUNITS) {
                $this->logSettlementProblem(self::REASON_AMOUNT_MISMATCH, $context);
                return self::REASON_AMOUNT_MISMATCH;
            }
        }

        // Placed after every amount rejection path (both the requested_amount
        // branch and its fallback) and before the overpayment info-log — the
        // only slot where no existing response's winning reason changes. A
        // response failing on amount/currency/zero-total *and* mode must keep
        // returning that reason (PERMANENT_FOR_WEBHOOK, 200), not
        // REASON_MODE_MISMATCH (not permanent, 503 retries) — moving this
        // check earlier would silently change webhook retry behavior for
        // exactly that class of response.
        //
        // Read defensively: every other field in this method is `?? null` +
        // type-checked before comparison, but `domain` only feeds this
        // secondary "misconfigured key pair" sanity check, not the core
        // settlement guarantee (unlike status/currency/paid amount above) — so
        // an absent/renamed `domain` skips the mode check entirely rather than
        // rejecting the whole payment, the same fail-open treatment
        // requested_amount gets a few lines up.
        $domain = $data->domain ?? null;
        if (!is_string($domain) || $domain === '') {
            $this->logger->warning('Paystack: mode check skipped, domain unreadable', $context);
        } elseif ($domain !== ($testMode ? 'test' : 'live')) {
            // Does NOT catch "live store left in test mode" — that scenario's
            // $testMode is true by construction. Catches a swapped/
            // misconfigured secret-key pair.
            $this->logSettlementProblem(self::REASON_MODE_MISMATCH, $context);
            return self::REASON_MODE_MISMATCH;
        }

        if ($this->isOverpayment($verifyResponse, $order)) {
            if ($requested === null) {
                // No requested_amount on this channel, so the tight
                // wrong-exponent window a few lines up never ran — this
                // overpayment has no backstop behind it, so it's more visible
                // than the normal (requested_amount present) case below.
                $this->logger->warning('Paystack: settlement overpayment', $context);
            } else {
                // Normal for Paystack's customer-bears-fee configuration, where
                // data.amount = requested + fee. Info level keeps the signal usable.
                $this->logger->info('Paystack: settlement overpayment', $context);
            }
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
     * subunits — compared against `requested_amount` when readable (for
     * consistency with the two-field check in settlementFailureReason()), or
     * `expectedSubunits()` when it is not. Meaningful once
     * settlementFailureReason() has already returned null for the same
     * envelope/order pair; called against an unsettled pair it simply answers
     * "no" rather than throwing, since it is a read, not a gate.
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

        $requested = $this->requestedSubunits($verifyResponse);
        $baseline = $requested ?? $this->expectedSubunits($order);

        return $paid > $baseline;
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
            'reference' => is_scalar($data->reference ?? null)
                ? substr((string) ($data->reference ?? ''), 0, 100)
                : null,
            'order_increment_id' => $order->getIncrementId(),
            'expected_amount' => $expected,
            'paid_amount' => $paid,
            'expected_currency' => $orderCurrency,
            'paid_currency' => $paidCurrency,
        ];
    }
}
