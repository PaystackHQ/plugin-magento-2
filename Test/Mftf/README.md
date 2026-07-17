# MFTF tests for Pstk_Paystack

This module provides **vendor-supplied** MFTF tests for the Paystack payment extension. They use Magento's standard action groups and page objects, mirroring how the Adobe Commerce Marketplace pipeline's own baseline suite navigates.

## Running

From the Magento root (with the extension installed), ensure `MAGENTO_ADMIN_PASSWORD` is set (e.g. in `.env` or `.credentials`), then:

```bash
vendor/bin/mftf generate:tests
vendor/bin/mftf run:test PaystackPaymentConfigAvailableTest
```

Or run by group:

```bash
vendor/bin/mftf run:group Paystack
```

Run a single storefront test by name:

```bash
vendor/bin/mftf run:test StorefrontPaystackCheckoutRendersTest
```

## Tests

- **PaystackPaymentConfigAvailableTest** – Logs in to admin (`AdminLoginActionGroup`), opens Stores → Configuration → Sales → Payment Methods via the `PaystackPaymentConfigPage` page object, and asserts that “Paystack” is visible.
- **StorefrontPaystackCheckoutRendersTest** – Guest **storefront checkout** coverage. Creates a simple product, enables Paystack (inline), then drives add-to-cart → checkout → shipping → payment and asserts the checkout actually renders (no infinite loading mask) and the Paystack method appears on the payment step. This is the regression guard for the “checkout does not load” class of failure; the prior suite only covered the admin config screen.

## Pages

- **PaystackPaymentConfigPage** – `area="admin"` page object for the Payment Methods config section. Using a page object (rather than a raw `admin/...` `amOnPage` string) makes MFTF emit a leading-slash, base-relative URL, avoiding the `/admin/admin/...` noRoute 404 that failed the original submission.
