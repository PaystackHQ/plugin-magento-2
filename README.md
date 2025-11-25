[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
[![Total Downloads][ico-downloads]][link-downloads]

## Paystack Magento 2 Module

Paystack payment gateway Magento2 extension

**Version:** 2.5.0 (Paystack v2 Inline.js API)

## Requirements

- Magento 2.x
- PHP 8.3+
- yabacon/paystack-php v2.2.0 or newer

## Installation

### Composer (Recommended)

Go to your Magento2 root folder and run:

```bash
composer require pstk/paystack-magento2-module:^2.5.0
php bin/magento module:enable Pstk_Paystack
php bin/magento setup:upgrade
php bin/magento cache:flush
```

### Manual Installation (Custom Source)

Copy all files from your source folder (`plugin-magento-2`) to `app/code/Pstk/Paystack/` in your Magento installation.

Then run:
```bash
php bin/magento module:enable Pstk_Paystack
php bin/magento setup:upgrade
php bin/magento cache:flush
```

## Install

* Go to Magento2 root folder

* Enter following command to install module:

```bash
composer require pstk/paystack-magento2-module
```

* Wait while dependencies are updated.

* Enter following commands to enable module:

```bash
php bin/magento module:enable Pstk_Paystack --clear-static-content
php bin/magento setup:upgrade
php bin/magento setup:di:compile
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

**Note:** Inline (Popup) uses Paystack v2 Inline.js API. Make sure your CSP whitelist and RequireJS config are updated as shown in the migration guide.

![Magento Settings](https://res.cloudinary.com/drps6uoe4/image/upload/v1617968546/Screenshot_2021-04-09_at_10.51.31_outbpi.png)

## Known Errors

Sometimes after receiving payment for an order you get an error like: Class Yabacon\Paystack not found and magento doesn't redirect to the `success` page.

**Fix:** 

Run:
```bash
composer require yabacon/paystack-php
```
    Enable and configure Paystack in Magento Admin under Stores/Configuration/Sales/Payment Methods

**Fail to redirect to success page after payment**

Ensure you are using Paystack v2 Inline.js and your CSP/RequireJS configs are correct.

[ico-version]: https://img.shields.io/packagist/v/pstk/paystack-magento2-module.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/pstk/paystack-magento2-module.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/pstk/paystack-magento2-module
[link-downloads]: https://packagist.org/packages/pstk/paystack-magento2-module


## Running the magento2 on docker

Contained within this repo is a Dockerfile and a docker-compose file to quickly spin up Magento 2 and MySQL containers with the Paystack plugin installed.

### Prerequisites
- Install [Docker](https://www.docker.com/)

### Quick Steps

- Create a `.env` file from `.env.sample` in the root directory. Fill in your values.
- Run `docker-compose up` from the root directory to build and start the containers.
- Visit `localhost:8000` for the Magento store. For admin, visit `localhost:8000/<MAGENTO_BACKEND_FRONTNAME>` (set in `.env`).
- Run `docker-compose down` to stop the containers.


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

