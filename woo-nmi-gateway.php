<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Exit if accessed directly
/*
Plugin Name: NMI Gateway for WooCommerce
Plugin URI: https://bizztoolz.com/plugins
Description: Add the NMI Gateway for WooCommerce.
Version: 1.6.11
Author: BizZToolz
Author URI: https://bizztoolz.com/plugins

WC requires at least: 4.6.2
WC tested up to: 4.7.0

License: GPLv2
*/

define( 'NMI_WOO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( function_exists( 'ngfw_fs' ) ) {
    ngfw_fs()->set_basename( false, __FILE__ );
} else {

	if ( !function_exists( 'ngfw_fs' ) ) {

		function ngfw_fs() {
			global  $ngfw_fs ;
            
            if ( !isset( $ngfw_fs ) ) {
                // Include Freemius SDK.
                require_once dirname( __FILE__ ) . '/freemius/start.php';
                $ngfw_fs = fs_dynamic_init( array(
                    'id'             => '1214',
                    'slug'           => 'woo-nmi-three-step',
                    'type'           => 'plugin',
                    'public_key'     => 'pk_25aa83790f8c599b20186a6c2f3c8',
                    'is_premium'     => false,
                    'premium_suffix' => 'Premium',
                    'has_addons'     => false,
                    'has_paid_plans' => true,
                    'menu'           => array(
						'slug'           => 'wc-settings',
						'override_exact' => true,
						'first-path'     => 'admin.php?page=wc-settings&tab=checkout&section=nmi_gateway',
						'contact'        => false,
						'support'        => false,
						'parent'         => array(
							'slug' => 'woocommerce',
						),
                	),
                    'is_live'        => true,
				) );
			}
            
            return $ngfw_fs;
		}
		ngfw_fs();
        // Signal that SDK was initiated.
        do_action( 'ngfw_fs_loaded' );
        function ngfw_fs_settings_url() {
            return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=nmi_gateway' );
        }
        
        ngfw_fs()->add_filter( 'connect_url', 'ngfw_fs_settings_url' );
        ngfw_fs()->add_filter( 'after_skip_url', 'ngfw_fs_settings_url' );
        ngfw_fs()->add_filter( 'after_connect_url', 'ngfw_fs_settings_url' );
        ngfw_fs()->add_filter( 'after_pending_connect_url', 'ngfw_fs_settings_url' );

		/* Load functions. */
		function bng_gateway_load() {

			if ( class_exists( 'WC_Payment_Gateway' ) ) {
				function wc_Custom_add_bng_gateway( $methods ) {
					$methods[] = 'NMI_GATEWAY_WOO';
					return $methods;
				}

				add_filter( 'woocommerce_payment_gateways', 'wc_Custom_add_bng_gateway' );
				// Include the WooCommerce Custom Payment Gateways classes.
				require_once plugin_dir_path( __FILE__ ) . 'includes/gateway.php';
				require_once plugin_dir_path( __FILE__ ) . 'includes/api.php';
			}
		}

		add_action( 'plugins_loaded', 'bng_gateway_load', 0 );
		/* Adds custom settings url in plugins page. */
		function bng_gateway_action_links( $links ) {
			$settings = array(
				'settings' => sprintf( '<a href="%s">%s</a>', admin_url( 'admin.php?page=wc-settings&tab=checkout&section=nmi_gateway' ), __( 'Manage NMI Gateway', 'nmi_gateway' ) ),
			);
			return array_merge( $settings, $links );
		}

		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'bng_gateway_action_links' );
	}
}
