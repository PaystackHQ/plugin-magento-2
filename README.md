magento2-Paystack_Inline
======================

Paystack Inline payment gateway Magento2 extension

Install
=======

1. Go to Magento2 root folder

2. Enter following commands to install module:

    ```bash
    composer config repositories.paystackinline git https://github.com/ibrahimlawal/magento2-Paystack_Inline.git
    composer require paystack/inline:dev-develop
    ```
   Wait while dependencies are updated.

3. Enter following commands to enable module:

    ```bash
    php bin/magento module:enable Paystack_Inline --clear-static-content
    php bin/magento setup:upgrade
    ```
4. Enable and configure Paystack Inline in Magento Admin under Stores/Configuration/Payment Methods/Inline

Other Notes
===========

**Paystack works with NGN only!** If NGN is not your base currency, you will not see this module on checkout pages.
