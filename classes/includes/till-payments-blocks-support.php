<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Till_Gateway_Blocks_Support extends AbstractPaymentMethodType {
	
	private $gateway;
	
	protected $name = 'till_payments_creditcard'; 

	public function initialize() {
		$this->settings = get_option( "woocommerce_{$this->name}_settings", array() );
		$gateways = WC()->payment_gateways->payment_gateways();
		$this->gateway  = $gateways[ $this->name ];		 
		$this->supports = array('products','custom-checkout','refunds');
	}

	public function is_active() {
        // return ! empty( $this->settings[ 'enabled' ] ) && 'yes' === $this->settings[ 'enabled' ];
        return true;
	}

	public function get_payment_method_script_handles() {
		
		wp_register_script(
			'wc-till-blocks-integration',
			plugin_dir_url( __DIR__ ) . '../build/index.js',
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
			),
			time(),// null, // or time() or filemtime( ... ) to skip caching
			true
		);

		return array( 'wc-till-blocks-integration' );

	}

	public function get_payment_method_data() {
		return array(
			'title'     => isset( $this->settings[ 'title' ] ) ? $this->settings[ 'title' ] : 'Default value',
			'description'  => isset( $this->settings[ 'description' ] ) ? $this->settings[ 'description' ] : 'You may be redirected away from this page to complete 3D-Secure verification for your order',
			'supports'  => array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] ),
			 'integrationKey' => $this->get_setting( 'integrationKey' ),
			 'icon' =>  $this->get_setting( 'amex_supported' ) == 'yes' ? plugin_dir_url( __DIR__ ) . '../assets/img/amex_visa_mc.svg' : plugin_dir_url( __DIR__ ) . '../assets/img/visa_mc.svg',
			 'testMode' => stripos($this->get_setting( 'apiHost '), 'test') !== true,
		);
	}
}
