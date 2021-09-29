/*browser:true*/
/*global define*/
define(
    [
        'jquery',
         'ko',
         'Magento_Checkout/js/view/payment/default',
        'PaymentExpress_PxPay2/js/action/redirect-to-pxpay2',
    ],
    function ($, ko, Component, redirectToPxPay2Action) {
        var pxpayConfig = window.checkoutConfig.payment.paymentexpress;
        var paymentOption = ko.observable("withoutRebillToken");

        var addBillCardEnabled = ko.observable(true);
        var rebillingTokenEnabled = ko.observable(false); // process with billing token

        // http://stackoverflow.com/questions/19590607/set-checkbox-when-radio-button-changes-with-knockout
        function paymentOptionChanged() {
            var paymentOptionValue = paymentOption();
            addBillCardEnabled(paymentOptionValue == "withoutRebillToken");
            rebillingTokenEnabled(paymentOptionValue == "withRebillToken");
        }
        paymentOption.subscribe(paymentOptionChanged);
        
        var merchantLogos = pxpayConfig.merchantUICustomOptions.logos;
        var index = 0, logoItem;
        for (index = 0; index < merchantLogos.length; ++index) {
            logoItem = merchantLogos[index];
            if (!logoItem.Width) {
                logoItem.Width = 80;
            }
            if (!logoItem.Height) {
                logoItem.Height = 45;
            }
        }
        
        return Component.extend({
            defaults: {
                template: 'PaymentExpress_PxPay2/payment/dps-payment'
            },

            redirectAfterPlaceOrder: false,

            showCardOptions: pxpayConfig.showCardOptions,
            isRebillEnabled: pxpayConfig.isRebillEnabled,
            contiansSavedCards: pxpayConfig.savedCards.length > 0,
            savedCards: pxpayConfig.savedCards,

            paymentOption: paymentOption,

            addBillCardEnabled: addBillCardEnabled,
            enableAddBillCard: ko.observable(),

            rebillingTokenEnabled: rebillingTokenEnabled,
            billingId: ko.observable(),
        
            merchantLinkData: pxpayConfig.merchantUICustomOptions.linkData,
            merchantLogos: merchantLogos,
            merchantText : pxpayConfig.merchantUICustomOptions.text,

            getData: function () {
                var parent = this._super();

                var additionalData = {
                    paymentexpress_enableAddBillCard: this.enableAddBillCard(),
                    paymentexpress_useSavedCard: this.rebillingTokenEnabled(),
                    paymentexpress_billingId: this.billingId(),
                };

                var result = $.extend(true, parent, {
                    'additional_data': additionalData
                });
                

                return result;
            },
            
            afterPlaceOrder: function() {
                redirectToPxPay2Action(this.messageContainer, "pxpay2");
            }
        });
    }
);
