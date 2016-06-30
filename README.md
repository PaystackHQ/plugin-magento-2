magento2-Profibro_Paystack
======================

Paystack payment gateway Magento2 extension

Install
=======

1. Go to Magento2 root folder

2. Enter following commands to install module:

    ```bash
    composer config repositories.profibropaystack git https://github.com/ibrahimlawal/magento2-Profibro_Paystack.git
    composer require profibro/paystack:dev-master
    ```
   Wait while dependencies are updated.

3. Enter following commands to enable module:

    ```bash
    php bin/magento module:enable Profibro_Paystack --clear-static-content
    php bin/magento setup:upgrade
    ```
4. Enable and configure Paystack in Magento Admin under Stores/Configuration/Payment Methods/Paystack

Other Notes
===========

**Paystack works with NGN only!** If NGN is not your base currency, you will not see this module on checkout pages.
