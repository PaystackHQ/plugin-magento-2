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
        'jquery'
    ],
    function (Component, $) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Profibro_Paystack/payment/paystack-form'
            },

            getCode: function() {
                return 'profibro_paystack';
            },

            isActive: function() {
                return true;
            },

            payWithPaystack: function(){
              var handler = PaystackPop.setup({
                key: 'pk_test_86d32aa1nV4l1da7120ce530f0b221c3cb97cbcc',
                email: 'customer@email.com',
                amount: 10000,
                callback: function(response){
                    alert('success. transaction ref is ' + response.trxref);
                },
                onClose: function(){
                    alert('window closed');
                }
              });
              handler.openIframe();
            }

            validate: function() {
                return true;
            }
        });
    }
);
