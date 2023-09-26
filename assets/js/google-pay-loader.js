(function ($) {
    var defaultConfiguration = {
        googlePayButtonContainerId: 'googlepay-button',
        buttonType: 'buy',
        buttonColor: 'default',

        /**
         * Card networks supported by your site and your gateway
         *
         * @see {@link https://developers.google.com/pay/api/web/reference/request-objects#CardParameters|CardParameters}
         */
        allowedCardNetworks: null,

        /**
         * Card authentication methods supported by your site and your gateway
         *
         * @see {@link https://developers.google.com/pay/api/web/reference/request-objects#CardParameters|CardParameters}
         */
        allowedCardAuthMethods: null,

        /**
         * Identify your gateway and your site's gateway merchant identifier
         *
         * The Google Pay API response will return an encrypted payment method capable
         * of being charged by a supported gateway after payer authorization
         *
         * @see {@link https://developers.google.com/pay/api/web/reference/request-objects#gateway|PaymentMethodTokenizationSpecification}
         */
        tokenizationSpecification: {
            type: 'PAYMENT_GATEWAY',
            parameters: {
                'gateway': '',
                'gatewayMerchantId': ''
            }
        },

        /** @var {?string} */
        merchantId: null,

        /** @var {?string} */
        merchantName: null,

        cartInfo: {
            /** @var {?string} */
            currency: null,

            /** @var {?string} */
            country: null,

            /** @var {?string} */
            grandTotal: null,
        },

        /**
         * @callback processPaymentCallback
         * @param {object} paymentData response from Google Pay API after user approves payment
         * @param {string} paymentToken pass payment token to your gateway to process payment
         * @see {@link https://developers.google.com/pay/api/web/reference/response-objects#PaymentData|PaymentData object reference}
         */

        /**
         * @callback errorCallback
         * @param {object} error Error details
         */

        /** @var {processPaymentCallback} */
        processPaymentCallback: null,

        /** @var {errorCallback} */
        errorCallback: null
    };

    var configuration = {};
    var buttonIsLoading = false;

    var baseRequest = {
        apiVersion: 2,
        apiVersionMinor: 0
    };

    /**
     * An initialized google.payments.api.PaymentsClient object or null if not yet set
     *
     * @see {@link getGooglePaymentsClient}
     */
    var paymentsClient = null;

    /**
     * Describe your site's support for the CARD payment method and its required
     * fields
     *
     * @see {@link https://developers.google.com/pay/api/web/reference/request-objects#CardParameters|CardParameters}
     */
    function getBaseCardPaymentMethod() {
        return {
            type: 'CARD',
            parameters: {
                allowedAuthMethods: configuration.allowedCardAuthMethods,
                allowedCardNetworks: configuration.allowedCardNetworks
            }
        };
    }

    /**
     * Describe your site's support for the CARD payment method including optional
     * fields
     *
     * @see {@link https://developers.google.com/pay/api/web/reference/request-objects#CardParameters|CardParameters}
     */
    function getCardPaymentMethod() {
        return Object.assign(
            {},
            getBaseCardPaymentMethod(),
            {
                tokenizationSpecification: configuration.tokenizationSpecification
            }
        );
    }

    /**
     * Configure your site's support for payment methods supported by the Google Pay
     * API.
     *
     * Each member of allowedPaymentMethods should contain only the required fields,
     * allowing reuse of this base request when determining a viewer's ability
     * to pay and later requesting a supported payment method
     *
     * @returns {object} Google Pay API version, payment methods supported by the site
     */
    function getGoogleIsReadyToPayRequest() {
        return Object.assign(
            {},
            baseRequest,
            {
                allowedPaymentMethods: [getBaseCardPaymentMethod()]
            }
        );
    }

    /**
     * Configure support for the Google Pay API
     *
     * @see {@link https://developers.google.com/pay/api/web/reference/request-objects#PaymentDataRequest|PaymentDataRequest}
     * @returns {object} PaymentDataRequest fields
     */
    function getGooglePaymentDataRequest() {
        var paymentDataRequest = Object.assign({}, baseRequest);
        paymentDataRequest.allowedPaymentMethods = [getCardPaymentMethod()];
        paymentDataRequest.transactionInfo = getGoogleTransactionInfo();
        paymentDataRequest.merchantInfo = {
            merchantId: configuration.merchantId,
            merchantName: configuration.merchantName
        };
        return paymentDataRequest;
    }

    /**
     * Return an active PaymentsClient or initialize
     *
     * @see {@link https://developers.google.com/pay/api/web/reference/client#PaymentsClient|PaymentsClient constructor}
     * @returns {google.payments.api.PaymentsClient} Google Pay API client
     */
    function getGooglePaymentsClient() {
        if ( paymentsClient === null ) {
            /* jshint -W117 */
            // noinspection JSUnresolvedVariable, JSUnresolvedFunction
            paymentsClient = new google.payments.api.PaymentsClient({environment: configuration.environment});
            /* jshint +W117 */
        }
        return paymentsClient;
    }

    /**
     * Initialize Google PaymentsClient after Google-hosted JavaScript has loaded
     *
     * Display a Google Pay payment button after confirmation of the viewer's
     * ability to pay.
     */
    function onGooglePayLoaded() {
        return new Promise(function (resolve, reject) {
            if (buttonIsLoading) {
                return;
            }

            buttonIsLoading = true;

            var paymentsClient = getGooglePaymentsClient();
            paymentsClient.isReadyToPay(getGoogleIsReadyToPayRequest())
                .then(function(response) {
                    if (response.result) {
                        addGooglePayButton();
                        prefetchGooglePaymentData();
                        resolve();
                    }
                })
                .catch(function(err) {
                    // show error in developer console for debugging
                    console.error(err);

                    if (configuration.errorCallback) {
                        configuration.errorCallback(err);
                    }

                    reject();
                })
                .finally(function() {
                    buttonIsLoading = false;
                });
        });
    }

    /**
     * Add a Google Pay purchase button alongside an existing checkout button
     *
     * @see {@link https://developers.google.com/pay/api/web/reference/request-objects#ButtonOptions|Button options}
     * @see {@link https://developers.google.com/pay/api/web/guides/brand-guidelines|Google Pay brand guidelines}
     */
    function addGooglePayButton()
    {
        if (document.querySelector('#'+configuration.googlePayButtonContainerId+' button')) {
            return;
        }

        if (getGoogleTransactionInfo() === false) {
            return;
        }

        var paymentsClient = getGooglePaymentsClient();
        var button =
            paymentsClient.createButton({
                onClick: onGooglePaymentButtonClicked,
                allowedPaymentMethods: [getBaseCardPaymentMethod()],
                buttonType: configuration.buttonType,
                buttonColor: configuration.buttonColor,
                buttonSizeMode: 'fill'
            });
        document.getElementById(configuration.googlePayButtonContainerId).appendChild(button);
    }

    /**
     * Provide Google Pay API with a payment amount, currency, and amount status
     *
     * @see {@link https://developers.google.com/pay/api/web/reference/request-objects#TransactionInfo|TransactionInfo}
     * @returns {object} transaction info, suitable for use as transactionInfo property of PaymentDataRequest
     */
    function getGoogleTransactionInfo()
    {
        if (configuration.cartInfo.country === null || configuration.cartInfo.currency === null || configuration.cartInfo.grandTotal === null) {
            return false;
        }

        return {
            countryCode: configuration.cartInfo.country,
            currencyCode: configuration.cartInfo.currency,
            totalPriceStatus: 'FINAL',
            totalPrice: configuration.cartInfo.grandTotal
        };
    }

    /**
     * Prefetch payment data to improve performance
     *
     * @see {@link https://developers.google.com/pay/api/web/reference/client#prefetchPaymentData|prefetchPaymentData()}
     */
    function prefetchGooglePaymentData()
    {
        var paymentDataRequest = getGooglePaymentDataRequest();
        // transactionInfo must be set but does not affect cache
        paymentDataRequest.transactionInfo = {
            totalPriceStatus: 'NOT_CURRENTLY_KNOWN',
            currencyCode: configuration.cartInfo.currency
        };
        var paymentsClient = getGooglePaymentsClient();
        paymentsClient.prefetchPaymentData(paymentDataRequest);
    }

    /**
     * Show Google Pay payment sheet when Google Pay payment button is clicked
     */
    function onGooglePaymentButtonClicked()
    {
        //$('body').trigger('update_checkout');
        //$('.validate-required input, .validate-required select').trigger('validate');

        /*var validationResult = additionalValidators.validate(false);

        if (!validationResult) {
            if (configuration.errorCallback) {
                configuration.errorCallback(validationResult);
            }

            return;
        }*/

        var paymentDataRequest = getGooglePaymentDataRequest();
        paymentDataRequest.transactionInfo = getGoogleTransactionInfo();

        var paymentsClient = getGooglePaymentsClient();
        paymentsClient.loadPaymentData(paymentDataRequest)
            .then(function (paymentData) {
                // handle the response
                processPayment(paymentData);
            })
            .catch(function (err) {
                // show error in developer console for debugging
                console.error(err);

                if (configuration.errorCallback) {
                    configuration.errorCallback(err);
                }
            });
    }

    /**
     * Process payment data returned by the Google Pay API
     *
     * @param {object} paymentData response from Google Pay API after user approves payment
     * @see {@link https://developers.google.com/pay/api/web/reference/response-objects#PaymentData|PaymentData object reference}
     */
    function processPayment(paymentData)
    {
        if (configuration.processPaymentCallback) {
            var paymentToken = paymentData.paymentMethodData.tokenizationData.token;
            configuration.processPaymentCallback(paymentData, paymentToken);
        }
    }

    window.tillGooglePayLoader = {
        /**
         * Set configuration data for Google Pay
         *
         * @param {Object} config
         */
        setConfiguration: function (config) {
            $.extend(/* true, */ configuration, defaultConfiguration, config);
        },
        onGooglePayLoaded: onGooglePayLoaded,
    };
})(jQuery);
