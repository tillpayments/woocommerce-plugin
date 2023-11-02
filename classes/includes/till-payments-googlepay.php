<?php

use TillPayments\Client\Callback\Result as CallbackResult;
use TillPayments\Client\Transaction\Result as TransactionResult;
use TillPayments\Client\Transaction\Debit;
use TillPayments\Client\Transaction\Preauthorize;
use TillPayments\Client\Transaction\Refund;

class WC_TillPayments_GooglePay extends WC_Payment_Gateway
{
    public $id = 'googlepay';

    public $method_title = 'Google Pay';

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

    /**
     * @var null|WC_Logger
     */
    protected $logger;

    protected $loggerContext = ['source' => 'TillPayments_GooglePay'];

    public function __construct()
    {
        $this->logger = wc_get_logger();

        $this->id = TILL_PAYMENTS_EXTENSION_UID_PREFIX . $this->id;
        $this->method_description = TILL_PAYMENTS_EXTENSION_NAME . ' ' . $this->method_title . ' payments.';
        $this->icon = TILL_PAYMENTS_EXTENSION_ASSETS . 'img/google-pay-mark_800.svg';
        $this->has_fields = true;

        $this->supports = array(
            'products',
            'refunds'
        );

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->callbackUrl = add_query_arg('wc-api', 'wc_' . $this->id, home_url('/'));

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('wp_enqueue_scripts', function () {
            wp_register_script('till_googlepay_js_' . $this->id, TILL_PAYMENTS_EXTENSION_ASSETS . 'js/till-googlepay.js', ['jquery'], TILL_PAYMENTS_EXTENSION_VERSION, false);
            wp_register_script('till_googlepay_loader_js_' . $this->id, TILL_PAYMENTS_EXTENSION_ASSETS . 'js/google-pay-loader.js', ['jquery'], TILL_PAYMENTS_EXTENSION_VERSION, false);
        }, 999);

        add_action('woocommerce_api_wc_' . $this->id, [$this, 'process_callback']);
        add_action(
            'woocommerce_order_item_add_action_buttons',
            function(WC_Order $order) {
                if ($order->get_meta('pending_capture') === 'yes' && $order->get_payment_method() === $this->id) {
                    echo sprintf(
                        '<button 
                            id="tillpayments_capture_payment" 
                            type="button" 
                            class="button capture-payment" 
                            data-order-id="%s"
                            data-payment-method="%s">Capture Payment</button>',
                        esc_attr($order->get_id()),
                        esc_attr($this->id)
                    );
                }
            }
        );
        add_filter('woocommerce_available_payment_gateways', [$this, 'hide_payment_gateways_on_pay_for_order_page'], 100, 1);
        add_filter('woocommerce_gateway_description', [$this, 'updateDescription'], 5, 1);
    }

    public function log(string $msg, string $level = WC_Log_Levels::DEBUG, string $source_suffix = null)
    {
        $context = $this->loggerContext;

        if (is_string($source_suffix)) {
            $context['source'] .= '_'.trim($source_suffix);
        }

        $this->logger->log($level, $msg, $context);
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

    private function encodeRefundId($orderId)
    {
        return $orderId . '-refund-' . date('YmdHis') . substr(sha1(uniqid()), 0, 10);
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
        $this->log('Processing new Google Pay payment...');

        global $woocommerce;

        $token = !empty($this->get_post_data()['googlepay_token']) ? $this->get_post_data()['googlepay_token'] : null;
        if (!$token) {
            $this->log('  > invalid Google Pay token!', WC_Log_Levels::ERROR);
            return $this->paymentFailedResponse();
        }

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
            ->setIpAddress(WC_Geolocation::get_ip_address())
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
        switch ($transactionRequest) {
            case 'preauthorize':
                $transaction = new Preauthorize();
                break;
            case 'debit':
            default:
                $transaction = new Debit();
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
            ->setCallbackUrl($this->callbackUrl)
            ->setCancelUrl(wc_get_checkout_url())
            ->setSuccessUrl($this->paymentSuccessUrl($this->order))
            ->setErrorUrl(add_query_arg(['gateway_return_result' => 'error'], wc_get_checkout_url()))
            ->setTransactionToken(stripcslashes($token))
        ;

        $this->log('  > created TillPayments transaction object. orderId: '.$orderId.', orderTxId: '. $orderTxId);

        /**
         * transaction
         */
        switch ($transactionRequest) {
            case 'preauthorize':
                $this->log('  > sending preauthorize transaction request...');
                $result = $client->preauthorize($transaction);
                break;
            case 'debit':
            default:
                $this->log('  > sending debit transaction request...');
                $result = $client->debit($transaction);
                break;
        }

        if ($result->isSuccess()) {
            $this->log('  > request successful');
            if ($result->getReturnType() == TransactionResult::RETURN_TYPE_ERROR) {
                $errors = $result->getErrors();
                $this->log('  > return type: ERROR', WC_Log_Levels::ERROR);
                $this->log('  > errors: '.print_r($errors, true), WC_Log_Levels::ERROR);

                return $this->paymentFailedResponse();
            } elseif ($result->getReturnType() == TransactionResult::RETURN_TYPE_REDIRECT) {
                $this->log('  > return type: REDIRECT');
                $this->order->add_meta_data('paymentUuid', $result->getReferenceId(), true);
                $this->order->save_meta_data();

                $this->log('  > redirect URL: '.$result->getRedirectUrl());

                /**
                 * hosted payment page or seamless+3DS
                 */
                return [
                    'result' => 'success',
                    'redirect' => $result->getRedirectUrl(),
                ];
            } elseif ($result->getReturnType() == TransactionResult::RETURN_TYPE_PENDING) {
                $this->log('  > return type: PENDING');
                /**
                 * payment is pending, wait for callback to complete
                 */
            } elseif ($result->getReturnType() == TransactionResult::RETURN_TYPE_FINISHED) {
                $this->order->add_meta_data('paymentUuid', $result->getReferenceId(), true);
                $this->order->save_meta_data();

                switch ($transactionRequest) {
                    case 'preauthorize':
                        $this->order->add_order_note('TillPayments authorization ID: '.$result->getReferenceId(), false);
                        break;
                    case 'debit':
                    default:
                        $this->order->payment_complete();
                        $this->order->add_order_note('TillPayments purchase ID: '.$result->getPurchaseId(), false);
                        break;
                }

                $this->log('  > return type: FINISHED');
                $this->log('  > result data: '.print_r($result->toArray(), true));
                /**
                 * seamless will finish here ONLY FOR NON-3DS SEAMLESS
                 */
            }

            if ($transactionRequest === 'preauthorize') {
                $this->order->add_meta_data('pending_capture', 'yes', true);
                $this->order->save_meta_data();
            }

            $woocommerce->cart->empty_cart();

            return [
                'result' => 'success',
                'redirect' => $this->paymentSuccessUrl($this->order),
            ];
        } else {
            $errors = $result->getErrors();
            $this->log('  > request failed', WC_Log_Levels::ERROR);
            $this->log('  > errors: '.print_r($errors, true), WC_Log_Levels::ERROR);
        }

        /**
         * something went wrong
         */
        $this->log('  > fallback return point reached. something went wrong?', WC_Log_Levels::ERROR);
        return $this->paymentFailedResponse();
    }

    /**
     * Process a refund if supported.
     *
     * @param  int    $order_id Order ID.
     * @param  float  $amount Refund amount.
     * @param  string $reason Refund reason.
     * @return bool|WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $this->log('Processing new Google Pay refund...');

        /**
         * order & user
         */
        $this->order = new WC_Order($order_id);
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
         * transaction
         */
        $transaction = new Refund();
        $refundTxId = $this->encodeRefundId($order_id);
        $transaction->setTransactionId($refundTxId)
            ->setAmount(floatval($amount))
            ->setCurrency($this->order->get_currency())
            ->setReferenceTransactionId($this->order->get_meta('paymentUuid'))
            ->setCallbackUrl($this->callbackUrl);

        /**
         * transaction
         */
        $this->log('  > sending refund transaction request...');
        $result = $client->refund($transaction);

        if ($result->isSuccess()) {
            switch ($result->getReturnType()) {
                case TransactionResult::RETURN_TYPE_ERROR:
                    $errors = $result->getErrors();
                    $this->log('  > return type: ERROR', WC_Log_Levels::ERROR);
                    $this->log('  > errors: '.print_r($errors, true), WC_Log_Levels::ERROR);

                    if (empty($errors)) {
                        return false;
                    }

                    $errorMsg = '';
                    foreach ($errors as $error) {
                        $errorMsg .= $error->getMessage() . PHP_EOL;
                    }

                    return new WP_Error('error', $errorMsg);
                case TransactionResult::RETURN_TYPE_PENDING:
                    $this->log('  > return type: PENDING');
                    $this->log('  > result data: '.print_r($result->toArray(), true));
                    break;
                case TransactionResult::RETURN_TYPE_FINISHED:
                    $this->log('  > return type: FINISHED');
                    $this->order->add_order_note('TillPayments refund ID: ' . $result->getReferenceId(), false);

                    $this->log('  > result data: '.print_r($result->toArray(), true));

                    return true;
            }
        } else {
            $errors = $result->getErrors();

            if (empty($errors)) {
                return false;
            }

            $this->log('  > request failed', WC_Log_Levels::ERROR);
            $this->log('  > errors: '.print_r($errors, true), WC_Log_Levels::ERROR);

            $errorMsg = '';
            foreach ($errors as $error) {
                $errorMsg .= $error->getMessage().PHP_EOL;
            }

            return new WP_Error('error', $errorMsg);
        }

        /**
         * something went wrong
         */
        $this->log('  > fallback return point reached. something went wrong?', WC_Log_Levels::ERROR);
        return false;
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
        
        if ($callbackResult->getResult() == CallbackResult::RESULT_OK) {
            switch ($callbackResult->getTransactionType()) {
                case CallbackResult::TYPE_DEBIT:
                case CallbackResult::TYPE_CAPTURE:
                    $this->order->payment_complete();
                    break;
                case CallbackResult::TYPE_VOID:
                    $this->order->update_status('cancelled', __('Void', 'woocommerce'));
                    break;
                case CallbackResult::TYPE_PREAUTHORIZE:
                    $this->order->update_status('on-hold', __('Awaiting capture/void', 'woocommerce'));
                    break;
            }
        } elseif ($callbackResult->getResult() == CallbackResult::RESULT_ERROR) {
            switch ($callbackResult->getTransactionType()) {
                case CallbackResult::TYPE_DEBIT:
                case CallbackResult::TYPE_CAPTURE:
                case CallbackResult::TYPE_VOID:
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
                'default' => $this->method_title,
            ],
            'apiHost' => [
                'title' => 'API Host',
                'type' => 'select',
                'label' => 'Environment',
                'description' => 'Till Environment',
                'default' => TILL_PAYMENTS_EXTENSION_URL,
                'options' => [
                    TILL_PAYMENTS_EXTENSION_URL_TEST => 'Test (Sandbox)',
                    TILL_PAYMENTS_EXTENSION_URL => 'Live (Production)'
                ],
            ],
            'gateway_merchant_id' => [
                'title' => 'Merchant GUID',
                'type' => 'text',
                'description' => 'Merchant GUID from Till Gateway',
                'default' => '',
            ],
            'apiUser' => [
                'title' => 'API User',
                'type' => 'text',
                'description' => 'API User provided by TillPayments',
                'default' => '',
            ],
            'apiPassword' => [
                'title' => 'API Password',
                'type' => 'password',
                'description' => 'API Password provided by TillPayments',
                'default' => '',
            ],
            'apiKey' => [
                'title' => 'API Key',
                'type' => 'password',
                'description' => 'API Key provided by TillPayments',
                'default' => '',
            ],
            'sharedSecret' => [
                'title' => 'Shared Secret',
                'type' => 'password',
                'description' => 'Shared Secret provided by TillPayments',
                'default' => '',
            ],
            
            'environment' => [
                'title' => 'GooglePay Environment',
                'type' => 'select',
                'default' => 'TEST',
                'options' => [
                    'TEST' => 'Googlepay Test Environment',
                    'PRODUCTION' => 'Googlepay Production Environment'
                ]
            ],
            'merchant_id' => [
                'title' => 'GooglePay Production Merchant ID',
                'type' => 'text',
                'description' => 'Your Google Merchant ID, from Google business console',
                'default' => '',
            ],
            'merchant_name' => [
                'title' => 'GooglePay Merchant Name',
                'type' => 'text',
                'description' => 'Your store name, as it appears in the payment sheet',
                'default' => '',
            ],
            'button_type' => [
                'title' => 'Google Pay Button Type',
                'type' => 'select',
                'description' => 'Wording to use on the button. See
                            <a href="https://developers.google.com/pay/api/web/guides/resources/customize" target="_blank" rel="noopener noreferrer">
                                Google docs for a preview</a>.',
                'default' => 'buy',
                'options' => [
                    'buy' => 'Buy with Google Pay',
                    'book' => 'Book with Google Pay',
                    'checkout' => 'Checkout with Google Pay',
                    'donate' => 'Donate with Google Pay',
                    'order' => 'Order with Google Pay',
                    'pay' => 'Pay with Google Pay',
                    'plain' => 'Google Pay',
                    'subscribe' => 'Subscribe with Google Pay',
                ],
            ],
            'button_color' => [
                'title' => 'Google Pay Button Color',
                'type' => 'select',
                'description' => 'Button color to use. See
                            <a href="https://developers.google.com/pay/api/web/guides/resources/customize" target="_blank" rel="noopener noreferrer">
                                Google docs for a preview</a>.',
                'default' => 'black',
                'options' => [
                    'black' => 'Black (for light backgrounds)',
                    'white' => 'White (for dark backgrounds)',
                    'default' => 'Default (selected by Google)',
                ],
            ],
            'allowed_card_networks' => [
                'title' => 'Allowed Card Networks',
                'type' => 'multiselect',
                'description' => 'Card networks you want to accept',
                'default' => ['VISA','MASTERCARD','AMEX', 'DISCOVER', 'INTERAC', 'JCB', ],
                'options' => [
                    'VISA' => 'VISA',
                    'MASTERCARD' => 'MASTERCARD',
                    'AMEX' => 'AMEX',
                    'DISCOVER' => 'DISCOVER',
                    'INTERAC' => 'INTERAC',
                    'JCB' => 'JCB',
                ],
            ],
            'allowed_card_auth_methods' => [
                'title' => 'Allowed Card Authentication methods',
                'type' => 'multiselect',
                'default' => ['PAN_ONLY', 'CRYPTOGRAM_3DS'],
                'description' => 'Card authentication methods you want to accept',
                'options' => [
                    'PAN_ONLY' => 'PAN_ONLY',
                    'CRYPTOGRAM_3DS' => 'CRYPTOGRAM_3DS',
                ],
            ],
            'transactionRequest' => [
                'title' => 'Transaction Request type',
                'type' => 'select',
                'description' => 'Transaction Request Type',
                'default' => 'debit',
                'options' => [
                    'debit' => 'Debit',
                    'preauthorize' => 'Preauthorize only',
                ],
            ],
        ];
    }

    public function payment_fields()
    {
        wp_enqueue_script('till_googlepay_js_' . $this->id);
        wp_enqueue_script('till_googlepay_loader_js_' . $this->id);

        $googlePayFrontendConfig = [
            'environment' => $this->get_option('environment'),
            'button_type' => $this->get_option('button_type'),
            'button_color' => $this->get_option('button_color'),
            'allowed_card_networks' => $this->get_option('allowed_card_networks'),
            'allowed_card_auth_methods' => $this->get_option('allowed_card_auth_methods'),
            'gateway_name' => 'ixopay',
            'gateway_merchant_id' => $this->get_option('gateway_merchant_id'),
            'merchant_id' => $this->get_option('merchant_id'),
            'merchant_name' => $this->get_option('merchant_name'),
            'currency_code' => get_woocommerce_currency(),
            'country' => WC()->countries->get_base_country(),
            'grand_total' => WC()->cart->get_total(''),
        ];

        wp_add_inline_script('till_googlepay_js_' . $this->id, 'window.googlePay = '.json_encode($googlePayFrontendConfig), 'before');

        echo '<style>
        #till_payments_googlepay_errors {color: red; }
        #googlepay-button { height: 65px; }
        #payment .payment_methods li .payment_box.payment_method_till_payments_googlepay { padding: 0; } 
        </style>
        <div id="till_payments_googlepay_errors"></div>
        <div id="till_payments_googlepay">
            <input type="hidden" id="till_payments_googlepay_token" name="googlepay_token">            
        </div>
        ';
    }

    /**
     * Validate frontend fields.
     *
     * Validate payment fields on the frontend.
     *
     * @return bool
     */
    public function validate_fields() {
        if( trim( $_POST[ 'googlepay_token' ]) === '' ) {
            wc_add_notice(  'Payment with Google Pay was not authorized successfully!', 'error' );
            return false;
        }
        return true;
    }

    /** 
     * add payment description
    */
    public function updateDescription($id)
    {
        if ($id == $this->id){
            echo '<div style = "margin-left: 30px; padding: 5px;">You will be able to confirm your payment via the Google Pay payment sheet. Powered by <a href="https://tillpayments.com/">Till Payments</a></div>';
        }
    }
}
