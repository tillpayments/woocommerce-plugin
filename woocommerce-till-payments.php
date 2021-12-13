<?php
/**
 * Plugin Name: WooCommerce Till Payments Extension
 * Description: Till Payments for WooCommerce
 * Version: 1.7.3
 * Author: Till Payments
 * WC requires at least: 3.6.0
 * WC tested up to: 3.7.0
 */
if (!defined('ABSPATH')) {
    exit;
}

define('TILL_PAYMENTS_EXTENSION_URL', 'https://gateway.tillpayments.com/');
define('TILL_PAYMENTS_EXTENSION_NAME', 'Till Payments');
define('TILL_PAYMENTS_EXTENSION_VERSION', '1.7.3');
define('TILL_PAYMENTS_EXTENSION_UID_PREFIX', 'till_payments_');
define('TILL_PAYMENTS_EXTENSION_BASEDIR', plugin_dir_path(__FILE__));

add_action('plugins_loaded', function () {
    require_once TILL_PAYMENTS_EXTENSION_BASEDIR . 'classes/includes/till-payments-provider.php';
    require_once TILL_PAYMENTS_EXTENSION_BASEDIR . 'classes/includes/till-payments-creditcard.php';


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
});
