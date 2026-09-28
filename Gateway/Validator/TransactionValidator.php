<?php

/**
 * Paystack Magento2 Module using \Magento\Payment\Model\Method\AbstractMethod
 * Copyright (C) 2019 Paystack.com
 *
 * This file is part of Pstk/Paystack.
 *
 * Pstk/Paystack is free software => you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http =>//www.gnu.org/licenses/>.
 */

namespace Pstk\Paystack\Gateway\Validator;

use Magento\Sales\Api\Data\OrderInterface;
use Pstk\Paystack\Gateway\SubunitConverter;

/**
 * Checks a Paystack "verify transaction" response against the Magento order
 * it claims to pay for, on four axes: status, currency, amount (bounded
 * window), and live/test domain.
 *
 * What this validator does NOT establish — `validate() === []` means the
 * response passed these four checks, nothing more:
 *  - reference↔order binding: no binding between a Paystack reference and a
 *    Magento order exists anywhere in this module today; a caller that
 *    advances an order on `validate() === []` alone would allow one charge
 *    to settle two orders. That binding is a separate concern owned
 *    elsewhere, not here.
 *  - order state: a `canceled`/`closed` order passes all four axes just the
 *    same as a fresh one — state is a separate concern owned elsewhere.
 *  - replay/idempotency: a known-successful reference re-validates
 *    indefinitely — nothing here tracks "already consumed."
 * Callers must not treat an empty array as "safe to advance this order" on
 * its own; it is one input among several owned by other classes.
 *
 * The `message` strings in this class are log/order-history-facing only and
 * must never be reflected back to an anonymous caller — there is a known
 * reflection leak elsewhere in this codebase via
 * `PaymentManagement::verifyPayment()`'s `\Throwable` catch; do not repeat
 * that pattern with these messages.
 *
 * Why not `Magento\Payment\Gateway\Validator\ValidatorInterface` /
 * `Result` / `ValidatorPool`: this module has no gateway-command DI wiring
 * anywhere — no `ValidatorPool`/`CommandPool` in any `etc/**\/di.xml`, and
 * `Model/Payment/Paystack.php` is a hand-rolled `MethodInterface`
 * implementation (`extends DataObject implements MethodInterface`), not
 * `AbstractMethod`- or `Adapter`-based. Adopting Magento's validator
 * interface here would fight the module's actual architecture for no
 * consumer that asks for it.
 */
class TransactionValidator
{
    const STATUS_NOT_SUCCESS = 'status_not_success';
    const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';
    const CURRENCY_MISMATCH = 'currency_mismatch';
    const AMOUNT_MISMATCH = 'amount_mismatch';
    const AMOUNT_EXCESS = 'amount_excess';
    const ORDER_AMOUNT_INVALID = 'order_amount_invalid';
    const MODE_MISMATCH = 'mode_mismatch';

    /**
     * Tolerance above the expected subunit amount. Covers orders paid by the
     * legacy `Math.ceil` build (pre-R1.2), which could send one subunit more
     * than `SubunitConverter::toSubunit()` computes today for the same
     * order — not unexplained slack, do not delete. Whoever registers the
     * captured payment later must decide whether the verified `data.amount`
     * (possibly one subunit over) or the order's own total is what gets
     * recorded — this class does not decide that.
     */
    const TOLERANCE_SUBUNITS = 1;

    /**
     * @param object|null $verifyData Decoded `data` object from Paystack's
     *     verify-transaction response. Nullable and loosely typed on purpose:
     *     callers receive this from JSON decoding of a third-party response,
     *     so missing/malformed fields must fail closed, never throw.
     * @param OrderInterface $order
     * @param bool $testMode Whether the store is configured for Paystack test
     *     mode. Must come from `PaystackApiClient::isTestMode()`, not from an
     *     independent config read — that closes the exact drift class this
     *     item's SubunitConverter change was designed to prevent, where two
     *     independently-maintained reads of the same config value can
     *     silently disagree. (Today `isTestMode()` and this validator are
     *     both unscoped/current-store; if a future caller resolves this
     *     against `$order->getStoreId()` while the client stays unscoped, a
     *     multi-website override makes the two disagree and real payments get
     *     held. Not fixed here.)
     * @return array{code: string, message: string}[] Empty array means valid.
     */
    public function validate(?object $verifyData, OrderInterface $order, bool $testMode): array
    {
        $status = $verifyData->status ?? null;

        if ($status !== 'success') {
            // `pending`, `ongoing` and `queued` are in-flight, not failed: bank
            // transfer and USSD sit there at verify time and settle minutes
            // later via the webhook (same list as Controller/Payment/Callback.php).
            // Collapsing this into STATUS_NOT_SUCCESS would regress that
            // distinction for any caller that replaces its own inline check
            // with this validator.
            if (in_array($status, ['pending', 'ongoing', 'queued'], true)) {
                return [
                    ['code' => self::STATUS_AWAITING_CONFIRMATION, 'message' => 'Transaction is still being confirmed.'],
                ];
            }

            return [
                ['code' => self::STATUS_NOT_SUCCESS, 'message' => 'Transaction status is not success.'],
            ];
        }

        $errors = [];

        $rawCurrency = $verifyData->currency ?? '';
        $verifyCurrency = is_scalar($rawCurrency) ? strtoupper((string) $rawCurrency) : '';
        $orderCurrency = strtoupper((string) $order->getOrderCurrencyCode());
        if ($verifyCurrency === '' || $orderCurrency === '' || $verifyCurrency !== $orderCurrency) {
            $errors[] = ['code' => self::CURRENCY_MISMATCH, 'message' => 'Transaction currency does not match order currency.'];
        }

        $grandTotal = $order->getGrandTotal();
        $expected = is_numeric($grandTotal) ? SubunitConverter::toSubunit($grandTotal) : 0;
        if ($expected <= 0) {
            $errors[] = ['code' => self::ORDER_AMOUNT_INVALID, 'message' => 'Order amount is missing or invalid.'];
        } else {
            $amount = $verifyData->amount ?? null;
            if (!is_numeric($amount)) {
                $errors[] = ['code' => self::AMOUNT_MISMATCH, 'message' => 'Transaction amount is missing or invalid.'];
            } elseif ($amount < $expected) {
                $errors[] = ['code' => self::AMOUNT_MISMATCH, 'message' => 'Transaction amount is less than the order total.'];
            } elseif ($amount > $expected + self::TOLERANCE_SUBUNITS) {
                $errors[] = ['code' => self::AMOUNT_EXCESS, 'message' => 'Transaction amount exceeds the order total.'];
            }
        }

        /**
         * This does NOT catch "live storefront left in test mode" — that
         * scenario's `$testMode` is true by construction, since `test_mode=1`
         * selects the test secret key that produced the test-domain response;
         * the two are coupled at the source. What it actually catches is a
         * swapped/misconfigured secret key pair (e.g. a test key configured
         * where a live key belongs, or vice versa).
         *
         * A caller that needs to know whether real money moved must not infer
         * it from which codes this method returns — call `chargeIsReal()`
         * instead, which derives that fact directly from `$verifyData`.
         */
        $domain = $verifyData->domain ?? null;
        $expectedDomain = $testMode ? 'test' : 'live';
        if ($domain !== $expectedDomain) {
            $errors[] = ['code' => self::MODE_MISMATCH, 'message' => 'Transaction domain does not match configured mode.'];
        }

        return $errors;
    }

    /**
     * Whether a Paystack verify response represents a real charge (money
     * actually moved), independent of which — if any — codes `validate()`
     * returns for the same `$verifyData`.
     *
     * Derivation: currency/amount/domain are only checked by `validate()`
     * when `$verifyData->status === 'success'`. A 'success' status on
     * Paystack's *test* domain is a sandbox charge (no real money moves);
     * 'success' on the *live* domain is a real charge. So "real money moved"
     * is fully determined by `status === 'success' && domain === 'live'`.
     *
     * This exists specifically so a future caller (e.g. R2.6, deciding
     * whether to suppress a "retry" prompt) doesn't have to infer "did money
     * move" from which `validate()` codes are present — `MODE_MISMATCH` alone
     * does not tell you that, but this method does.
     *
     * @param object|null $verifyData Same decoded `data` object passed to
     *     `validate()`.
     * @return bool
     */
    public static function chargeIsReal(?object $verifyData): bool
    {
        return ($verifyData->status ?? null) === 'success' && ($verifyData->domain ?? null) === 'live';
    }
}
