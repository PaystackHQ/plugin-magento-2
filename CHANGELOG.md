# Changelog

All notable changes to the Paystack Magento 2 module are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

The entries below cover every release since the last tag, **v3.0.10**.

## [3.1.0] - 2026-09-29

Every payment-verification path now confirms, from Paystack's verify response,
that the transaction actually settles the order before the order is advanced
to Processing: transaction status must be `success`, the paid amount must
cover the order total (in subunits), the currency must match the order's
currency, the order must have been placed with the Paystack payment method,
and the response's live/test `domain` must match the store's configured mode.
Overpaying is accepted — normal when the customer bears Paystack's transaction
fee — and recorded; where a verify response also reports the amount actually
*requested* at initialize, that figure is checked against a tight window
around the order's expected total (catching a wrong-exponent client bug
regardless of any fee added on top), and the paid amount only has to cover
what was requested.

### Security
- **A transaction that did not pay for an order can no longer advance it.**
  Previously, the inline (popup) REST verification endpoint never checked the
  verify response's status, and no path compared the paid amount or currency to
  the order — a smaller or differently-denominated payment could mark an order
  as Processing. All three paths (redirect callback, inline REST endpoint,
  webhook) now share one settlement check (`Gateway/Validator/TransactionValidator`).
- **`/paystack/payment/recreate` no longer cancels a paid order.** The route
  only acts on orders still in the `new` or `pending_payment` state, and only
  when the order was placed with the Paystack payment method; a
  processing/complete order, or one paid via another method, can no longer
  have its quote restored by an anonymous GET. (Side effect: an
  already-cancelled order no longer re-triggers a quote restore — the first
  call has already restored the quote.)
- **The inline verification endpoint no longer leaks internal detail.** The
  success response now returns only the transaction status and reference
  (previously the full transaction object, including card BIN/last4, customer
  email/phone, and IP, went to the browser), and error responses return a fixed
  message instead of raw gateway/cURL text.
- **The webhook's HMAC signature check no longer fails open on an
  unconfigured secret key.** An empty Paystack secret key (a store never
  configured, or misconfigured for the active mode) made the signature check
  trivially satisfiable by anyone, since `hash_hmac()` against an empty key is
  computable without knowing any secret. The check now rejects outright when
  no secret key is configured.
- **Both payment-verification observers are now also registered in the
  `webapi_rest` area, not only `frontend`.** Previously
  `etc/webapi_rest/events.xml` did not exist at all: the
  `paystack_payment_verify_after` event (advances the order past pending and
  sends the post-payment confirmation email) and `sales_order_place_before`
  (suppresses the initial placement email until payment verifies) were only
  wired in `etc/frontend/events.xml`. The inline flow's own REST endpoint
  (`Model/PaymentManagement.php`) runs in the `webapi_rest` area, so neither
  observer fired there — the module's default integration type sent the
  placement confirmation email (that was never suppressed) but never
  advanced the order or sent the post-payment confirmation. Registering only
  `paystack_payment_verify_after` without also registering
  `ObserverBeforeSalesOrderPlace` would have caused a duplicate email (the
  unsuppressed placement email, plus a new post-payment one); both are
  registered together.
- **`Controller/Payment/Setup.php` no longer leaks internal gateway detail to
  the customer.** A Paystack API failure during the redirect/standard
  checkout flow showed the raw exception message — built from `curl_error()`
  and Paystack's raw response body, which can carry internal hostnames, TLS
  detail, or gateway-side state — directly on the storefront failure page.
  The same leak class was already closed on the redirect callback route; this
  was the one place it was missed. The customer now gets a fixed, safe
  message; the raw detail goes to the log and order history (admin-only).
  Also closes a gap where only `ApiException` was caught — any other
  exception this route can throw (a missing store URL, a malformed Paystack
  response, an order-save failure) now gets the same safe handling instead of
  escaping uncaught.
- **A single Paystack reference can no longer settle two different orders
  (D7, narrowed to a race window — not fully closed; see the reconciliation
  plan's Risks section).** All three verification paths now bind the
  reference to the order via a cross-order lookup before registering the
  payment; a reference already bound to a different order is rejected
  (`reference_bound_elsewhere`) rather than silently advancing whichever
  order asks second.
- **Verified payments are now actually registered against the order**
  (`total_paid`, an invoice, and a `sales_payment_transaction` row) — D8's
  accounting half. Previously, every consumer dispatched
  `paystack_payment_verify_after` without ever calling
  `registerCaptureNotification()`, so a "Processing" order had no invoice and
  no recorded payment. Registration is idempotent per (reference, order): a
  repeat verify of an already-registered payment is a no-op, not a duplicate
  capture. An order no longer in a payable state (canceled/closed) is
  rejected (`order_not_payable`).

### Changed
- **`Observer/ObserverAfterPaymentVerify.php` no longer advances the order
  state itself.** `Model/PaymentSettlement::register()` (above) now owns that
  side effect via `registerCaptureNotification()`; the observer is email-only,
  gated on `!$order->getEmailSent()` instead of the order's status. The
  human-readable "Paystack Payment Verified and Order is being processed"
  history comment this observer used to write is gone — replaced by core's
  own transaction-ID comment (`registerCaptureNotification()` →
  `addTransactionCommentsToOrder()`), not an equivalent line.

### Fixed
- **Webhook responses now distinguish transient from permanent failures.**
  Transient conditions (transaction still settling via bank transfer/USSD,
  Paystack API errors, order not yet found) return HTTP 503 so Paystack retries
  within its ~72h window — previously every outcome returned HTTP 200, which
  silently cancelled retries and could permanently strand a legitimate payment's
  confirmation. Genuine rejections (failed status, amount/currency mismatch)
  return HTTP 200 so Paystack does not pointlessly retry.
- **Rejected and surplus payments are now visible to the merchant on all three
  paths, not only the webhook.** `Model/PaymentSettlement` writes an order
  status-history comment (paid vs expected amount, reference) for every
  settlement rejection and every overpayment, whichever of the redirect
  callback, inline REST endpoint, or webhook produced it — previously only
  the webhook recorded this, so a rejected callback/inline verification
  (including a cross-order `reference_bound_elsewhere` hit) left zero
  merchant-visible trace.
- **A malformed inline verification reference no longer causes a 500** on the
  anonymous REST route.
- **After a payment fails post-charge on the inline flow, the Place Order
  button is no longer re-enabled** — re-enabling it invited a double charge
  while money was already moving.
- **`Controller/Payment/Recreate.php` no longer calls the deprecated
  `Order::save()`.** Order cancellation is now persisted through
  `OrderRepositoryInterface::save()`.

### Known limitations, not addressed by this change
- The webhook's fallback lookup of an order by `quote_id`
  (`Controller/Payment/Webhook.php`, used when the popup flow's
  Paystack-generated reference has no matching order) still resolves an
  ambiguous match via `getTotalCount() == 1`/`getFirstItem()` rather than
  disambiguating by amount. This is a separate, still-open issue (tracked as
  D9/R2.8) — not fixed by this settlement-gate work, which only changed the
  webhook's *retry* semantics (transient vs. permanent), not its order-lookup
  logic.
- The reference-to-order binding introduced above closes the *sequential*
  version of "one charge settles two orders" but is a read-then-write check
  with no lock: two verifications racing at the exact same instant, for a
  reference not yet bound to anything, can still both pass the check before
  either saves. Reference-keyed locking (tracked as a corrected R2.4) is not
  implemented in this release.
- A verified, registered payment is not refundable through Magento's own
  Credit Memo action — refunds must be issued directly from the Paystack
  dashboard. This is a pre-existing gap, not introduced by this release; see
  the User Guide's Refunds section.

### Upgrade note
If a store's checkout was relying (unknowingly) on under- or mis-paid
transactions being accepted, those orders now stay pending and the webhook
records the mismatch in the order history. No configuration change is needed.

## [3.0.11] - 2026-08-17

Corrects the transaction payload sent to Paystack: the amount is now always an
integer number of currency subunits, and the order's own currency is sent.

### Fixed
- **Redirect-mode checkout failed outright on many order totals.** The amount
  was sent as `grandTotal * 100`, a floating-point product — so a 19.99 order
  became `1998.9999999999998`. Paystack rejects a non-integer amount
  (`"amount" must be an integer`, `invalid_amount`), which surfaced to the
  customer as a failed checkout they could not complete. Totals such as 19.99,
  1.10, 0.29 and 8.21 were affected; totals whose product is exactly
  representable, such as 5000.00, were not — which is why this was
  intermittent. The amount is now an integer number of subunits.
  Thanks to @iammcoding (#70).
- **Inline (popup) mode overcharged by one subunit on some totals.** The amount
  used `Math.ceil`, so `Math.ceil(8.21 * 100)` produced 822 instead of 821
  whenever the float product landed just above the integer. Now uses
  `Math.round`.
- **Redirect mode sent no currency at all.** The code called
  `$order->getCurrency()`, which is not a method on `Magento\Sales\Model\Order`
  — it resolved through Magento's magic getter to a non-existent `currency`
  column and returned `null`. Paystack silently substitutes the integration's
  default currency for a null value, so orders were charged the correct number
  in the merchant's default currency rather than the order's. On a store whose
  display currency differs from the Paystack default this mischarged
  significantly: a 12.50 USD order was charged as 12.50 in the default
  currency. Now sends `getOrderCurrencyCode()`.

### Upgrade note
If your Paystack integration does not have your store's currency enabled, the
redirect flow will now fail with `unsupported_currency` where it previously
completed (in the wrong currency). Enable your store's currency on your Paystack
integration. This is a deliberate change: a visible failure is better than a
silent mischarge.

## [3.0.10] - 2026-07-17

Consolidated release for Magento 2.4.9 / PHP 8.5, verified end-to-end with
Content-Security-Policy enforced.

### Fixed
- **Payment verification failed on PHP 8.5 even when the payment succeeded.**
  `Gateway/PaystackApiClient.php` called `curl_close()`, which is deprecated in
  PHP 8.5 (a no-op since PHP 8.0). Magento escalates the deprecation to an
  exception, and it fired *after* the charge was confirmed — so customers saw
  "Payment verification failed" despite a successful payment. Removed all
  `curl_close()` calls (the handle is freed automatically).
- **Admin order creation on Adobe Commerce (EE).** The payment method now
  implements `MethodInterface` directly instead of extending `AbstractMethod`,
  and `getInfoInstance()` matches the expected contract, preventing EE-only
  interceptors from crashing admin pages. Adds a defence-in-depth admin-area guard.

### Added
- PHPUnit unit-test suite (`Test/Unit/**`, `phpunit.xml`) covering the payment
  model, controllers, gateway client, observers, config provider, and plugins.
- End-to-end test coverage and a dedicated admin-config MFTF page object/section.

## [3.0.9] - 2026-07-17

Superseded by 3.0.10 (its fixes are included there).

### Fixed
- **Checkout page hung on the loading spinner under enforced CSP.** The module's
  PHP CSP `PolicyCollector` replaced Magento's entire Content-Security-Policy,
  dropping `'self'` from `script-src` and blocking Magento's own JavaScript on the
  checkout page (CSP is enforced by default on checkout/payment pages since 2.4.7).
  Replaced with the standard, additive `etc/csp_whitelist.xml` mechanism.
- **MFTF `PaystackPaymentConfigAvailableTest` 404'd in the Adobe pipeline.** The
  test navigated with a raw `amOnPage url="admin/..."` that resolved to
  `/admin/admin/...`. Switched to an `area="admin"` page object (emits a correct
  base-relative URL) and core `AdminLoginActionGroup`/`AdminLogoutActionGroup`.

## [3.0.8] - 2026-06-22

### Added
- Storefront guest-checkout MFTF coverage (`StorefrontPaystackCheckoutRendersTest`)
  guarding against the "checkout does not load" class of failure.

### Changed
- Hardened the Adobe Marketplace build script so internal artifacts are excluded
  from the published zip.

## [3.0.7] - 2026-04-07

### Changed
- Version bump (no functional changes).

## [3.0.6] - 2026-04-07

### Changed
- Reworked the checkout method-renderer JavaScript to lazy-load the Paystack Inline
  SDK, so a slow/blocked SDK no longer stalls checkout rendering.
- Updated `ConfigProvider` and the payment model.

### Added
- First vendor MFTF test (`PaystackPaymentConfigAvailableTest`) verifying the
  payment method appears in admin configuration.
- Empty `etc/adminhtml/di.xml` to keep the module out of the admin DI scope.

## [3.0.5] - 2026-03-06

### Fixed
- **Admin order-create crash on Adobe Commerce (EE).** Scoped the
  `PaymentManagementInterface` preference to `frontend`/`webapi_rest` (removed from
  the global/admin scope) and lazy-loaded the payment method, so admin order
  creation and MFTF tests are no longer affected by frontend-only dependencies.

---

_Note: 3.0.5–3.0.9 were not individually tagged — they were released together as
[`v3.0.10`](https://github.com/PaystackHQ/plugin-magento-2/releases/tag/v3.0.10).
See the commit history since
[`v3.0.4`](https://github.com/PaystackHQ/plugin-magento-2/releases/tag/v3.0.4) for details._
