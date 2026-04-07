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

## Tests

- **PaystackPaymentConfigAvailableTest** – Logs in to admin (using our LoginToAdminActionGroup), opens Stores → Configuration → Sales → Payment Methods, and asserts that “Paystack” is visible.

## Action groups

- **LoginToAdminActionGroup** – Self-contained admin login (navigate to admin, fill username/password, click login). Used so the vendor test does not depend on Magento_Backend during MFTF generation.
