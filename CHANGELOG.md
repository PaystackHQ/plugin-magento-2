# Changelog

All notable changes to the Paystack Magento 2 module are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

The entries below cover every release since the last tag, **v3.0.10**.

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
