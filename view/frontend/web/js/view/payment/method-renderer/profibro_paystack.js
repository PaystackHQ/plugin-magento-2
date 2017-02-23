/*browser:true*/
/*global define*/
define(
    [
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/model/quote'
    ],
    function ($, Component, placeOrderAction, additionalValidators, quote, fullScreenLoader) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Profibro_Paystack/payment/form',
                customObserverName: null
            },

            initialize: function () {
                this._super();
                // Add Profibro Gateway script to head
                $("head").append('<script src="https://js.paystack.co/v1/inline.js">');
                return this;
            },

            getCode: function () {
                return 'profibro_paystack';
            },

            getData: function () {
                return {
                    'method': this.item.method,
                    'additional_data': {}
                };
            },

            isActive: function() {
                return true;
            },

            /**
             * @override
             */
            placeOrder: function () {
                var checkoutConfig = window.checkoutConfig;
                var paymentData = quote.billingAddress();
                var profibroPaystackConfiguration = checkoutConfig.payment.profibro_paystack;

                if (checkoutConfig.isCustomerLoggedIn) {
                    var customerData = checkoutConfig.customerData;
                    paymentData.email = customerData.email;

                } else {
                    var storageData = JSON.parse(localStorage.getItem('mage-cache-storage'))['checkout-data'];
                    paymentData.email = storageData.validatedEmailValue;
                }

                var _this = this;
                var handler = PaystackPop.setup({
                  key: profibroPaystackConfiguration.public_key,
                  email: paymentData.email,
                  amount: checkoutConfig.quoteData.grand_total * 100,
                  phone: paymentData.telephone,
                  currency: checkoutConfig.quoteData.store_currency_code,
                  metadata: {
                     custom_fields: [
                        {
                            display_name: "Address",
                            variable_name: "address",
                            value: paymentData.street[0] + ', ' + paymentData.street[1]
                        },
                        {
                            display_name: "Postal Code",
                            variable_name: "postal_code",
                            value: paymentData.postcode
                        },
                        {
                            display_name: "City",
                            variable_name: "city",
                            value: paymentData.city + ', ' + paymentData.countryId
                        },
                     ]
                  },
                  callback: function(response){
                        $.ajax({
                            method: 'GET',
                            url: profibroPaystackConfiguration.api_url + 'paystack/verify/' + response.reference,
                        }).success(function () {
                            _this.processPayment();
                        });
                  }
                });
                handler.openIframe();
            },

            processPayment: function () {
                var self = this,
                    placeOrder;

                if (this.validate() && additionalValidators.validate()) {
                    this.isPlaceOrderActionAllowed(false);
                    placeOrder = placeOrderAction(this.getData(), this.messageContainer);
                    $.when(placeOrder).fail(function () {
                        self.isPlaceOrderActionAllowed(true);
                    }).done(
                        function () {
                            self.afterPlaceOrder();
                        }
                    );

                    return true;
                }


                return false;
            },
        });
    }
)
;
