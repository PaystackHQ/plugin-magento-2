/**
 * Profibro_Paystack Magento JS component
 *
 * @category    Profibro
 * @package     Profibro_Paystack
 * @author      Ibrahim Lawal
 * @copyright   Ibrahim Lawal (http://ibrahim.lawal.me)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
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
                component: 'Profibro_Paystack/js/view/payment/method-renderer/paystack-method'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);