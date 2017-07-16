/*browser:true*/
/*global define*/
define(
    [
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Checkout/js/action/redirect-on-success'
    ],
    function ($, Component, placeOrderAction, additionalValidators, quote, fullScreenLoader, redirectOnSuccessAction) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Profibro_Paystack/payment/form',
                customObserverName: null
            },
            
            redirectAfterPlaceOrder : true,

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
                
                var quoteId = checkoutConfig.quoteItemData[0].quote_id;

                var _this = this;
                _this.isPlaceOrderActionAllowed(false);
                var handler = PaystackPop.setup({
                  key: profibroPaystackConfiguration.public_key,
                  email: paymentData.email,
                  amount: Math.ceil(quote.totals().grand_total * 100), // get order total from quote for an accurate... quote
                  phone: paymentData.telephone,
                  currency: checkoutConfig.totalsData.quote_currency_code,
                  metadata: {
                     quoteId: quoteId,
                     custom_fields: [
                        {
                         display_name: "QuoteId",
                         variable_name: "quote id",
                         value: quoteId
                        },
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
                        }
                     ]
                  },
                  callback: function(response){
                        $.ajax({
                            method: 'GET',
                            url: profibroPaystackConfiguration.api_url + 'paystack/verify/' + response.reference + '_-~-_' + quoteId
                        }).success(function (data) {
                            
                            data = JSON.parse(data);
                            
                            if (data.status) {
                                if (data.data.status === 'success') {
                                    _this.processPayment();
                                    return;
                                }
                            }
                            
                            _this.isPlaceOrderActionAllowed(true);
                            _this.messageContainer.addErrorMessage({
                                message: "Error, please try again"
                            });
                                
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

                            if (self.redirectAfterPlaceOrder) {
                                redirectOnSuccessAction.execute();
                            }
                        }
                    );

                    return true;
                }

                return false;
            }
        });
    }
);
