<?php
/**
 * Plugin Name: RayPay for Easy Digital Downloads (EDD)
 * Author: SaminRay
 * Description: <a href="https://raypay.ir">RayPay</a> secure payment gateway for Easy Digital Downloads (EDD)
 * Version: 1.0
 * Author URI: https://saminray.com
 * Author Email: info@saminray.com
 *
 * Text Domain: edd-raypay-gateway
 * Domain Path: languages
 */

if (!defined('ABSPATH')) exit;

/**
 * Load plugin textdomain.
 */
function raypay_for_edd_load_textdomain() {
	load_plugin_textdomain( 'edd-raypay-gateway', false, basename( dirname( __FILE__ ) ) . '/languages' );
}

add_action( 'init', 'raypay_for_edd_load_textdomain' );

include_once( plugin_dir_path( __FILE__ ) . 'includes/edd-raypay-gateway.php' );
