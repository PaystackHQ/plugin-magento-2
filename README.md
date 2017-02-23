magento2-Profibro_Paystack
======================

Paystack payment gateway Magento2 extension

Install
=======

1. Go to Magento2 root folder

2. Enter following command to install module:

```bash
composer require profibro/magento2-module-paystack
```

3. Wait while dependencies are updated.

4. Enter following commands to enable module:

```bash
php bin/magento module:enable Profibro_Paystack --clear-static-content
php bin/magento setup:upgrade
php bin/magento setup:di:compile
```

5. Enable and configure `Paystack` in *Magento Admin* under `Stores/Configuration/Payment` Methods
