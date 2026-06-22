# MFTF tests for Pstk_Paystack

This module provides **vendor-supplied** MFTF tests for the Paystack payment extension. The test and action group are self-contained (no refs to Magento_Backend) so generation succeeds in the Adobe Commerce Marketplace pipeline.

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

- **PaystackPaymentConfigAvailableTest** – Logs in to admin (using our LoginToAdminActionGroup), opens Stores → Configuration → Sales → Payment Methods, and asserts that “Paystack” is visible.
- **StorefrontPaystackCheckoutRendersTest** – Guest **storefront checkout** coverage. Creates a simple product, enables Paystack (inline), then drives add-to-cart → checkout → shipping → payment and asserts the checkout actually renders (no infinite loading mask) and the Paystack method appears on the payment step. This is the regression guard for the “checkout does not load” class of failure; the prior suite only covered the admin config screen.

## Action groups

- **LoginToAdminActionGroup** – Self-contained admin login (navigate to admin, fill username/password, click login). Used so the vendor test does not depend on Magento_Backend during MFTF generation.
