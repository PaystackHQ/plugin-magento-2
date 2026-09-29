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

            // Client-side fallback only: shown when the server's own tailored
            // `message` is unavailable (parse failure, network failure, or a
            // response that omits it). One copy, referenced everywhere it's needed.
            var PAYMENT_UNCONFIRMED_MESSAGE =
                "We could not confirm your payment. Please do not pay again — contact support with your order number.";

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
                                // Invariant: everything below this point runs AFTER Paystack has
                                // taken the customer's money, so this handler fails closed — only
                                // an explicit `data.final === false` from the verify response
                                // re-enables the pay button; re-enabling on anything else invites
                                // a double charge.
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
                                        // Can't prove the charge failed — terminal by default.
                                        _this.messageContainer.addErrorMessage({
                                            message: PAYMENT_UNCONFIRMED_MESSAGE
                                        });
                                        return;
                                    }

                                    if (data.status && data.data && data.data.status === "success") {
                                        // Only reported to Paystack's plugin tracker once
                                        // verification actually succeeded — this call used to
                                        // fire unconditionally, above, before the terminal-
                                        // failure branch below was even evaluated, so a
                                        // rejected settlement still reported a successful
                                        // charge.
                                        $.ajax({
                                            method: 'POST',
                                            url: "https://plugin-tracker.paystackintegrations.com/log/charge_success",
                                            data: {
                                                plugin_name: 'magento-2',
                                                transaction_reference: response.reference,
                                                public_key: paystackConfiguration.public_key
                                            }
                                        });

                                        redirectOnSuccessAction.execute();
                                        return;
                                    }

                                    fullScreenLoader.stopLoader();

                                    if (data.final === false) {
                                        // Only an explicit "not terminal" from the server re-enables
                                        // payment. The server owns that classification (see
                                        // TransactionValidator::isTerminalForCustomer) so a reason
                                        // added there later cannot silently become retryable here.
                                        // The comparison is against false, not a truthy check on
                                        // `final`: a missing or mangled field must fail closed.
                                        _this.isPlaceOrderActionAllowed(true);
                                        _this.messageContainer.addErrorMessage({
                                            message: data.message || "Payment verification failed. Please try again."
                                        });
                                        return;
                                    }

                                    // Terminal: money moved, or its fate is unknown. Leave the
                                    // button disabled — re-enabling it invites a double charge.
                                    // The server always sends a tailored `message` for the reason
                                    // it classified, so show that, falling back to a generic
                                    // literal only if the server ever omits it.
                                    _this.messageContainer.addErrorMessage({
                                        message: data.message || PAYMENT_UNCONFIRMED_MESSAGE
                                    });
                                }).fail(function () {
                                    fullScreenLoader.stopLoader();
                                    // A 5xx/timeout on verify proves nothing about the charge —
                                    // terminal by default.
                                    _this.messageContainer.addErrorMessage({
                                        message: PAYMENT_UNCONFIRMED_MESSAGE
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
