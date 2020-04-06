[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
[![Total Downloads][ico-downloads]][link-downloads]

## Paystack Magento 2 Module

Paystack payment gateway Magento2 extension

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

## Known Errors

* Fail to redirect to success page after successful payment

Sometimes after receiving payment for an order you get an error like: `Class Yabacon\Paystack not found` 
and magento doesn't redirect to the `success` page.

** Fix:
Run the following command:

```bash
composer require yabacon/paystack-php
```

* Enable and configure `Paystack` in *Magento Admin* under `Stores/Configuration/Payment` Methods

[ico-version]: https://img.shields.io/packagist/v/pstk/paystack-magento2-module.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/pstk/paystack-magento2-module.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/pstk/paystack-magento2-module
[link-downloads]: https://packagist.org/packages/pstk/paystack-magento2-module


## Running the magento2 on docker
Contained within this repo, is a dockerfile and a docker-compose file to quickly spin up a magento2 and mysql container with the paystack plugin installed.

### Prerequisites
- Install [Docker](https://www.docker.com/)

### Quick Steps
- Create a `.env` file off the `.env.sample` in the root directory. Replace the `*******` with the right values
- Run `docker-compose up` from the root directory to build and start the mysql and magento2 containers.
- Visit `localhost:8000` on your browser to access the magento store. For the admin backend, visit `localhost:8000/<MAGENTO_BACKEND_FRONTNAME>` where `MAGENTO_BACKEND_FRONTNAME` is the value you specified in your `.env` file
- Run `docker-compose down` from the root directory to stop the containers.


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

