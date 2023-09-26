(function ($) {
    window.tillPaymentsApplePay = {
        $tillPaymentsErrors: $('#till_payments_applepay_errors'),
        paymentFormSubmitButtonId: 'place_order',
        applePayButtonId: 'applepay-button',
        injected: false,

        injectApplePay: function() {
            if (!this.isApplePaySupported()) {
                return;
            }

            var self = this;

            if (!this.injected) {
                $.getScript('https://applepay.cdn-apple.com/jsapi/v1/apple-pay-sdk.js')
                    .done(function() {
                        self.injected = true;
                        self.toggleApplePayButton($('input[type=radio][name=payment_method]:checked').val());
                    })
                    .fail(function() {
                        console.error('APPLE PAY error loading apple-pay-sdk.js!');
                    });
            }
        },

        isApplePaySupported: function() {
            if (window.ApplePaySession && ApplePaySession.canMakePayments()) {
                return true;
            } else {
                if ($('#'+ this.applePayButtonId + '_notsupported').length === 0) {
                    var $notSupportedMsg = $(
                        '<div class="woocommerce-info" id="' + this.applePayButtonId + '_notsupported">' +
                        'Apple Pay is not supported on your device. ' +
                        '<a href="https://support.apple.com/HT208531" target="_blank" rel="noopener noreferrer">' +
                        'See this list of supported devices.' +'</a><br/><br/>' +
                        'Please choose a different payment method.' +
                        '</div>'
                    );

                    var $buttonWrapper = $('#'+this.applePayButtonId);
                    $notSupportedMsg.appendTo($buttonWrapper);
                    $buttonWrapper.css({cursor: 'auto'});
                }

                return false;
            }
        },

        toggleApplePayButton: function(currentPaymentMethodName) {
            if (currentPaymentMethodName.startsWith('till_payments_') && currentPaymentMethodName !== 'till_payments_creditcard') {
                $('#'+this.paymentFormSubmitButtonId).hide();
            } else {
                $('#'+this.paymentFormSubmitButtonId).show();
            }

            var applePayButtonContainer = $('#'+this.applePayButtonId);

            if (currentPaymentMethodName === 'till_payments_applepay') {
                applePayButtonContainer.show();
            } else {
                applePayButtonContainer.hide();
            }
        },

        onApplePayButtonClicked: function() {
            if (!ApplePaySession) {
                console.error('APPLE PAY Got no session!');
                return;
            }

            var self = this;

            const apSessionData = {
                "countryCode": window.applePay.country,
                "currencyCode": window.applePay.currency_code,
                "merchantCapabilities": [
                    "supports3DS"
                ],
                "supportedNetworks": window.applePay.allowed_card_networks,
                "total": {
                    "label": window.applePay.merchant_name,
                    "type": "final",
                    "amount": window.applePay.grand_total
                }
            };

            // Create ApplePaySession
            try {
                window.applePaySessionInstance = new ApplePaySession(3, apSessionData);
            } catch (e) {
                console.error('APPLE PAY could not create ApplePaySession', e.message);
            }

            window.applePaySessionInstance.onvalidatemerchant = async function (event) {
                // noinspection JSDeprecatedSymbols
                const validationUrl = window.btoa(unescape(encodeURIComponent(event.validationURL)));
                const fetchUrl = window.applePay.session_init_url+'&v='+validationUrl;

                fetch(
                    fetchUrl,
                    {
                        cache: 'no-cache'
                    }
                )
                    .then(res => {
                        if (!res.ok) {
                            self.$tillPaymentsErrors.html('Apple Pay Session request was not successful.');
                            window.applePaySessionInstance.abort();
                            throw new Error('Apple Pay Session request was not successful.');
                        }

                        // Parse response as JSON.
                        return res.json();
                    })
                    .then(merchantSession => {
                        window.applePaySessionInstance.completeMerchantValidation(merchantSession);
                    })
                    .catch(err => {
                        window.applePaySessionInstance.abort();
                        console.error('APPLE PAY merchant validation error', err.message);
                        self.$tillPaymentsErrors.html('An error occurred while initiating the payment.');
                    });
            };

            window.applePaySessionInstance.onpaymentmethodselected = function (event) {
                // Define ApplePayPaymentMethodUpdate based on the selected payment method.
                const update = {
                    "newTotal": {
                        "label": window.applePay.merchant_name,
                        "type": "final",
                        "amount": window.applePay.grand_total,
                    }
                };
                window.applePaySessionInstance.completePaymentMethodSelection(update);
            };

            window.applePaySessionInstance.onshippingmethodselected = function (event) {
                // Define ApplePayShippingMethodUpdate based on the selected shipping method.
                // No updates or errors are needed, pass an empty object.
                const update = {};
                window.applePaySessionInstance.completeShippingMethodSelection(update);
            };

            window.applePaySessionInstance.onshippingcontactselected = function (event) {
                // Define ApplePayShippingContactUpdate based on the selected shipping contact.
                const update = {};
                window.applePaySessionInstance.completeShippingContactSelection(update);
            };

            window.applePaySessionInstance.onpaymentauthorized = function (event) {
                console.debug('APPLE PAY onpaymentauthorized()');

                var $paymentFormTokenInput = $('#till_payments_applepay_token');
                $paymentFormTokenInput.val('applepay:' + JSON.stringify(event.payment));

                var $checkoutForm = $paymentFormTokenInput.closest('form')[0];
                var formData = new FormData($checkoutForm);

                fetch(
                    wc_checkout_params.checkout_url,
                    {
                        method: 'POST',
                        body: formData,
                        cache: 'no-cache'
                    }
                )
                    .then(res => {
                        if (!res.ok) {
                            self.$tillPaymentsErrors.html('Apple Pay order submit was not successful.');
                            window.applePaySessionInstance.completePayment({
                                "status": ApplePaySession.STATUS_FAILURE
                            });
                            throw new Error('Apple Pay Session request was not successful.');
                        }

                        // Parse response as JSON.
                        return res.json();
                    })
                    .then(orderSubmitResult => {
                        if (orderSubmitResult.hasOwnProperty('result') && orderSubmitResult.result === 'success') {
                            console.debug('APPLE PAY order successful');
                            window.applePaySessionInstance.completePayment({
                                "status": ApplePaySession.STATUS_SUCCESS
                            });
                            document.location.href = orderSubmitResult.redirect;
                        } else {
                            console.error('APPLE PAY Order submission was not successful!');
                            self.$tillPaymentsErrors.html('Apple Pay order submit was not successful.');
                            window.applePaySessionInstance.completePayment({
                                "status": ApplePaySession.STATUS_FAILURE
                            });
                            throw new Error('Apple Pay order submit was not successful.');
                        }

                    })
                    .catch(err => {
                        window.applePaySessionInstance.abort();
                        console.error('APPLE PAY Order submission was not successful!', err);
                        self.$tillPaymentsErrors.html('An error occurred while processing the payment.');
                    });
            };

            window.applePaySessionInstance.oncancel = function (event) {
                // Payment cancelled by WebKit
                self.$tillPaymentsErrors.html('Payment was cancelled by the user.');
            };

            window.applePaySessionInstance.begin();
        }
    };

    // init ApplePay on document load
    $(function() {
        window.tillPaymentsApplePay.injectApplePay();

        $(document).on('change', 'input[type=radio][name=payment_method]', function() {
            window.tillPaymentsApplePay.isApplePaySupported();
            window.tillPaymentsApplePay.toggleApplePayButton(this.value);
        });
    });
})(jQuery);
