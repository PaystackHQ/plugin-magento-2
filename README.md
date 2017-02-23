magento2-Profibro_Paystack
======================

Paystack payment gateway Magento2 extension

Install
=======

* Go to Magento2 root folder

* Enter following command to install module:

```bash
composer require profibro/magento2-module-paystack
```

* Wait while dependencies are updated.

* Enter following commands to enable module:

```bash
php bin/magento module:enable Profibro_Paystack --clear-static-content
php bin/magento setup:upgrade
php bin/magento setup:di:compile
```

* Enable and configure `Paystack` in *Magento Admin* under `Stores/Configuration/Payment` Methods
