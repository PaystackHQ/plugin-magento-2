/*browser:true*/
/*global define*/
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'profibro_paystack',
                component: 'Profibro_Paystack/js/view/payment/method-renderer/profibro_paystack'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);
