# Paystack Payments for Magento 2

Installation Guide

<!-- Version is injected from composer.json at build time. Never hardcode it here. -->

## Requirements

- Magento Open Source or Adobe Commerce 2.4.x
- PHP 8.2 or later
- A [Paystack account](https://dashboard.paystack.com/#/signup)
- Command-line access to your Magento installation

## Before You Begin

Collect your API keys from the Paystack dashboard under **Settings > API Keys & Webhooks**. You will need both a public key and a secret key. Paystack issues two pairs — one for test mode and one for live mode — and you can install the extension with either.

Take a backup of your Magento installation and database before installing on a production store.

## Install with Composer

This is the recommended method. From your Magento root folder:

```
composer require pstk/paystack-magento2-module
php bin/magento module:enable Pstk_Paystack
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

If your store runs in production mode, redeploy static content afterwards:

```
php bin/magento setup:static-content:deploy -f
```

## Install Manually

Use this method if you downloaded the extension package rather than installing from a repository.

1. Extract the package into `app/code/Pstk/Paystack/` in your Magento installation. The folder should contain `registration.php` at its top level.
2. From your Magento root folder, run:

```
php bin/magento module:enable Pstk_Paystack
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

3. In production mode, redeploy static content as shown above.

## Verify the Installation

Confirm the module is registered and enabled:

```
php bin/magento module:status Pstk_Paystack
```

The command should report the module as enabled. You can also check **Stores > Configuration > Sales > Payment Methods** in the Magento Admin, where a **Paystack** section should now appear.

The extension is installed but not yet active. It will not appear at checkout until you enable and configure it — see the User Guide.

## Upgrade

```
composer update pstk/paystack-magento2-module
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

For a manual installation, replace the contents of `app/code/Pstk/Paystack/` with the new version and run the same commands. Your configuration is stored in the Magento database and is preserved across upgrades.

## Uninstall

Disable the module and clear the generated code:

```
php bin/magento module:disable Pstk_Paystack
php bin/magento setup:upgrade
php bin/magento cache:flush
```

For a Composer installation, remove the package with `composer remove pstk/paystack-magento2-module`. For a manual installation, delete `app/code/Pstk/Paystack/`.

Disabling the module leaves its stored configuration in place, so re-enabling later restores your settings.

## Installation Troubleshooting

**The module does not appear in `module:status`.** Confirm the files are at `app/code/Pstk/Paystack/` and that `registration.php` sits at the top of that folder, then re-run `php bin/magento setup:upgrade`.

**`setup:di:compile` fails.** Remove the generated code and compile again:

```
rm -rf generated/code generated/metadata
php bin/magento setup:di:compile
```

**The Paystack section is missing from the Admin.** Flush the cache with `php bin/magento cache:flush`, then reload the configuration page. If you are running in production mode, redeploy static content.

**Changes do not take effect.** Magento caches configuration aggressively. Run `php bin/magento cache:flush` after any change made outside the Admin.

## Next Steps

With installation complete, see the **User Guide** to enable the extension, add your API keys, choose an integration type, and set up webhooks.

## Support

For issues with this extension, use the [issue tracker](https://github.com/PaystackHQ/plugin-magento-2/issues).

For questions about your Paystack account, send a message from [our website](https://paystack.com/contact).
