# Changelog

All notable changes to the Paystack Magento 2 module are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

The entries below cover every release since the last tag, **v3.0.4**.

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

_Note: 3.0.5–3.0.10 were not individually tagged; see the commit history since
[`v3.0.4`](https://github.com/PaystackHQ/plugin-magento-2/releases/tag/v3.0.4) for details._
