<?php

use GuzzleHttp\Exception\TransferException;
use TillPayments\Client\Callback\Result;
use TillPayments\Client\Transaction\Debit;
use TillPayments\Client\Transaction\Preauthorize;
use TillPayments\Client\Transaction\Refund;

class WC_TillPayments_ApplePay extends WC_Payment_Gateway
{
    public const APPLE_PAY_ALLOWED_DOMAINS = [
        'apple-pay-gateway.apple.com',
        'cn-apple-pay-gateway.apple.com',
        'apple-pay-gateway-nc-pod1.apple.com',
        'apple-pay-gateway-nc-pod2.apple.com',
        'apple-pay-gateway-nc-pod3.apple.com',
        'apple-pay-gateway-nc-pod4.apple.com',
        'apple-pay-gateway-nc-pod5.apple.com',
        'apple-pay-gateway-pr-pod1.apple.com',
        'apple-pay-gateway-pr-pod2.apple.com',
        'apple-pay-gateway-pr-pod3.apple.com',
        'apple-pay-gateway-pr-pod4.apple.com',
        'apple-pay-gateway-pr-pod5.apple.com',
        'cn-apple-pay-gateway-sh-pod1.apple.com',
        'cn-apple-pay-gateway-sh-pod2.apple.com',
        'cn-apple-pay-gateway-sh-pod3.apple.com',
        'cn-apple-pay-gateway-tj-pod1.apple.com',
        'cn-apple-pay-gateway-tj-pod2.apple.com',
        'cn-apple-pay-gateway-tj-pod3.apple.com',
        'apple-pay-gateway-cert.apple.com',
        'cn-apple-pay-gateway-cert.apple.com',
    ];

    public $id = 'applepay';

    public $method_title = 'Apple Pay';

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
     * @var string
     */
    protected $sessionInitUrl;

    /**
     * @var string
     */
    protected $debugLogUrl;

    /**
     * @var null|WC_Logger
     */
    protected $logger;

    protected $loggerContext = ['source' => 'TillPayments_ApplePay'];

    public function __construct()
    {
        $this->logger = wc_get_logger();

        $this->id = TILL_PAYMENTS_EXTENSION_UID_PREFIX . $this->id;
        $this->method_description = TILL_PAYMENTS_EXTENSION_NAME . ' ' . $this->method_title . ' payments.';
        $this->icon = plugins_url('/tillpayments/assets/img/Apple_Pay_Mark_RGB_041619.svg');
        $this->has_fields = true;

        $this->supports = array(
            'products',
            'refunds'
        );

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->callbackUrl = add_query_arg('wc-api', 'wc_' . $this->id, home_url('/'));
        $this->sessionInitUrl = add_query_arg('wc-api', 'wc_' . $this->id . '_applepay_session', home_url('/'));
        $this->debugLogUrl = add_query_arg('wc-api', 'wc_' . $this->id . '_debuglog', home_url('/'));

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('wp_enqueue_scripts', function () {
            wp_register_script('till_applepay_js_' . $this->id, plugins_url('/tillpayments/assets/js/till-applepay.js'), ['jquery'], TILL_PAYMENTS_EXTENSION_VERSION, false);
        }, 999);
        add_action('woocommerce_api_wc_' . $this->id, [$this, 'process_callback']);
        add_action('woocommerce_api_wc_' . $this->id . '_applepay_session', [$this, 'start_applepay_session']);
        add_action('woocommerce_api_wc_' . $this->id . '_debuglog', [$this, 'debuglog']);
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
        add_filter('woocommerce_order_button_html', [$this, 'injectApplePayButtonIntoOrderButton'], 5, 1);
    }

    public function log(string $msg, string $level = WC_Log_Levels::DEBUG, string $source_suffix = null)
    {
        $context = $this->loggerContext;

        if (is_string($source_suffix)) {
            $context['source'] .= '_'.trim($source_suffix);
        }

        $this->logger->log($level, $msg, $context);
    }

    public function injectApplePayButtonIntoOrderButton($button)
    {
        return
            '<div id="applepay-button" style="cursor:pointer" onclick="void(0)">
                <apple-pay-button 
                    onclick="tillPaymentsApplePay.onApplePayButtonClicked()" 
                    id="real-apple-pay-button" 
                    buttonstyle="'.$this->get_option('button_style').'" 
                    type="'.$this->get_option('button_type').'" 
                    locale="'.get_locale().'">
                </apple-pay-button>
            </div>'
            . $button;
    }

    public function debugLog()
    {
        $msg = file_get_contents('php://input');
        $msg = json_decode($msg, true);

        if (!is_array($msg)) {
            $msg = [$msg];
        }

        foreach ($msg as $_msg) {
            $this->log($_msg, WC_Log_Levels::DEBUG, 'frontend_debug');
        }
    }

    /**
     * Process admin options. This is used to process uploaded files.
     *
     * @return bool
     */
    public function process_admin_options() {
        foreach (['certificate', 'private_key'] as $_fieldname) {
            $fieldname = 'woocommerce_'.$this->id.'_'.$_fieldname;
            if (array_key_exists($fieldname, $_FILES) && $_FILES[$fieldname]['size'] > 0) {
                $_POST[$fieldname] = base64_encode(file_get_contents($_FILES[$fieldname]['tmp_name']));
                unlink($_FILES[$fieldname]['tmp_name']);
                unset($_FILES[$fieldname]);
            } else {
                $_POST[$fieldname] = $this->get_option($_fieldname);
            }
        }

        return parent::process_admin_options();
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

    public function process_payment($order_id)
    {
        $this->log('Processing new Apple Pay payment...');

        global $woocommerce;

        $token = !empty($this->get_post_data()['applepay_token']) ? $this->get_post_data()['applepay_token'] : null;
        if (!$token) {
            $this->log('  > invalid Apple Pay token!', WC_Log_Levels::ERROR);
            return $this->paymentFailedResponse();
        }

        /**
         * order & user
         */
        $this->order = new WC_Order($order_id);
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

        $orderTxId = $this->encodeOrderId($order_id);
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

        $this->log('  > created TillPayments transaction object. orderId: '.$order_id.', orderTxId: '. $orderTxId);

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
            if ($result->getReturnType() == TillPayments\Client\Transaction\Result::RETURN_TYPE_ERROR) {
                $errors = $result->getErrors();
                $this->log('  > return type: ERROR', WC_Log_Levels::ERROR);
                $this->log('  > errors: '.print_r($errors, true), WC_Log_Levels::ERROR);

                return $this->paymentFailedResponse();
            } elseif ($result->getReturnType() == TillPayments\Client\Transaction\Result::RETURN_TYPE_REDIRECT) {
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
            } elseif ($result->getReturnType() == TillPayments\Client\Transaction\Result::RETURN_TYPE_PENDING) {
                $this->log('  > return type: PENDING');
                /**
                 * payment is pending, wait for callback to complete
                 */
            } elseif ($result->getReturnType() == TillPayments\Client\Transaction\Result::RETURN_TYPE_FINISHED) {
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
        $this->log('Processing new Apple Pay refund...');

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
        $result = $client->refund($transaction);

        if ($result->isSuccess()) {
            switch ($result->getReturnType()) {
                case TillPayments\Client\Transaction\Result::RETURN_TYPE_ERROR:
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
                case TillPayments\Client\Transaction\Result::RETURN_TYPE_PENDING:
                    $this->log('  > return type: PENDING');
                    $this->log('  > result data: '.print_r($result->toArray(), true));
                    break;
                case TillPayments\Client\Transaction\Result::RETURN_TYPE_FINISHED:
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
        
        if ($callbackResult->getResult() == Result::RESULT_OK) {
            switch ($callbackResult->getTransactionType()) {
                case Result::TYPE_DEBIT:
                case Result::TYPE_CAPTURE:
                    $this->order->payment_complete();
                    break;
                case Result::TYPE_VOID:
                    $this->order->update_status('cancelled', __('Void', 'woocommerce'));
                    break;
                case Result::TYPE_PREAUTHORIZE:
                    $this->order->update_status('on-hold', __('Awaiting capture/void', 'woocommerce'));
                    break;
            }
        } elseif ($callbackResult->getResult() == Result::RESULT_ERROR) {
            switch ($callbackResult->getTransactionType()) {
                case Result::TYPE_DEBIT:
                case Result::TYPE_CAPTURE:
                case Result::TYPE_VOID:
                    $this->order->update_status('failed', __('Error', 'woocommerce'));
                    break;
            }
        }

        die("OK");
    }

    public function start_applepay_session()
    {
        $oldErrorHandler = set_error_handler(function(
            int $errno,
            string $errstr,
            string $errfile,
            int $errline,
            array $errcontext
        ) {
            $this->log(sprintf('[%s:%d] %s', $errfile, $errline, $errstr), WC_Log_Levels::ERROR);
        });

        $this->log('Starting new ApplePay session...');

        if (!array_key_exists('v', $_GET) || empty($_GET['v'])) {
            $this->log('  > Missing GET parameter v! Aborting.', WC_Log_Levels::ERROR);
            http_response_code(400);
            return;
        }

        $validationUrl = $_GET['v'];
        $decodedValidationUrl = base64_decode($validationUrl, true);

        $this->log('  > decoded validation URL: '.$decodedValidationUrl);

        if ($decodedValidationUrl === false || !$this->validateValidationUrl($decodedValidationUrl)) {
            $this->log('  > invalid validation URL! Aborting.', WC_Log_Levels::ERROR);
            http_response_code(400);
            return;
        }

        $merchantIdCert = base64_decode($this->get_option('certificate'));
        $merchantIdKey = base64_decode($this->get_option('private_key'));
        //$merchantIdKeyPassword = $this->get_option('private_key_password');
        if ($merchantIdCert === false) {
            $this->log('  > missing merchant certificate! Aborting.', WC_Log_Levels::ERROR);
            http_response_code(500);
            return;
        } elseif ($merchantIdKey === false) {
            $this->log('  > missing merchant private key! Aborting.', WC_Log_Levels::ERROR);
            http_response_code(500);
            return;
        } else {
            $tempCertFile = tmpfile();
            fwrite($tempCertFile, $merchantIdCert);
            $merchantIdCert = stream_get_meta_data($tempCertFile)['uri'];

            $tempKeyFile = tmpfile();
            fwrite($tempKeyFile, $merchantIdKey);
            $merchantIdKey = stream_get_meta_data($tempKeyFile)['uri'];

            $this->log('  > temporary cert and key files: '.$merchantIdCert.' / '.$merchantIdKey);
        }

        if (is_string($merchantIdKeyPassword) && strlen($merchantIdKeyPassword) > 0) {
            $this->log('  > using private key decryption password');
            $merchantIdKey = [$merchantIdKey, $merchantIdKeyPassword];
        }

        $requestBody = [
            'merchantIdentifier' => 'merchant.com.tillpayments.prod',
            'displayName' => $this->get_option('merchant_name'),
            'initiative' => 'web',
            'initiativeContext' => $this->get_option('merchant_id_fqdn'),
        ];

        try {
            $this->log('  > merchant ID: '.$requestBody['merchantIdentifier']);

            WC_TillPayments_Provider::autoloadClient();
            $client = new \GuzzleHttp\Client();

            $this->log('  > requesting new ApplePay session...');
            $guzzleResponse = $client->request(
                'POST',
                $decodedValidationUrl,
                [
                    'timeout' => 10,
                    'cert' => $merchantIdCert,
                    'ssl_key' => $merchantIdKey,
                    'json' => $requestBody,
                ]
            );

            $this->log('  > got Apple Pay response, status code: '.$guzzleResponse->getStatusCode());

            if ($guzzleResponse->getStatusCode() !== 200) {
                $this->log('  > aborting with HTTP 400', WC_Log_Levels::ERROR);
                http_response_code(400);
                return;
            }

            http_response_code(200);
            header('Content-type: application/json', true, 200);

            $this->log('  > responding with HTTP 200 and Apple Pay session object in body');

            set_error_handler($oldErrorHandler);

            // our response must be sent with die()
            die((string) $guzzleResponse->getBody());
        } catch (Exception|Throwable|TransferException $exception) {
            $this->log('  > exception: '.$exception->getMessage(), WC_Log_Levels::ERROR);
            $this->log('  > aborting with HTTP 500', WC_Log_Levels::ERROR);
            http_response_code(500);
            return;
        }
    }

    protected function validateValidationUrl(string $url): bool
    {
        $host = wp_parse_url($url, PHP_URL_HOST);
        return in_array($host, self::APPLE_PAY_ALLOWED_DOMAINS);
    }

    /**
     * Return a human-readable file size.
     *
     * @param int $bytes
     * @param int $precision
     * @return string
     * @see https://stackoverflow.com/a/2510459/219467
     */
    private function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KiB', 'MiB', 'GiB', 'TiB');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . '&nbsp;' . $units[$pow];
    }

    /**
     * Generate *.pem File Input HTML.
     *
     * @param string $key Field key.
     * @param array  $data Field data.
     * @since  1.0.0
     * @return string
     */
    public function generate_pemfile_html( $key, $data ) {
        $currentValue = $this->get_option($key);
        if (!empty($currentValue)) {
            $currentSize = strlen(base64_decode($currentValue));
        }

        $field_key = $this->get_field_key( $key );
        $defaults  = array(
            'title'             => '',
            'disabled'          => false,
            'class'             => '',
            'css'               => '',
            'placeholder'       => '',
            'type'              => 'text',
            'desc_tip'          => false,
            'description'       => '',
            'custom_attributes' => array(),
        );

        $data = wp_parse_args( $data, $defaults );

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo $this->get_tooltip_html( $data ); // WPCS: XSS ok. ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
                    <?php if (isset($currentSize)) { echo '<p class="description"><strong>File present. Current file size: '.$this->formatBytes($currentSize, 2).'</strong></p>'; } ?>
                    <input class="input-text regular-input <?php echo esc_attr( $data['class'] ); ?>" type="file" accept="application/x-pem-file,.pem" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" style="<?php echo esc_attr( $data['css'] ); ?>" placeholder="<?php echo esc_attr( $data['placeholder'] ); ?>" <?php disabled( $data['disabled'], true ); ?> <?php echo $this->get_custom_attribute_html( $data ); // WPCS: XSS ok. ?> />
                    <?php echo $this->get_description_html( $data ); // WPCS: XSS ok. ?>
                </fieldset>
            </td>
        </tr>
        <?php

        return ob_get_clean();
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
            'merchant_id_fqdn' => [
                'title' => 'ApplePay Merchant ID FQDN',
                'type' => 'text',
                'description' => 'Fully Qualified Domain Name associated with your ApplePay Merchant ID',
                'default' => '',
            ],
            'certificate' => [
                'title' => 'ApplePay Certificate',
                'type' => 'pemfile',
                'description' => 'Certificate File (*.pem) provided by ApplePay',
                'default' => '',
            ],
            'private_key' => [
                'title' => 'ApplePay Private Key',
                'type' => 'pemfile',
                'description' => 'Private Key File (*.pem) provided by ApplePay',
                'default' => '',
            ],
            'merchant_name' => [
                'title' => 'ApplePay Merchant Name',
                'type' => 'text',
                'description' => 'Your store name',
                'default' => '',
            ],
            'button_type' => [
                'title' => 'ApplePay Button Type',
                'type' => 'select',
                'description' => 'Wording to use on the button. See
                            <a href="https://developer.apple.com/design/human-interface-guidelines/apple-pay/overview/buttons-and-marks/#t01" target="_blank" rel="noopener noreferrer">
                                Apple docs for a preview</a>.',
                'default' => 'buy',
                'options' => [
                    'add-money' => 'Add Money with Apple Pay',
                    'book' => 'Book with Apple Pay',
                    'buy' => 'Buy with Apple Pay',
                    'check-out' => 'Check Out with Apple Pay',
                    'continue' => 'Continue with Apple Pay',
                    'contribute' => 'Contribute with Apple Pay',
                    'donate' => 'Donate with Apple Pay',
                    'order' => 'Order with Apple Pay',
                    'pay' => 'Pay with Apple Pay',
                    'plain' => 'Apple Pay',
                    'reload' => 'Reload with Apple Pay',
                    'rent' => 'Rent with Apple Pay',
                    'subscribe' => 'Subscribe with Apple Pay',
                    'support' => 'Support with Apple Pay',
                    'tip' => 'Tip with Apple Pay',
                    'top-up' => 'Top Up with Apple Pay',
                ],
            ],
            'button_style' => [
                'title' => 'ApplePay Button Style',
                'type' => 'select',
                'description' => 'Button style to use. See
                            <a href="https://developer.apple.com/design/human-interface-guidelines/apple-pay/overview/buttons-and-marks/#button-styles" target="_blank" rel="noopener noreferrer">
                                Apple docs for a preview</a>.',
                'default' => 'black',
                'options' => [
                    'black' => 'Black (for light backgrounds)',
                    'white' => 'White (for dark backgrounds)',
                    'white-outline' => 'White with outline (for light backgrounds)',
                ],
            ],
            'allowed_card_networks' => [
                'title' => 'Allowed Card Networks',
                'type' => 'multiselect',
                'description' => 'Card networks you want to accept',
                'default' => ['visa', 'masterCard', 'amex', 'chinaUnionPay', 'discover', 'interac', 'jcb'],
                'options' => [
                    'visa' => 'VISA',
                    'masterCard' => 'MASTERCARD',
                    'amex' => 'AMEX',
                    'chinaUnionPay' => 'CHINA UNION PAY',
                    'discover' => 'DISCOVER',
                    'interac' => 'INTERAC',
                    'jcb' => 'JCB',
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
        wp_enqueue_script('till_applepay_js_' . $this->id);

        $applePayFrontendConfig = [
            'button_type' => $this->get_option('button_type'),
            'button_style' => $this->get_option('gateway_style'),
            'allowed_card_networks' => $this->get_option('allowed_card_networks'),
            'gateway_name' => $this->get_option('gateway_name'),
            'gateway_merchant_id' => $this->get_option('gateway_merchant_id'),
            'merchant_id' => $this->get_option('merchant_id'),
            'merchant_name' => $this->get_option('merchant_name'),
            'currency_code' => get_woocommerce_currency(),
            'country' => WC()->countries->get_base_country(),
            'grand_total' => WC()->cart->get_total(''),
            'session_init_url' => $this->sessionInitUrl,
            'debuglog_url' => $this->debugLogUrl,
        ];

        wp_add_inline_script('till_applepay_js_' . $this->id, 'window.applePay = '.json_encode($applePayFrontendConfig), 'before');

        echo '<style>
        #till_payments_applepay_errors {color: red; }
        apple-pay-button {
            height: 65px;
            --apple-pay-button-width: 100%;
            --apple-pay-button-height: 65px;
            --apple-pay-button-border-radius: 0px;
        }
        #applepay-button { display: none; }
        #applepay-button_notsupported {  }
        #payment .payment_methods li .payment_box.payment_method_till_payments_applepay { padding: 0; } 
        </style>
        <div id="till_payments_applepay_errors"></div>
        <div id="till_payments_applepay">
            <input type="hidden" id="till_payments_applepay_token" name="applepay_token">            
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
        if( trim( $_POST[ 'applepay_token' ]) === '' ) {
            wc_add_notice('Payment with Apple Pay was not authorized successfully!', 'error');
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
            echo '<div style = "margin-left: 30px; padding: 5px;">You will be able to confirm your payment via the Apple Pay payment sheet. Powered by <a href="https://tillpayments.com/">Till Payments</a></div>';
        }
    }
}
