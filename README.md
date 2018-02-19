[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
[![Total Downloads][ico-downloads]][link-downloads]

Paystack Magento 2 Module
======================

Paystack payment gateway Magento2 extension

Install
=======

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

Known Errors
============

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
