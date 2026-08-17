[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
[![Total Downloads][ico-downloads]][link-downloads]

## Paystack Payments for Magento 2

Paystack payment gateway extension for Magento 2

**Version:** 3.0.11 (Paystack v2 Inline.js API)

## Requirements

- Magento 2.4.x
- PHP 8.2+

## Installation

### Composer (Recommended)

Go to your Magento2 root folder and run:

```bash
composer require pstk/paystack-magento2-module
php bin/magento module:enable Pstk_Paystack
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

### Manual Installation

Copy all files to `app/code/Pstk/Paystack/` in your Magento installation, then run:

```bash
php bin/magento module:enable Pstk_Paystack
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

## Configuration

To configure the plugin in *Magento Admin*:
1. Go to **Stores > Configuration > Sales > Payment Methods**.
2. Find **Paystack** and configure:
	- **Enabled**: Yes/No
	- **Title**: What customers see at checkout
	- **Integration Type**: Inline (Popup) or Redirect
	- **Test Mode**: Enable for sandbox testing
	- **Test/Live Secret Key**: Get from your [Paystack dashboard](https://dashboard.paystack.com/#/settings/developer)
	- **Test/Live Public Key**: Get from your [Paystack dashboard](https://dashboard.paystack.com/#/settings/developer)
3. Click **Save Config**.

### Webhook Setup

For reliable payment confirmation (especially for the redirect flow), set up a webhook in your Paystack dashboard:

1. Go to **Settings > API Keys & Webhooks** on your [Paystack dashboard](https://dashboard.paystack.com/#/settings/developer)
2. Set the Webhook URL to: `https://yourdomain.com/paystack/payment/webhook`
3. The module handles `charge.success` events and automatically updates order status

## Development Environment

A Docker-based development environment is included in the `dev/` directory for contributors and testing.

### Prerequisites

- [Docker](https://www.docker.com/) (or [Rancher Desktop](https://rancherdesktop.io/) with `dockerd` runtime)
- A [Paystack test account](https://dashboard.paystack.com/#/signup)

### Quick Start

```bash
cd dev
cp .env.example .env     # Add your Paystack test keys
docker compose up -d      # First run builds the image (~5 min) and installs Magento (~3 min)
bash setup.sh             # Enables module, creates test products, configures everything
```

Once complete you'll see:

```
============================================
  Setup complete!

  Storefront:  http://localhost:8080
  Admin panel: http://localhost:8080/admin
  Admin login: admin / Admin12345!

  Test card:   4084 0840 8408 4081
  Expiry:      12/30
  CVV:         408
  PIN:         0000
  OTP:         123456
============================================
```

### What's Included

- **Magento 2.4.8-p3** via [Mage-OS](https://mage-os.org/) public mirror (no Adobe marketplace auth needed)
- **OpenSearch 2.19.1** + **MariaDB 10.6**
- **5 test products** with images and a configured homepage
- **Paystack payment** pre-configured in test mode (inline popup)
- Container names: `paystack-magento`, `paystack-db`, `paystack-search`

### Tear Down

```bash
cd dev
docker compose down        # Stop containers (preserves data)
docker compose down -v     # Stop containers and delete all data
```

## Documentation

* [Paystack Documentation](https://developers.paystack.co/v2.0/docs/)
* [Paystack Helpdesk](https://paystack.com/help)

## Support

For bug reports and feature requests directly related to this plugin, please use the [issue tracker](https://github.com/PaystackHQ/plugin-magento-2/issues).

For general support or questions about your Paystack account, you can reach out by sending a message from [our website](https://paystack.com/contact).

## Community

If you are a developer, please join our Developer Community on [Slack](https://slack.paystack.com).

## Contributing to the Magento 2 plugin

If you have a patch or have stumbled upon an issue with the Magento 2 plugin, you can contribute this back to the code. Please read our [contributor guidelines](https://github.com/PaystackHQ/plugin-magento-2/blob/master/CONTRIBUTING.md) for more information how you can do this.

[ico-version]: https://img.shields.io/packagist/v/pstk/paystack-magento2-module.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/pstk/paystack-magento2-module.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/pstk/paystack-magento2-module
[link-downloads]: https://packagist.org/packages/pstk/paystack-magento2-module
