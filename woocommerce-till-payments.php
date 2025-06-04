<?php
/**
 * Plugin Name: WooCommerce Till Payments Extension
 * Description: Till Payments for WooCommerce
 * Version: 1.11.0
 * Author: Till Payments
 */

use TillPayments\Client\Transaction\Capture;
use TillPayments\Client\Transaction\Result as TransactionResult;

if (!defined('ABSPATH')) {
    exit;
}

define('TILL_PAYMENTS_EXTENSION_URL', 'https://gateway.tillpayments.com/');
define('TILL_PAYMENTS_EXTENSION_URL_TEST', 'https://test-gateway.tillpayments.com/');
define('TILL_PAYMENTS_EXTENSION_NAME', 'Till Payments');
define('TILL_PAYMENTS_EXTENSION_VERSION', '1.10.4');
define('TILL_PAYMENTS_EXTENSION_UID_PREFIX', 'till_payments_');
define('TILL_PAYMENTS_EXTENSION_BASEDIR', plugin_dir_path(__FILE__));

add_action('plugins_loaded', function () {
    require_once TILL_PAYMENTS_EXTENSION_BASEDIR . 'classes/includes/till-payments-provider.php';
    require_once TILL_PAYMENTS_EXTENSION_BASEDIR . 'classes/includes/till-payments-creditcard.php';
    require_once TILL_PAYMENTS_EXTENSION_BASEDIR . 'classes/includes/till-payments-googlepay.php';
    require_once TILL_PAYMENTS_EXTENSION_BASEDIR . 'classes/includes/till-payments-applepay.php';

    add_filter('woocommerce_payment_gateways', function ($methods) {
        foreach (WC_TillPayments_Provider::paymentMethods() as $paymentMethod) {
            $methods[] = $paymentMethod;
        }
        return $methods;
    }, 0);

    // add_filter('woocommerce_before_checkout_form', function(){
    add_filter('the_content', function($content){
        if(is_checkout_pay_page() || is_checkout()) {
            if(!empty($_GET['gateway_return_result']) && $_GET['gateway_return_result'] == 'error') {
                wc_print_notice(__('Payment failed or was declined', 'woocommerce'), 'error');
            }
        }
        return $content;
    }, 0, 1);

    add_action( 'init', 'woocommerce_clear_cart_url' );
    function woocommerce_clear_cart_url() {
        if (isset( $_GET['clear-cart']) && is_order_received_page()) {
            global $woocommerce;

            $woocommerce->cart->empty_cart();
        }
    }

    add_action('admin_enqueue_scripts', function($hook) {
        if ($hook === 'post.php') {
            wp_enqueue_script('tillpayments_capture_script', plugins_url("/tillpayments/assets/js/capture-payments.js"), ['jquery'], TILL_PAYMENTS_EXTENSION_VERSION, false);
            wp_localize_script('tillpayments_capture_script', 'tp_capture', ['security' => wp_create_nonce('tillpayments_capture_payment')]);
        }
    });

    add_action('wp_ajax_tillpayments_capture_payment', function () {
        check_ajax_referer('tillpayments_capture_payment', 'security');

        if (!current_user_can( 'edit_shop_orders')) {
            wp_die(-1);
        }

        $payment_method_code = $_POST['payment_method'];
        $gateway = WC()->payment_gateways()->payment_gateways()[$payment_method_code];

        $gateway->log('Processing new '.$gateway->method_title.' capture...');

        $orderId = !empty($_POST['order_id']) ? $_POST['order_id'] : null;
        if (!$orderId) {
            $gateway->log('  > missing order ID!', WC_Log_Levels::ERROR);
            wp_send_json(['error' => 1, 'msg' => 'Missing order ID!']);
        }

        /**
         * order & user
         */
        $order = new WC_Order($orderId);

        /**
         * gateway client
         */
        WC_TillPayments_Provider::autoloadClient();
        TillPayments\Client\Client::setApiUrl($gateway->get_option('apiHost'));
        $client = new TillPayments\Client\Client(
            $gateway->get_option('apiUser'),
            htmlspecialchars_decode($gateway->get_option('apiPassword')),
            $gateway->get_option('apiKey'),
            $gateway->get_option('sharedSecret')
        );

        /**
         * transaction
         */
        $transaction = new Capture();
        $captureTxId = $orderId . '-capture-' . date('YmdHis') . substr(sha1(uniqid()), 0, 10);
        $transaction->setTransactionId($captureTxId)
            ->setAmount(floatval($order->get_total('')))
            ->setCurrency($order->get_currency())
            ->setReferenceTransactionId($order->get_meta('paymentUuid'));

        /**
         * transaction
         */
        $gateway->log('  > sending capture transaction request...');
        $result = $client->capture($transaction);

        if ($result->isSuccess()) {
            switch ($result->getReturnType()) {
                case TransactionResult::RETURN_TYPE_ERROR:
                    $errors = $result->getErrors();
                    $gateway->log('  > return type: ERROR', WC_Log_Levels::ERROR);
                    $gateway->log('  > errors: '.print_r($errors, true), WC_Log_Levels::ERROR);

                    if (empty($errors)) {
                        wp_send_json(['error' => 1, 'msg' => 'Capture request failed!']);
                    }

                    $errorMsg = '';
                    foreach ($errors as $error) {
                        $errorMsg .= $error->getMessage() . PHP_EOL;
                    }

                    $order->add_order_note('TillPayments capture error: ' . $errorMsg, false);

                    wp_send_json(['error' => 1, 'msg' => $errorMsg]);
                case TransactionResult::RETURN_TYPE_PENDING:
                    $gateway->log('  > return type: PENDING');
                case TransactionResult::RETURN_TYPE_FINISHED:
                    $gateway->log('  > return type: FINISHED');
                    $order->add_order_note('TillPayments capture ID: ' . $result->getReferenceId(), false);

                    $order->update_meta_data('paymentUuid', $result->getReferenceId());
                    $order->update_meta_data('pending_capture', 'no');
                    $order->save_meta_data();

                    $order->payment_complete();

                    $gateway->log('  > result data: '.print_r($result->toArray(), true));

                    wp_send_json(['error' => 0]);
            }
        } else {
            $errors = $result->getErrors();

            if (empty($errors)) {
                wp_send_json(['error' => 1, 'msg' => 'Capture request failed!']);
            }

            $gateway->log('  > request failed', WC_Log_Levels::ERROR);
            $gateway->log('  > errors: '.print_r($errors, true), WC_Log_Levels::ERROR);

            $errorMsg = '';
            foreach ($errors as $error) {
                $errorMsg .= $error->getMessage().PHP_EOL;
            }

            $order->add_order_note('TillPayments capture error: ' . $errorMsg, false);

            wp_send_json(['error' => 1, 'msg' => $errorMsg]);
        }

        /**
         * something went wrong
         */
        $gateway->log('  > fallback return point reached. something went wrong?', WC_Log_Levels::ERROR);
        wp_send_json(['error' => 1, 'msg' => 'Capture request failed!']);
    });
});

add_action( 'woocommerce_blocks_loaded', 'till_gateway_block_support' );
function till_gateway_block_support() {

	// if( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
	// 	return;
	// }

	// here we're including our "gateway block support class"
	require_once __DIR__ . '/classes/includes/till-payments-blocks-support.php';

	// registering the PHP class we have just included
	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
			$payment_method_registry->register( new WC_Till_Gateway_Blocks_Support );
		}
	);

}

add_action( 'before_woocommerce_init', 'till_cart_checkout_blocks_compatibility' );

function till_cart_checkout_blocks_compatibility() {

    if( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				__FILE__,
				true
			);
    }
		
}

/**
 * Ensure payment.js script is loaded at checkout and pointing to the correct host
 */
function enqueue_till_payments_script() {
    if ( is_checkout() ) {
        // Get your plugin settings
        $settings = get_option('woocommerce_till_payments_creditcard_settings', array());
        
        // Get the apiHost setting
        $api_host = isset($settings['apiHost']) ? $settings['apiHost'] : '';
        
        // Check if apiHost contains 'test' (case-insensitive)
        $is_test_mode = stripos($api_host, 'test') !== false;
        
        // Set script URL based on test mode
        $script_url = $is_test_mode 
            ? 'https://test-gateway.tillpayments.com/js/integrated/payment.1.3.min.js'
            : 'https://gateway.tillpayments.com/js/integrated/payment.1.3.min.js';
        
        wp_enqueue_script(
            'till-payments-js',
            $script_url,
            [],
            null,
            true
        );
    }
}
add_action( 'wp_enqueue_scripts', 'enqueue_till_payments_script' );

function add_data_main_attribute( $tag, $handle, $src ) {
    if ( 'till-payments-js' === $handle ) {
        $tag = str_replace( '<script ', '<script data-main="payment-js" ', $tag );
    }
    return $tag;
}
add_filter( 'script_loader_tag', 'add_data_main_attribute', 10, 3 );

function my_plugin_enqueue_assets() {
    if ( is_checkout() ) { // or other conditional you need
        wp_enqueue_style(
            'my-plugin-style', // handle
            plugin_dir_url( __FILE__ ) . 'build/style-index.css', // URL to CSS file
            [], // dependencies
            filemtime( plugin_dir_path( __FILE__ ) . 'build/style-index.css' ) // version based on file modification time for cache busting
        );
    }
}
add_action( 'wp_enqueue_scripts', 'my_plugin_enqueue_assets' );



// ensure block checkout allows my validate function to run 
add_filter(
    'woocommerce_blocks_payment_method_type_registration_data',
    'till_payments_creditcard_block_registration_data',
    10,
    2
);

function till_payments_creditcard_block_registration_data( $data, $gateway_id ) {
    if ( 'till_payments_creditcard' === $gateway_id ) {
        $gateway = new WC_TillPayments_CreditCard();
        $data['title']       = $gateway->get_option('title');
        $data['description'] = $gateway->get_option('description');
        $data['supports']    = $gateway->supports;
        $data['icon']        = $gateway->get_icon();
        return $data;
    }

    return $data;
}
