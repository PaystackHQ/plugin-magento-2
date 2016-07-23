/*browser:true*/
/*global define*/
define(
	[
		'Magento_Checkout/js/view/payment/default',
		'jquery',
		'underscore'
	],
	function (Component, $, _) {
		'use strict';

		return Component.extend({
			defaults: {
				template: 'Profibro_Paystack/payment/paystack-link',
				paymentReady: false,
				trxref: ''
			},
			redirectAfterPlaceOrder: false,
			
			initObservable: function () {

				this._super()
					.observe([
						'trxref'
					]);
				return this;
			},
			/**
			 * Init component
			 */
			initialize: function () {
				var self = this;

				this._super();
			},

			getCode: function() {
				return 'profibro_paystack';
			},

			isActive: function() {
				return true;
			},
			
			getData: function() {
				return {
					'method': this.item.method,
					'additional_data': {
						'trxref': this.trxref()
					}
				};
			},

			afterPlaceOrder: function () {
				window.location = window.checkoutConfig.payment.paystack.redirectUrl;
			},

			validate: function() {
				return true;
			}
		});
	}
);