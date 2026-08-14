# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Module Identity

- **Module name**: `Pstk_Paystack`
- **PHP namespace**: `Pstk\Paystack`
- **Composer package**: `pstk/paystack-magento2-module`
- **Magento payment method code**: `pstk_paystack` (constant `Pstk\Paystack\Model\Payment\Paystack::CODE`)
- **Requires**: Magento 2.4.x, PHP 8.2+ (this is the supported target, but note `composer.json` has an empty `require: {}` — these constraints are **not** enforced by Composer)

## Build

```bash
# Build zip for Adobe Commerce Marketplace
./build-adobe-zip.sh
# Output: pstk-paystack-magento2-module-<version>.zip
```

`build-adobe-zip.sh` reads the version from `composer.json` and always rebuilds from scratch (removes any stale zip first). Its exclusion list is `.git*`, `.DS_Store`, `.claude/`, `dev/`, `dev-ee/`, `dev-repro/`, `marketplace/`, `vendor/`, `.env`, `auth.json`, `CLAUDE.md`, `docs/`, `graphify-out/`, `node_modules/`, the build script itself, and prior `*.zip` builds — so `CLAUDE.md`, internal QA artifacts, and tooling caches never ship to Marketplace.

> ⚠️ **Anything added to the repo root after the build script was written must be added to its exclusion list explicitly.** This has already gone wrong once: `dev-ee/` was created after the script and had to be retro-fitted before a release could ship without bundling the entire EE harness. When you add a new top-level directory that is not package content, add its `-x` line **in the same commit**.

### Versioning

The version string lives in **three** places that must be kept in sync on a version bump:
- `composer.json` (`version`) — source of truth used by the build script
- `etc/module.xml` (`setup_version` on `<module>`)
- `README.md` (the **Version:** line)

The Marketplace documentation is deliberately **not** a fourth: `marketplace/bin/build-guide.sh` injects the version from `composer.json` at build time, and no file in `marketplace/src/` contains a version string. Keep it that way.

## Marketplace Listing Collateral

`marketplace/` holds everything uploaded to the Adobe submission that is **not** the extension package, laid out as `src/` (Markdown sources — the tracked originals), `pdf/` (generated, **gitignored** like the package zip), and `bin/` (build script, stdlib-only Markdown converter, print CSS), plus `long-description.md` recording the listing copy.

Three documents are uploaded, matching Adobe's slots: **Installation Guide** (getting it installed), **User Guide** (configuring and operating), **Reference Manual** (config paths, routes, events, webhook signature, CSP hosts, DI scoping). They must stay distinct — the marketing review guidelines reject duplicate documents while also requiring documentation to cover all features, so content belongs in exactly one and is cross-referenced from the others.

Regenerate with `./marketplace/bin/build-guide.sh` (needs `python3` and any Chromium-family browser). Run it before every upload — a fresh checkout has no PDFs, which is deliberate: a committed PDF goes stale silently when a source changes without a rebuild.

Conventions there are load-bearing, from Adobe's August 2026 marketing-review rejection of submission `fc2xb678ho`: document titles read **"Paystack Payments for Magento 2"** with the document type as subtitle, never Magento-first; no Adobe or Magento logos; and Long Description bullets must be re-entered using the Marketplace editor's own bullet button rather than pasted. See `marketplace/README.md`.

The guides are merchant-facing and intentionally diverge from `README.md` — they omit the Docker development environment and contribution sections. Changes to one do not automatically belong in the other.

## Development Environment

```bash
cd dev
cp .env.example .env
docker compose up -d
bash setup.sh
```

The `dev/` directory contains a full Docker-based Magento 2 environment (Magento 2.4.8 via the Mage-OS mirror — no Adobe Marketplace auth needed). `docker compose up -d` builds the image and installs Magento on first run (~8 min total); `setup.sh` enables the module, disables 2FA, sets developer mode, and seeds test data (`dev/seed-products.php`). Containers: `paystack-magento`, `paystack-db`, `paystack-search`.

- Storefront: `http://localhost:8080` · Admin: `http://localhost:8080/admin` (`admin` / `Admin12345!`)
- Paystack test card: `4084 0840 8408 4081`, exp `12/30`, CVV `408`, PIN `0000`, OTP `123456`
- `docker compose down -v` resets all data.

The local `dev/` env uses the Mage-OS **Community Edition** mirror. It **cannot** reproduce Adobe Commerce (Enterprise) issues — those depend on EE-only layers (Varnish FPC, Content Staging). For EE-specific reproduction there is a separate `dev-ee/` harness (see below).

### `dev-ee/` — Enterprise baseline harness

`dev-ee/` is a standalone EE + Varnish + Selenium + MFTF harness (`run-ee-baseline.sh`) built to determine whether two EE-only MFTF failures reported in Adobe's QA (`MC-84` AdminConfigurableProductCreateTest, `MC-26602` AdminCreateGroupedProductTest) are caused by this module or are pre-existing EE core-test flakiness. It runs each test N times in two arms (no-module vs with-module) and compares failure rates. It is the empirical counterpart to `docs/EE-NO-MODULE-BASELINE.md`.

**Status:** written without valid Adobe Commerce keys and **never executed end-to-end** — the local `auth.json` keys return HTTP 401, and `check-ee-keys.sh` preflight refuses to run until they work. Treat `run-ee-baseline.sh` as a runbook, not a one-shot; fragile spots are marked `SEAM:` with manual fallbacks in its README.

## Testing

Tests use the Magento Functional Testing Framework (MFTF), located in `Test/Mftf/`. MFTF tests run against a live Magento instance:

```bash
# From Magento root (not this repo root)
vendor/bin/mftf run:test PaystackPaymentConfigAvailableTest
vendor/bin/mftf run:test StorefrontPaystackCheckoutRendersTest
```

Current tests (`Test/Mftf/Test/`): `PaystackPaymentConfigAvailableTest.xml` and `StorefrontPaystackCheckoutRendersTest.xml`, backed by the page object `Test/Mftf/Page/AdminPaymentConfigPage.xml`. `Test/Mftf/Suite/` exists but is **empty** — there are no suites, so `vendor/bin/mftf run:suite` has nothing to run.

There are no unit tests — the test suite is entirely MFTF (browser-level functional tests). There is no configured linter or static-analysis tooling (no PHPCS/PHPStan config, no composer `scripts`); match the surrounding code style by hand. The only CI is `.github/workflows/codeql-analysis.yml` (CodeQL security scanning).

The `docs/` directory (gitignored, never shipped) holds local MFTF Allure report artifacts (`mftfmagento/`, `mftfvendor/`) plus the `EE-NO-MODULE-BASELINE.md` analysis — it is not module code.

## Architecture

### Payment Flows

There are two integration types, selectable in admin config:

**Inline (default)**: Paystack popup opens in the browser after order is placed.
1. JS calls `afterPlaceOrder()` → Paystack popup opens
2. On success, JS calls `GET /V1/paystack/verify/{reference}_{quoteId}` (REST API, anonymous)
3. `PaymentManagement::verifyPayment()` verifies with Paystack API, dispatches `paystack_payment_verify_after`
4. `ObserverAfterPaymentVerify` sets order to Processing and sends confirmation email

**Standard (redirect)**: Customer is redirected to Paystack's hosted page.
1. `/paystack/payment/setup` — initializes transaction, redirects to Paystack
2. `/paystack/payment/callback` — Paystack returns here; verifies transaction, dispatches `paystack_payment_verify_after`
3. `/paystack/payment/recreate` — retry path: cancels the failed/abandoned order, restores the quote, and redirects back to the checkout payment step

**Webhook** (independent, server-to-server):
- `/paystack/payment/webhook` — receives `charge.success` events from Paystack
- Validates HMAC-SHA512 signature, verifies transaction, dispatches `paystack_payment_verify_after`
- CSRF validation skipped via `Plugin/CsrfValidatorSkip.php`

The custom event `paystack_payment_verify_after` is the single point where order status is updated to Processing and confirmation email is sent (`Observer/ObserverAfterPaymentVerify.php`). Initial order confirmation email is suppressed by `ObserverBeforeSalesOrderPlace` until payment is verified.

### Key Classes

| Class | Responsibility |
|---|---|
| `Gateway/PaystackApiClient.php` | All Paystack API calls: initialize transaction, verify, validate webhook signature |
| `Model/PaymentManagement.php` | REST API endpoint for inline payment verification |
| `Model/Ui/ConfigProvider.php` | Injects public key, integration type, and URLs into checkout JS config |
| `Controller/Payment/AbstractPaystackStandard.php` | Base controller with shared utilities (quote loading, message handling) |
| `etc/csp_whitelist.xml` | Whitelists Paystack domains in Magento's Content Security Policy (additive; the Magento-standard mechanism) |

### DI Configuration Scoping

This is critical — the payment method is intentionally unavailable in admin order creation:

- `etc/frontend/di.xml` — registers `ConfigProvider`, `PaymentManagementInterface` preference, CSRF-skip plugin (CSP is handled by `etc/csp_whitelist.xml`, not here)
- `etc/adminhtml/di.xml` — intentionally empty (prevents EE admin crash on order create)
- `etc/webapi_rest/di.xml` — `PaymentManagementInterface` preference for REST API calls
- `etc/di.xml` — root scope (minimal)

### Configuration Path

All settings live under `payment/pstk_paystack/` in Magento config. Secret keys use the `Encrypted` backend model. Test mode toggles between test/live key pairs in `PaystackApiClient`.

### Quote ID as Transaction Anchor

For inline payments, Paystack generates the transaction reference on the client side. The `quoteId` is passed as metadata in the Paystack transaction so the webhook/verification can locate the correct order when no Magento-generated reference is available.
