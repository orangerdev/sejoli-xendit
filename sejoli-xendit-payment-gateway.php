<?php
/**
 *
 * @link              https://ridwan-arifandi.com
 * @since             1.0.0
 * @package           Sejoli
 *
 * @wordpress-plugin
 * Plugin Name:       Sejoli - XENDIT Payment Gateway
 * Plugin URI:        https://sejoli.co.id
 * Description:       Integrate Sejoli Premium WordPress Membership Plugin with XENDIT Payment Gateway.
 * Version:           1.0.0
 * Requires PHP: 	  7.4.1
 * Author:            Sejoli
 * Author URI:        https://sejoli.co.id
 * Text Domain:       sejoli-xendit
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {

	die;

}

// Register payment gateway
add_filter('sejoli/payment/available-libraries', function( array $libraries ) {

    require_once ( plugin_dir_path( __FILE__ ) . '/class-xendit-payment-gateway.php' );

    $libraries['xendit'] = new \SejoliXendit();

    return $libraries;

});

add_action( 'plugins_loaded', 'sejoli_xendit_plugin_init' ); 
function sejoli_xendit_plugin_init() {

    load_plugin_textdomain( 'sejoli-xendit', false, dirname(plugin_basename(__FILE__)).'/languages/' );

}