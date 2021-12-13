<?php

class WC_TillPayments_CreditCard extends WC_Payment_Gateway
{
    public $id = 'creditcard';

    public $method_title = 'Credit Card';

    /**
     * @var false|WP_User
     */
    protected $user;

    /**
     * @var WC_Order
     */
    protected $order;

    /**
     * @var string
     */
    protected $callbackUrl;

    public function __construct()
    {
        $this->id = TILL_PAYMENTS_EXTENSION_UID_PREFIX . $this->id;
        $this->method_description = TILL_PAYMENTS_EXTENSION_NAME . ' ' . $this->method_title . ' payments.';
		$this->icon = 'https://s3.ap-southeast-2.amazonaws.com/images.simplepays.io/visa_mastercard+(2).png';
        $this->has_fields = isset($_GET['pay_for_order']);

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->callbackUrl = add_query_arg('wc-api', 'wc_' . $this->id, home_url('/'));

        

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('wp_enqueue_scripts', function () {
            wp_register_script('payment_js', $this->get_option('apiHost') . 'js/integrated/payment.min.js', [], TILL_PAYMENTS_EXTENSION_VERSION, false);
            wp_register_script('till_payments_js_' . $this->id, plugins_url('/tillpayments/assets/js/till-payments.js'), [], TILL_PAYMENTS_EXTENSION_VERSION, false);
        }, 999);
        add_action('woocommerce_api_wc_' . $this->id, [$this, 'process_callback']);
        add_filter('script_loader_tag', function ($tag, $handle) {
            if ($handle !== 'payment_js') {
                return $tag;
            }
            return str_replace(' src', ' data-main="payment-js" src', $tag);
        }, 10, 2);
        add_filter('woocommerce_available_payment_gateways', [$this, 'hide_payment_gateways_on_pay_for_order_page'], 100, 1);
        add_filter('woocommerce_gateway_description', [$this, 'updateDescription'], 5, 1);     
    }

    public function hide_payment_gateways_on_pay_for_order_page($available_gateways)
    {
        if (is_checkout_pay_page()) {
            global $wp;
            $this->order = new WC_Order($wp->query_vars['order-pay']);
            foreach ($available_gateways as $gateways_id => $gateways) {
                if ($gateways_id !== $this->order->get_payment_method()) {
                    unset($available_gateways[$gateways_id]);
                }
            }
        }

        return $available_gateways;
    }

    private function encodeOrderId($orderId)
    {
        return $orderId . '-' . date('YmdHis') . substr(sha1(uniqid()), 0, 10);
    }

    private function decodeOrderId($orderId)
    {
        if (strpos($orderId, '-') === false) {
            return $orderId;
        }

        $orderIdParts = explode('-', $orderId);

        if(count($orderIdParts) === 2) {
            $orderId = $orderIdParts[0];
        }

        /**
         * void/capture will prefix the transaction id
         */
        if(count($orderIdParts) === 3) {
            $orderId = $orderIdParts[1];
        }

        return $orderId;
    }

    public function process_payment($orderId)
    {
        global $woocommerce;

        /**
         * order & user
         */
        $this->order = new WC_Order($orderId);
        $this->order->update_status('pending', __('Awaiting payment', 'woocommerce'));
        $this->user = $this->order->get_user();

        /**
         * gateway client
         */
        WC_TillPayments_Provider::autoloadClient();
        TillPayments\Client\Client::setApiUrl($this->get_option('apiHost'));
        $client = new TillPayments\Client\Client(
            $this->get_option('apiUser'),
            htmlspecialchars_decode($this->get_option('apiPassword')),
            $this->get_option('apiKey'),
            $this->get_option('sharedSecret')
        );

        /**
         * gateway customer
         */
        $customer = new TillPayments\Client\Data\Customer();
        $customer
            ->setBillingAddress1($this->order->get_billing_address_1())
            ->setBillingAddress2($this->order->get_billing_address_2())
            ->setBillingCity($this->order->get_billing_city())
            ->setBillingCountry($this->order->get_billing_country())
            ->setBillingPhone($this->order->get_billing_phone())
            ->setBillingPostcode($this->order->get_billing_postcode())
            ->setBillingState($this->order->get_billing_state())
            ->setCompany($this->order->get_billing_company())
            ->setEmail($this->order->get_billing_email())
            ->setFirstName($this->order->get_billing_first_name())
            ->setIpAddress(WC_Geolocation::get_ip_address()) // $this->order->get_customer_ip_address()
            ->setLastName($this->order->get_billing_last_name());

        /**
         * add shipping data for non-digital goods
         */
        if ($this->order->get_shipping_country()) {
            $customer
                ->setShippingAddress1($this->order->get_shipping_address_1())
                ->setShippingAddress2($this->order->get_shipping_address_2())
                ->setShippingCity($this->order->get_shipping_city())
                ->setShippingCompany($this->order->get_shipping_company())
                ->setShippingCountry($this->order->get_shipping_country())
                ->setShippingFirstName($this->order->get_shipping_first_name())
                ->setShippingLastName($this->order->get_shipping_last_name())
                ->setShippingPostcode($this->order->get_shipping_postcode())
                ->setShippingState($this->order->get_shipping_state());
        }

        /**
         * transaction
         */
        $transactionRequest = $this->get_option('transactionRequest');
        $transaction = null;
        switch ($transactionRequest) {
            case 'preauthorize':
                $transaction = new \TillPayments\Client\Transaction\Preauthorize();
                break;
            case 'debit':
            default:
                $transaction = new \TillPayments\Client\Transaction\Debit();
                break;
        }

        $orderTxId = $this->encodeOrderId($orderId);
        // keep track of last tx id 
        $this->order->add_meta_data('orderTxId', $orderTxId, true); 
        $this->order->save_meta_data();
        $transaction->setTransactionId($orderTxId)
            ->setAmount(floatval($this->order->get_total()))
            ->setCurrency($this->order->get_currency())
            ->setCustomer($customer)
            ->setExtraData($this->extraData3DS())
            ->setCallbackUrl($this->callbackUrl)
            ->setCancelUrl(wc_get_checkout_url())
            ->setSuccessUrl($this->paymentSuccessUrl($this->order))
            ->setErrorUrl(add_query_arg(['gateway_return_result' => 'error'], $this->get_option('integrationKey') ? $this->order->get_checkout_payment_url(false) : wc_get_checkout_url()));
        
        /**
         * integration key is set -> seamless
         * proceed to pay now page or apply submitted transaction token
         */
        if ($this->get_option('integrationKey')) {
            $token = !empty($this->get_post_data()['token']) ? $this->get_post_data()['token'] : null;
            if (!$token) {
                return [
                    'result' => 'success',
                    'redirect' => $this->order->get_checkout_payment_url(false),
                ];
            }
            $transaction->setTransactionToken($token);
        }

        /**
         * transaction
         */
        switch ($transactionRequest) {
            case 'preauthorize':
                $result = $client->preauthorize($transaction);
                break;
            case 'debit':
            default:
                $result = $client->debit($transaction);
                break;
        }

        if ($result->isSuccess()) {
            // $gatewayReferenceId = $result->getReferenceId();
            if ($result->getReturnType() == TillPayments\Client\Transaction\Result::RETURN_TYPE_ERROR) {
                // $errors = $result->getErrors();
                return $this->paymentFailedResponse();
            } elseif ($result->getReturnType() == TillPayments\Client\Transaction\Result::RETURN_TYPE_REDIRECT) {
                /**
                 * hosted payment page or seamless+3DS
                 */
                return [
                    'result' => 'success',
                    'redirect' => $result->getRedirectUrl(),
                ];
            } elseif ($result->getReturnType() == TillPayments\Client\Transaction\Result::RETURN_TYPE_PENDING) {
                /**
                 * payment is pending, wait for callback to complete
                 */
            } elseif ($result->getReturnType() == TillPayments\Client\Transaction\Result::RETURN_TYPE_FINISHED) {
                /**
                 * seamless will finish here ONLY FOR NON-3DS SEAMLESS
                 */
            }
            $woocommerce->cart->empty_cart();

            return [
                'result' => 'success',
                'redirect' => $this->paymentSuccessUrl($this->order),
            ];
        }

        /**
         * something went wrong
         */
        return $this->paymentFailedResponse();
    }

    private function paymentSuccessUrl($order)
    {
        $url = $this->get_return_url($order);

        return $url . '&empty-cart';
    }

    private function paymentFailedResponse()
    {
        $this->order->update_status('failed', __('Payment failed or was declined', 'woocommerce'));
        wc_add_notice(__('Payment failed or was declined', 'woocommerce'), 'error');
        return [
            'result' => 'error',
            'redirect' => $this->get_return_url($this->order),
        ];
    }

    public function process_callback()
    {
        WC_TillPayments_Provider::autoloadClient();

        TillPayments\Client\Client::setApiUrl($this->get_option('apiHost'));
        $client = new TillPayments\Client\Client(
            $this->get_option('apiUser'),
            htmlspecialchars_decode($this->get_option('apiPassword')),
            $this->get_option('apiKey'),
            $this->get_option('sharedSecret')
        );

        if (!$client->validateCallbackWithGlobals()) {
            if (!headers_sent()) {
                http_response_code(400);
            }
            die("OK");
        }

        $callbackResult = $client->readCallback(file_get_contents('php://input'));
        $this->order = new WC_Order($this->decodeOrderId($callbackResult->getTransactionId()));

        // check if callback data is coming from the last (=newest+relevant) tx attempt, otherwise ignore it
        if ($this->order->get_meta('orderTxId') !== $callbackResult->getTransactionId()) {
            die("OK");
        }
        
        if ($callbackResult->getResult() == \TillPayments\Client\Callback\Result::RESULT_OK) {
            switch ($callbackResult->getTransactionType()) {
                case \TillPayments\Client\Callback\Result::TYPE_DEBIT:
                case \TillPayments\Client\Callback\Result::TYPE_CAPTURE:
                    $this->order->payment_complete();
                    break;
                case \TillPayments\Client\Callback\Result::TYPE_VOID:
                    $this->order->update_status('cancelled', __('Void', 'woocommerce'));
                    break;
                case \TillPayments\Client\Callback\Result::TYPE_PREAUTHORIZE:
                    $this->order->update_status('on-hold', __('Awaiting capture/void', 'woocommerce'));
                    break;
            }
        } elseif ($callbackResult->getResult() == \TillPayments\Client\Callback\Result::RESULT_ERROR) {
            switch ($callbackResult->getTransactionType()) {
                case \TillPayments\Client\Callback\Result::TYPE_DEBIT:
                case \TillPayments\Client\Callback\Result::TYPE_CAPTURE:
                case \TillPayments\Client\Callback\Result::TYPE_VOID:
                    $this->order->update_status('failed', __('Error', 'woocommerce'));
                    break;
            }
        }

        die("OK");
    }

    public function init_form_fields()
    {
        $this->form_fields = [
            'title' => [
                'title' => 'Title',
                'type' => 'text',
                'label' => 'Title',
                'description' => 'Title',
                'default' => $this->method_title,
            ],
            'apiHost' => [
                'title' => 'API Host',
                'type' => 'text',
                'label' => 'API Host',
                'description' => 'API Host',
                'default' => TILL_PAYMENTS_EXTENSION_URL,
            ],
            'apiUser' => [
                'title' => 'API User',
                'type' => 'text',
                'label' => 'API User',
                'description' => 'API User',
                'default' => '',
            ],
            'apiPassword' => [
                'title' => 'API Password',
                'type' => 'text',
                'label' => 'API Password',
                'description' => 'API Password',
                'default' => '',
            ],
            'apiKey' => [
                'title' => 'API Key',
                'type' => 'text',
                'label' => 'API Key',
                'description' => 'API Key',
                'default' => '',
            ],
            'sharedSecret' => [
                'title' => 'Shared Secret',
                'type' => 'text',
                'label' => 'Shared Secret',
                'description' => 'Shared Secret',
                'default' => '',
            ],
            'integrationKey' => [
                'title' => 'Integration Key',
                'type' => 'text',
                'label' => 'Integration Key',
                'description' => 'Integration Key',
                'default' => '',
            ],
            'transactionRequest' => [
                'title' => 'Transaction Request',
                'type' => 'select',
                'label' => 'Transaction Request',
                'description' => 'Transaction Request',
                'default' => 'debit',
                'options' => [
                    'debit' => 'Debit',
                    'preauthorize' => 'Preauthorize/Capture/Void',
                ],
            ],
        ];
    }

    public function payment_fields()
    {
        wp_enqueue_script('payment_js');
        wp_enqueue_script('till_payments_js_' . $this->id);

        echo '<script>window.integrationKey="' . $this->get_option('integrationKey') . '";</script>
        <style>.payment_box iframe { width: 100%!important } #till_payments_errors{color: red; } 
        #loader {
          position: absolute;  
          left: 50%;
          top: 50%;
          border: 5px dotted #808080;
          border-radius: 50%;
          border-top: 5px dotted #FFFFFF;
          width: 40px;
          height: 40px;
          -webkit-animation: spin 2s linear infinite; /* Safari */
          animation: spin 1s linear infinite;
        }
        
        /* Safari */
        @-webkit-keyframes spin {
          0% { -webkit-transform: rotate(0deg); }
          100% { -webkit-transform: rotate(360deg); }
        }
        
        @keyframes spin {
          0% { transform: rotate(0deg); }
          100% { transform: rotate(360deg); }
        }
        </style>
        <div id="till_payments_errors"></div>
        <div class="payment_box" style="padding: 25px; background-color: #fff; border-radius: 3px; max-width: 450px; min-height: 385px">
            <div id = "loader"></div>
            <div id="till_payments_seamless">
                <input type="hidden" id="till_payments_token" name="token">
                <p class="form-row form-row-wide" style="height: 80px;">
                    <label for="till_payments_seamless_card_holder">Cardholder Name&nbsp;<abbr class="required" title="required">*</abbr></label>
                    <span class="woocommerce-input-wrapper">
                        <input type="text" class="input-text" id="till_payments_seamless_card_holder" style="border-radius: 3px">
                    </span>
                </p>
                <p class="form-row form-row-wide" style="height: 80px;">
                    <label for="till_payments_seamless_card_number">Card Number&nbsp;<abbr class="required" title="required">*</abbr></label>
                    <span class="woocommerce-input-wrapper">
                        <span id="till_payments_seamless_card_number" class="input-text" style="padding: 0; width: 100%; border-radius: 3px"></span>
                    </span>
                </p>
                <p class="form-row form-row-first" style="height: 80px;">
                    <label for="till_payments_seamless_expiry">Expiration Date&nbsp;<abbr class="required" title="required">*</abbr></label>
                    <span class="woocommerce-input-wrapper">
                        <input type="text" class="input-text" id="till_payments_seamless_expiry" maxlength="5" placeholder="MM/YY" style="border-radius: 3px">
                    </span>
                </p>
                <p class="form-row form-row-last" style="height: 80px;">
                    <label for="till_payments_seamless_cvv">CVC/CVV Code&nbsp;<abbr class="required" title="required" style="color: #b22222;
                    text-decoration: none;">*</abbr></label>
                    <span class="woocommerce-input-wrapper">
                        <span id="till_payments_seamless_cvv" style="padding: 0; height: 52px; width: 187px; border-radius: 3px"></span>
                    </span>
                </p>   
            </div>
        </div>';
    }

    /**
     * @throws Exception
     * @return array
     */
    private function extraData3DS()
    {
        $extraData = [
            /**
             * Browser 3ds data injected by payment.js
             */
            // 3ds:browserAcceptHeader
            // 3ds:browserIpAddress
            // 3ds:browserJavaEnabled
            // 3ds:browserLanguage
            // 3ds:browserColorDepth
            // 3ds:browserScreenHeight
            // 3ds:browserScreenWidth
            // 3ds:browserTimezone
            // 3ds:browserUserAgent

            /**
             * force 3ds flow
             */
            // '3dsecure' => 'mandatory',

            /**
             * Additional 3ds 2.0 data
             */
            '3ds:addCardAttemptsDay' => $this->addCardAttemptsDay(),
            '3ds:authenticationIndicator' => $this->authenticationIndicator(),
            '3ds:billingAddressLine3' => $this->billingAddressLine3(),
            '3ds:billingShippingAddressMatch' => $this->billingShippingAddressMatch(),
            '3ds:browserChallengeWindowSize' => $this->browserChallengeWindowSize(),
            '3ds:cardholderAccountAgeIndicator' => $this->cardholderAccountAgeIndicator(),
            '3ds:cardHolderAccountChangeIndicator' => $this->cardHolderAccountChangeIndicator(),
            '3ds:cardholderAccountDate' => $this->cardholderAccountDate(),
            '3ds:cardholderAccountLastChange' => $this->cardholderAccountLastChange(),
            '3ds:cardholderAccountLastPasswordChange' => $this->cardholderAccountLastPasswordChange(),
            '3ds:cardholderAccountPasswordChangeIndicator' => $this->cardholderAccountPasswordChangeIndicator(),
            '3ds:cardholderAccountType' => $this->cardholderAccountType(),
            '3ds:cardHolderAuthenticationData' => $this->cardHolderAuthenticationData(),
            '3ds:cardholderAuthenticationDateTime' => $this->cardholderAuthenticationDateTime(),
            '3ds:cardholderAuthenticationMethod' => $this->cardholderAuthenticationMethod(),
            '3ds:challengeIndicator' => $this->challengeIndicator(),
            '3ds:channel' => $this->channel(),
            '3ds:deliveryEmailAddress' => $this->deliveryEmailAddress(),
            '3ds:deliveryTimeframe' => $this->deliveryTimeframe(),
            '3ds:giftCardAmount' => $this->giftCardAmount(),
            '3ds:giftCardCount' => $this->giftCardCount(),
            '3ds:giftCardCurrency' => $this->giftCardCurrency(),
            '3ds:homePhoneCountryPrefix' => $this->homePhoneCountryPrefix(),
            '3ds:homePhoneNumber' => $this->homePhoneNumber(),
            '3ds:mobilePhoneCountryPrefix' => $this->mobilePhoneCountryPrefix(),
            '3ds:mobilePhoneNumber' => $this->mobilePhoneNumber(),
            '3ds:paymentAccountAgeDate' => $this->paymentAccountAgeDate(),
            '3ds:paymentAccountAgeIndicator' => $this->paymentAccountAgeIndicator(),
            '3ds:preOrderDate' => $this->preOrderDate(),
            '3ds:preOrderPurchaseIndicator' => $this->preOrderPurchaseIndicator(),
            '3ds:priorAuthenticationData' => $this->priorAuthenticationData(),
            '3ds:priorAuthenticationDateTime' => $this->priorAuthenticationDateTime(),
            '3ds:priorAuthenticationMethod' => $this->priorAuthenticationMethod(),
            '3ds:priorReference' => $this->priorReference(),
            '3ds:purchaseCountSixMonths' => $this->purchaseCountSixMonths(),
            '3ds:purchaseDate' => $this->purchaseDate(),
            '3ds:purchaseInstalData' => $this->purchaseInstalData(),
            '3ds:recurringExpiry' => $this->recurringExpiry(),
            '3ds:recurringFrequency' => $this->recurringFrequency(),
            '3ds:reorderItemsIndicator' => $this->reorderItemsIndicator(),
            '3ds:shipIndicator' => $this->shipIndicator(),
            '3ds:shippingAddressFirstUsage' => $this->shippingAddressFirstUsage(),
            '3ds:shippingAddressLine3' => $this->shippingAddressLine3(),
            '3ds:shippingAddressUsageIndicator' => $this->shippingAddressUsageIndicator(),
            '3ds:shippingNameEqualIndicator' => $this->shippingNameEqualIndicator(),
            '3ds:suspiciousAccountActivityIndicator' => $this->suspiciousAccountActivityIndicator(),
            '3ds:transactionActivityDay' => $this->transactionActivityDay(),
            '3ds:transactionActivityYear' => $this->transactionActivityYear(),
            '3ds:transType' => $this->transType(),
            '3ds:workPhoneCountryPrefix' => $this->workPhoneCountryPrefix(),
            '3ds:workPhoneNumber' => $this->workPhoneNumber(),
        ];

        return array_filter($extraData, function ($data) {
            return $data !== null;
        });
    }

    /**
     * 3ds:addCardAttemptsDay
     * Number of Add Card attempts in the last 24 hours.
     *
     * @return int|null
     */
    private function addCardAttemptsDay()
    {
        return null;
    }

    /**
     * 3ds:authenticationIndicator
     * Indicates the type of Authentication request. This data element provides additional information to the ACS to determine the best approach for handling an authentication request.
     * 01 -> Payment transaction
     * 02 -> Recurring transaction
     * 03 -> Installment transaction
     * 04 -> Add card
     * 05 -> Maintain card
     * 06 -> Cardholder verification as part of EMV token ID&V
     *
     * @return string|null
     */
    private function authenticationIndicator()
    {
        return null;
    }

    /**
     * 3ds:billingAddressLine3
     * Line 3 of customer's billing address
     *
     * @return string|null
     */
    private function billingAddressLine3()
    {
        return null;
    }

    /**
     * 3ds:billingShippingAddressMatch
     * Indicates whether the Cardholder Shipping Address and Cardholder Billing Address are the same.
     * Y -> Shipping Address matches Billing Address
     * N -> Shipping Address does not match Billing Address
     *
     * @return string|null
     */
    private function billingShippingAddressMatch()
    {
        return null;
    }

    /**
     * 3ds:browserChallengeWindowSize
     * Dimensions of the challenge window that has been displayed to the Cardholder. The ACS shall reply with content that is formatted to appropriately render in this window to provide the best possible user experience.
     * 01 -> 250 x 400
     * 02 -> 390 x 400
     * 03 -> 500 x 600
     * 04 -> 600 x 400
     * 05 -> Full screen
     *
     * @return string|null
     */
    private function browserChallengeWindowSize()
    {
        return '05';
    }

    /**
     * 3ds:cardholderAccountAgeIndicator
     * Length of time that the cardholder has had the account with the 3DS Requestor.
     * 01 -> No account (guest check-out)
     * 02 -> During this transaction
     * 03 -> Less than 30 days
     * 04 -> 30 - 60 days
     * 05 -> More than 60 days
     *
     * @return string|null
     */
    private function cardholderAccountAgeIndicator()
    {
        return null;
    }

    /**
     * 3ds:cardHolderAccountChangeIndicator
     * Length of time since the cardholder’s account information with the 3DS Requestor waslast changed. Includes Billing or Shipping address, new payment account, or new user(s) added.
     * 01 -> Changed during this transaction
     * 02 -> Less than 30 days
     * 03 -> 30 - 60 days
     * 04 -> More than 60 days
     *
     * @return string|null
     */
    private function cardHolderAccountChangeIndicator()
    {
        return null;
    }

    /**
     * Date that the cardholder opened the account with the 3DS Requestor. Format: YYYY-MM-DD
     * Example: 2019-05-12
     *
     * @throws Exception
     * @return string|null
     */
    private function cardholderAccountDate()
    {
        if (!$this->user) {
            return null;
        }

        return $this->user->user_registered ? (new DateTime($this->user->user_registered))->format('Y-m-d') : null;
    }

    /**
     * 3ds:cardholderAccountLastChange
     * Date that the cardholder’s account with the 3DS Requestor was last changed. Including Billing or Shipping address, new payment account, or new user(s) added. Format: YYYY-MM-DD
     * Example: 2019-05-12
     *
     * @throws Exception
     * @return string|null
     */
    private function cardholderAccountLastChange()
    {
        if (!$this->user) {
            return null;
        }

        $lastUpdate = get_user_meta($this->user->ID, 'last_update', true);

        return $lastUpdate ? (new DateTime('@' . $lastUpdate))->format('Y-m-d') : null;
    }

    /**
     * 3ds:cardholderAccountLastPasswordChange
     * Date that cardholder’s account with the 3DS Requestor had a password change or account reset. Format: YYYY-MM-DD
     * Example: 2019-05-12
     *
     * @return string|null
     */
    private function cardholderAccountLastPasswordChange()
    {
        return null;
    }

    /**
     * 3ds:cardholderAccountPasswordChangeIndicator
     * Length of time since the cardholder’s account with the 3DS Requestor had a password change or account reset.
     * 01 -> No change
     * 02 -> Changed during this transaction
     * 03 -> Less than 30 days
     * 04 -> 30 - 60 days
     * 05 -> More than 60 days
     *
     * @return string|null
     */
    private function cardholderAccountPasswordChangeIndicator()
    {
        return null;
    }

    /**
     * 3ds:cardholderAccountType
     * Indicates the type of account. For example, for a multi-account card product.
     * 01 -> Not applicable
     * 02 -> Credit
     * 03 -> Debit
     * 80 -> JCB specific value for Prepaid
     *
     * @return string|null
     */
    private function cardholderAccountType()
    {
        return null;
    }

    /**
     * 3ds:cardHolderAuthenticationData
     * Data that documents and supports a specific authentication process. In the current version of the specification, this data element is not defined in detail, however the intention is that for each 3DS Requestor Authentication Method, this field carry data that the ACS can use to verify the authentication process.
     *
     * @return string|null
     */
    private function cardHolderAuthenticationData()
    {
        return null;
    }

    /**
     * 3ds:cardholderAuthenticationDateTime
     * Date and time in UTC of the cardholder authentication. Format: YYYY-MM-DD HH:mm
     * Example: 2019-05-12 18:34
     *
     * @return string|null
     */
    private function cardholderAuthenticationDateTime()
    {
        return null;
    }

    /**
     * 3ds:cardholderAuthenticationMethod
     * Mechanism used by the Cardholder to authenticate to the 3DS Requestor.
     * 01 -> No 3DS Requestor authentication occurred (i.e. cardholder "logged in" as guest)
     * 02 -> Login to the cardholder account at the 3DS Requestor system using 3DS Requestor's own credentials
     * 03 -> Login to the cardholder account at the 3DS Requestor system using federated ID
     * 04 -> Login to the cardholder account at the 3DS Requestor system using issuer credentials
     * 05 -> Login to the cardholder account at the 3DS Requestor system using third-party authentication
     * 06 -> Login to the cardholder account at the 3DS Requestor system using FIDO Authenticator
     *
     * @return string|null
     */
    private function cardholderAuthenticationMethod()
    {
        return null;
    }

    /**
     * 3ds:challengeIndicator
     * Indicates whether a challenge is requested for this transaction. For example: For 01-PA, a 3DS Requestor may have concerns about the transaction, and request a challenge.
     * 01 -> No preference
     * 02 -> No challenge requested
     * 03 -> Challenge requested: 3DS Requestor Preference
     * 04 -> Challenge requested: Mandate
     *
     * @return string|null
     */
    private function challengeIndicator()
    {
        return null;
    }

    /**
     * 3ds:channel
     * Indicates the type of channel interface being used to initiate the transaction
     * 01 -> App-based
     * 02 -> Browser
     * 03 -> 3DS Requestor Initiated
     *
     * @return string|null
     */
    private function channel()
    {
        return null;
    }

    /**
     * 3ds:deliveryEmailAddress
     * For electronic delivery, the email address to which the merchandise was delivered.
     *
     * @return string|null
     */
    private function deliveryEmailAddress()
    {
        return null;
    }

    /**
     * 3ds:deliveryTimeframe
     * Indicates the merchandise delivery timeframe.
     * 01 -> Electronic Delivery
     * 02 -> Same day shipping
     * 03 -> Overnight shipping
     * 04 -> Two-day or more shipping
     *
     * @return string|null
     */
    private function deliveryTimeframe()
    {
        return null;
    }

    /**
     * 3ds:giftCardAmount
     * For prepaid or gift card purchase, the purchase amount total of prepaid or gift card(s) in major units (for example, USD 123.45 is 123).
     *
     * @return string|null
     */
    private function giftCardAmount()
    {
        return null;
    }

    /**
     * 3ds:giftCardCount
     * For prepaid or gift card purchase, total count of individual prepaid or gift cards/codes purchased. Field is limited to 2 characters.
     *
     * @return string|null
     */
    private function giftCardCount()
    {
        return null;
    }

    /**
     * 3ds:giftCardCurrency
     * For prepaid or gift card purchase, the currency code of the card
     *
     * @return string|null
     */
    private function giftCardCurrency()
    {
        return null;
    }

    /**
     * 3ds:homePhoneCountryPrefix
     * Country Code of the home phone, limited to 1-3 characters
     *
     * @return string|null
     */
    private function homePhoneCountryPrefix()
    {
        return null;
    }

    /**
     * 3ds:homePhoneNumber
     * subscriber section of the number, limited to maximum 15 characters.
     *
     * @return string|null
     */
    private function homePhoneNumber()
    {
        return null;
    }

    /**
     * 3ds:mobilePhoneCountryPrefix
     * Country Code of the mobile phone, limited to 1-3 characters
     *
     * @return string|null
     */
    private function mobilePhoneCountryPrefix()
    {
        return null;
    }

    /**
     * 3ds:mobilePhoneNumber
     * subscriber section of the number, limited to maximum 15 characters.
     *
     * @return string|null
     */
    private function mobilePhoneNumber()
    {
        return null;
    }

    /**
     * 3ds:paymentAccountAgeDate
     * Date that the payment account was enrolled in the cardholder’s account with the 3DS Requestor. Format: YYYY-MM-DD
     * Example: 2019-05-12
     *
     * @return string|null
     */
    private function paymentAccountAgeDate()
    {
        return null;
    }

    /**
     * 3ds:paymentAccountAgeIndicator
     * Indicates the length of time that the payment account was enrolled in the cardholder’s account with the 3DS Requestor.
     * 01 -> No account (guest check-out)
     * 02 -> During this transaction
     * 03 -> Less than 30 days
     * 04 -> 30 - 60 days
     * 05 -> More than 60 days
     *
     * @return string|null
     */
    private function paymentAccountAgeIndicator()
    {
        return null;
    }

    /**
     * 3ds:preOrderDate
     * For a pre-ordered purchase, the expected date that the merchandise will be available.
     * Format: YYYY-MM-DD
     *
     * @return string|null
     */
    private function preOrderDate()
    {
        return null;
    }

    /**
     * 3ds:preOrderPurchaseIndicator
     * Indicates whether Cardholder is placing an order for merchandise with a future availability or release date.
     * 01 -> Merchandise available
     * 02 -> Future availability
     *
     * @return string|null
     */
    private function preOrderPurchaseIndicator()
    {
        return null;
    }

    /**
     * 3ds:priorAuthenticationData
     * Data that documents and supports a specfic authentication porcess. In the current version of the specification this data element is not defined in detail, however the intention is that for each 3DS Requestor Authentication Method, this field carry data that the ACS can use to verify the authentication process. In future versionsof the application, these details are expected to be included. Field is limited to maximum 2048 characters.
     *
     * @return string|null
     */
    private function priorAuthenticationData()
    {
        return null;
    }

    /**
     * 3ds:priorAuthenticationDateTime
     * Date and time in UTC of the prior authentication. Format: YYYY-MM-DD HH:mm
     * Example: 2019-05-12 18:34
     *
     * @return string|null
     */
    private function priorAuthenticationDateTime()
    {
        return null;
    }

    /**
     * 3ds:priorAuthenticationMethod
     * Mechanism used by the Cardholder to previously authenticate to the 3DS Requestor.
     * 01 -> Frictionless authentication occurred by ACS
     * 02 -> Cardholder challenge occurred by ACS
     * 03 -> AVS verified
     * 04 -> Other issuer methods
     *
     * @return string|null
     */
    private function priorAuthenticationMethod()
    {
        return null;
    }

    /**
     * 3ds:priorReference
     * This data element provides additional information to the ACS to determine the best approach for handling a request. The field is limited to 36 characters containing ACS Transaction ID for a prior authenticated transaction (for example, the first recurring transaction that was authenticated with the cardholder).
     *
     * @return string|null
     */
    private function priorReference()
    {
        return null;
    }

    /**
     * 3ds:purchaseCountSixMonths
     * Number of purchases with this cardholder account during the previous six months.
     *
     * @return int
     */
    private function purchaseCountSixMonths()
    {
        if (!$this->user) {
            return null;
        }

        $count = 0;
        foreach (['processing', 'completed', 'refunded', 'cancelled', 'authorization'] as $status) {
            $orders = wc_get_orders([
                'customer' => $this->user->ID,
                'limit' => -1,
                'status' => $status,
                'date_after' => '6 months ago',
            ]);
            $count += count($orders);
        }
        return $count;
    }

    /**
     * 3ds:purchaseDate
     * Date and time of the purchase, expressed in UTC. Format: YYYY-MM-DD
     **Note: if omitted we put in today's date
     *
     * @return string|null
     */
    private function purchaseDate()
    {
        return null;
    }

    /**
     * 3ds:purchaseInstalData
     * Indicates the maximum number of authorisations permitted for instalment payments. The field is limited to maximum 3 characters and value shall be greater than 1. The fields is required if the Merchant and Cardholder have agreed to installment payments, i.e. if 3DS Requestor Authentication Indicator = 03. Omitted if not an installment payment authentication.
     *
     * @return string|null
     */
    private function purchaseInstalData()
    {
        return null;
    }

    /**
     * 3ds:recurringExpiry
     * Date after which no further authorizations shall be performed. This field is required for 01-PA and for 02-NPA, if 3DS Requestor Authentication Indicator = 02 or 03.
     * Format: YYYY-MM-DD
     *
     * @return string|null
     */
    private function recurringExpiry()
    {
        return null;
    }

    /**
     * 3ds:recurringFrequency
     * Indicates the minimum number of days between authorizations. The field is limited to maximum 4 characters. This field is required if 3DS Requestor Authentication Indicator = 02 or 03.
     *
     * @return string|null
     */
    private function recurringFrequency()
    {
        return null;
    }

    /**
     * 3ds:reorderItemsIndicator
     * Indicates whether the cardholder is reoreding previously purchased merchandise.
     * 01 -> First time ordered
     * 02 -> Reordered
     *
     * @return string|null
     */
    private function reorderItemsIndicator()
    {
        return null;
    }

    /**
     * 3ds:shipIndicator
     * Indicates shipping method chosen for the transaction. Merchants must choose the Shipping Indicator code that most accurately describes the cardholder's specific transaction. If one or more items are included in the sale, use the Shipping Indicator code for the physical goods, or if all digital goods, use the code that describes the most expensive item.
     * 01 -> Ship to cardholder's billing address
     * 02 -> Ship to another verified address on file with merchant
     * 03 -> Ship to address that is different than the cardholder's billing address
     * 04 -> "Ship to Store" / Pick-up at local store (Store address shall be populated in shipping address fields)
     * 05 -> Digital goods (includes online services, electronic gift cards and redemption codes)
     * 06 -> Travel and Event tickets, not shipped
     * 07 -> Other (for example, Gaming, digital services not shipped, emedia subscriptions, etc.)
     *
     * @return string|null
     */
    private function shipIndicator()
    {
        return null;
    }

    /**
     * 3ds:shippingAddressFirstUsage
     * Date when the shipping address used for this transaction was first used with the 3DS Requestor. Format: YYYY-MM-DD
     * Example: 2019-05-12
     *
     * @throws Exception
     * @return string|null
     */
    private function shippingAddressFirstUsage()
    {
        if (!$this->user) {
            return null;
        }

        $orders = wc_get_orders([
            'customer' => $this->user->ID,
            'shipping_address_1' => $this->order->get_shipping_address_1(),
            'orderby' => 'date',
            'order' => 'ASC',
            'limit' => 1,
            'paginate' => false,
        ]);

        /** @var WC_Order $firstOrder */
        $firstOrder = reset($orders);
        $firstOrderDate = $firstOrder && $firstOrder->get_date_created() ? $firstOrder->get_date_created() : new WC_DateTime();
        return $firstOrderDate->format('Y-m-d');
    }

    /**
     * 3ds:shippingAddressLine3
     * Line 3 of customer's shipping address
     *
     * @return string|null
     */
    private function shippingAddressLine3()
    {
        return null;
    }

    /**
     * 3ds:shippingAddressUsageIndicator
     * Indicates when the shipping address used for this transaction was first used with the 3DS Requestor.
     * 01 -> This transaction
     * 02 -> Less than 30 days
     * 03 -> 30 - 60 days
     * 04 -> More than 60 days.
     *
     * @return string|null
     */
    private function shippingAddressUsageIndicator()
    {
        return null;
    }

    /**
     * 3ds:shippingNameEqualIndicator
     * Indicates if the Cardholder Name on the account is identical to the shipping Name used for this transaction.
     * 01 -> Account Name identical to shipping Name
     * 02 -> Account Name different than shipping Name
     *
     * @return string|null
     */
    private function shippingNameEqualIndicator()
    {
        return null;
    }

    /**
     * 3ds:suspiciousAccountActivityIndicator
     * Indicates whether the 3DS Requestor has experienced suspicious activity (including previous fraud) on the cardholder account.
     * 01 -> No suspicious activity has been observed
     * 02 -> Suspicious activity has been observed
     *
     * @return string|null
     */
    private function suspiciousAccountActivityIndicator()
    {
        return null;
    }

    /**
     * 3ds:transactionActivityDay
     * Number of transactions (successful and abandoned) for this cardholder account with the 3DS Requestor across all payment accounts in the previous 24 hours.
     *
     * @return string|null
     */
    private function transactionActivityDay()
    {
        return null;
    }

    /**
     * 3ds:transactionActivityYear
     * Number of transactions (successful and abandoned) for this cardholder account with the 3DS Requestor across all payment accounts in the previous year.
     *
     * @return string|null
     */
    private function transactionActivityYear()
    {
        return null;
    }

    /**
     * 3ds:transType
     * Identifies the type of transaction being authenticated. The values are derived from ISO 8583.
     * 01 -> Goods / Service purchase
     * 03 -> Check Acceptance
     * 10 -> Account Funding
     * 11 -> Quasi-Cash Transaction
     * 28 -> Prepaid activation and Loan
     *
     * @return string|null
     */
    private function transType()
    {
        return null;
    }

    /**
     * 3ds:workPhoneCountryPrefix
     * Country Code of the work phone, limited to 1-3 characters
     *
     * @return string|null
     */
    private function workPhoneCountryPrefix()
    {
        return null;
    }

    /**
     * 3ds:workPhoneNumber
     * subscriber section of the number, limited to maximum 15 characters.
     *
     * @return string|null
     */
    private function workPhoneNumber()
    {
        return null;
    }
    /** 
     * add payment description
    */
    public function updateDescription($id)
    {
        if ($id == $this->WC_TillPayments_CreditCard->id){
            echo '<div style = "margin-left: 30px; padding: 5px;">You\'ll be directed to the next page to complete the payment. Powered by <a href="https://tillpayments.com/">Till Payments</a></div>';
        }
    }
}
