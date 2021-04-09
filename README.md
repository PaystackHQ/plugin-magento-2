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

## Configuration

To configure the plugin in *Magento Admin* , go to __Stores > Configuration__ from the left hand menu, then click __Payment Methods__ from the list of options. You will see __Paystack__ as part of the available Payment Methods. Click on it to configure the payment gateway.

* __Enabled__ - Select _Yes_ to enable Paystack Payment Gateway.
* __Title__ - allows you to determine what your customers will see this payment option as on the checkout page.
* __Integration Type__ - allows you to select the type of checkout experience you want on your website. Select _Inline(Popup)_ if you want your customers to checkout while still on your website, and _Redirect_ to be redirected to the payment gateway's checkout
* __Test Mode__ - Check to enable test mode. Test mode enables you to test payments before going live. If you ready to start receving real payment on your site, kindly uncheck this.
* __Test Secret Key__ - Enter your Test Secret Key here. Get your API keys from your [Paystack account under Settings > Developer/API](https://dashboard.paystack.com/#/settings/developer)
* __Test Public Key__ - Enter your Test Public Key here. Get your API keys from your [Paystack account under Settings > Developer/API](https://dashboard.paystack.com/#/settings/developer)
* __Live Secret Key__ - Enter your Live Secret Key here. Get your API keys from your [Paystack account under Settings > Developer/API](https://dashboard.paystack.com/#/settings/developer)
* __Live Public Key__ - Enter your Live Public Key here. Get your API keys from your [Paystack account under Settings > Developer/API](https://dashboard.paystack.com/#/settings/developer) 
* Click on __Save Config__ for the changes you made to be effected.

![Magento Settings](https://res.cloudinary.com/drps6uoe4/image/upload/v1617968546/Screenshot_2021-04-09_at_10.51.31_outbpi.png)

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

