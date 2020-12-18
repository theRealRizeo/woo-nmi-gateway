<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Exit if accessed directly
/*
Plugin Name: NMI Gateway for WooCommerce
Plugin URI: https://bizztoolz.com/plugins
Description: Add the NMI Gateway for WooCommerce.
Version: 1.0.0
Author: Paul Kevin
Author URI: https://hubloy.com

WC requires at least: 4.6.2
WC tested up to: 4.7.0

License: GPLv2
*/

define( 'NMI_WOO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/* Load functions. */
function nmi_woo_gateway_load() {

	if ( class_exists( 'WC_Payment_Gateway' ) ) {
		function nmi_woo_load_custom_gateway( $methods ) {
			$methods[] = 'NMI_GATEWAY_WOO';
			return $methods;
		}
		add_filter( 'woocommerce_payment_gateways', 'nmi_woo_load_custom_gateway' );
		// Include the WooCommerce Custom Payment Gateways classes.
		require_once plugin_dir_path( __FILE__ ) . 'includes/config.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/logger.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/functions.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/gateway.php';
	}
}

add_action( 'plugins_loaded', 'nmi_woo_gateway_load' );
/* Adds custom settings url in plugins page. */
function nmi_woo_gateway_action_links( $links ) {
	$settings = array(
		'settings' => sprintf( '<a href="%s">%s</a>', admin_url( 'admin.php?page=wc-settings&tab=checkout&section=nmi_gateway' ), __( 'Manage NMI Gateway', 'nmi_gateway' ) ),
	);
	return array_merge( $settings, $links );
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'nmi_woo_gateway_action_links' );
