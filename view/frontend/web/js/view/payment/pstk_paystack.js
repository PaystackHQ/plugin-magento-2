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
                type: 'pstk_paystack',
                component: 'Pstk_Paystack/js/view/payment/method-renderer/pstk_paystack-method'
            }
        );

        /** Add view logic here if needed */
        
        return Component.extend({});
    }
);