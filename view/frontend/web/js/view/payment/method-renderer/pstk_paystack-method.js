define(
        [
            "jquery",
            'mage/url',
            "Magento_Checkout/js/view/payment/default",
            "Magento_Checkout/js/action/place-order",
            "Magento_Checkout/js/model/payment/additional-validators",
            "Magento_Checkout/js/model/quote",
            "Magento_Checkout/js/model/full-screen-loader",
            "Magento_Checkout/js/action/redirect-on-success"
        ],
        function (
                $,
                mageUrl,
                Component,
                placeOrderAction,
                additionalValidators,
                quote,
                fullScreenLoader,
                redirectOnSuccessAction
                ) {
            'use strict';

            return Component.extend({
                defaults: {
                    template: 'Pstk_Paystack/payment/pstk_paystack'
                },

                redirectAfterPlaceOrder: false,

                isActive: function () {
                    return true;
                },

                /**
                 * Provide redirect to page
                 */
                redirectToCustomAction: function (url) {
                    fullScreenLoader.startLoader();
                    window.location.replace(mageUrl.build(url));
                },

                /**
                 * @override
                 */
                afterPlaceOrder: function () {
                    var checkoutConfig = window.checkoutConfig;
                    var paystackConfiguration = checkoutConfig.payment && checkoutConfig.payment.pstk_paystack;

                    if (!paystackConfiguration) {
                        this.messageContainer.addErrorMessage({
                            message: "Paystack configuration is missing. Please contact support."
                        });
                        this.isPlaceOrderActionAllowed(true);
                        return;
                    }

                    if (paystackConfiguration.integration_type == 'standard') {
                        this.redirectToCustomAction(paystackConfiguration.integration_type_standard_url);
                        return;
                    }

                    var paymentData = quote.billingAddress();

                    if (!paymentData) {
                        this.messageContainer.addErrorMessage({
                            message: "Billing address is required to process payment."
                        });
                        this.isPlaceOrderActionAllowed(true);
                        return;
                    }

                    if (checkoutConfig.isCustomerLoggedIn) {
                        var customerData = checkoutConfig.customerData;
                        paymentData.email = customerData.email;
                    } else {
                        paymentData.email = quote.guestEmail;
                    }

                    var quoteItems = checkoutConfig.quoteItemData;
                    var quoteId = quoteItems && quoteItems.length > 0 ? quoteItems[0].quote_id : null;

                    if (!quoteId) {
                        this.messageContainer.addErrorMessage({
                            message: "Unable to identify your cart. Please refresh and try again."
                        });
                        this.isPlaceOrderActionAllowed(true);
                        return;
                    }

                    var streetLines = paymentData.street || [];
                    var streetAddress = [streetLines[0] || '', streetLines[1] || '']
                        .filter(Boolean).join(', ');

                    var _this = this;
                    _this.isPlaceOrderActionAllowed(false);

                    // Stop any loader left active by placeOrderAction before opening the popup.
                    fullScreenLoader.stopLoader();

                    require(['paystack'], function () {
                        var PaystackPop = window.PaystackPop;
                        if (typeof PaystackPop !== 'function') {
                            fullScreenLoader.stopLoader();
                            _this.isPlaceOrderActionAllowed(true);
                            _this.messageContainer.addErrorMessage({
                                message: "Unable to load Paystack. Please check your internet connection and try again."
                            });
                            return;
                        }
                        var popup = new PaystackPop();
                        popup.newTransaction({
                            key: paystackConfiguration.public_key,
                            email: paymentData.email,
                            amount: Math.round(quote.totals().grand_total * 100),
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
                                        value: streetAddress
                                    },
                                    {
                                        display_name: "Postal Code",
                                        variable_name: "postal_code",
                                        value: paymentData.postcode
                                    },
                                    {
                                        display_name: "City",
                                        variable_name: "city",
                                        value: paymentData.city + ", " + paymentData.countryId
                                    },
                                    {
                                        display_name: "Plugin",
                                        variable_name: "plugin",
                                        value: "magento-2"
                                    }
                                ]
                            },
                            onSuccess: function (response) {
                                fullScreenLoader.startLoader();
                                $.ajax({
                                    method: "GET",
                                    url: paystackConfiguration.api_url + "V1/paystack/verify/" + response.reference + "_-~-_" + quoteId,
                                    dataType: 'text'
                                }).done(function (data) {
                                    try {
                                        data = JSON.parse(data);
                                        if (typeof data === 'string') {
                                            data = JSON.parse(data);
                                        }
                                    } catch (e) {
                                        console.error('Payment verification JSON parse error:', e);
                                        fullScreenLoader.stopLoader();
                                        _this.isPlaceOrderActionAllowed(true);
                                        _this.messageContainer.addErrorMessage({
                                            message: "Payment verification error."
                                        });
                                        return;
                                    }

                                    $.ajax({
                                        method: 'POST',
                                        url: "https://plugin-tracker.paystackintegrations.com/log/charge_success",
                                        data: {
                                            plugin_name: 'magento-2',
                                            transaction_reference: response.reference,
                                            public_key: paystackConfiguration.public_key
                                        }
                                    });

                                    if (data.status && data.data && data.data.status === "success") {
                                        redirectOnSuccessAction.execute();
                                        return;
                                    }
                                    fullScreenLoader.stopLoader();
                                    _this.isPlaceOrderActionAllowed(true);
                                    _this.messageContainer.addErrorMessage({
                                        message: "Payment verification failed. Status: " + (data.data ? data.data.status : 'unknown')
                                    });
                                }).fail(function () {
                                    fullScreenLoader.stopLoader();
                                    _this.isPlaceOrderActionAllowed(true);
                                    _this.messageContainer.addErrorMessage({
                                        message: "Payment verification failed."
                                    });
                                });
                            },
                            onCancel: function () {
                                _this.redirectToCustomAction(paystackConfiguration.recreate_quote_url);
                            }
                        });
                    }, function () {
                        fullScreenLoader.stopLoader();
                        _this.isPlaceOrderActionAllowed(true);
                        _this.messageContainer.addErrorMessage({
                            message: "Unable to load Paystack. Please check your internet connection and try again."
                        });
                    });
                },

            });
        }
);
