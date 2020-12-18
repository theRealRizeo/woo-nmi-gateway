<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


class NMI_GATEWAY_WOO extends WC_Payment_Gateway {


	public function __construct() {
		$this->id                 = NMI_Config::$pluginId;
		$this->icon               = NMI_Config::$pluginIcon;
		$this->has_fields         = NMI_Config::$pluginHasFields;
		$this->order_button_text  = __( NMI_Config::$pluginButtonText, NMI_Config::$pluginId );
		$this->method_title       = __( NMI_Config::$pluginMethodTitle, NMI_Config::$pluginId );
		$this->method_description = __( NMI_Config::$pluginDescription, NMI_Config::$pluginId );
		$this->gatewayURL         = NMI_Config::$pluginUrl;
		$this->chosen             = true; // set plugin to be selected on checkout page


		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables.
		$this->title                   = $this->get_option( 'title' );
		$this->description             = $this->get_option( 'description' );
		$this->instructions            = $this->get_option( 'instructions' );
		$this->apikey                  = sanitize_text_field( $this->get_option( 'apikey' ) );
		$this->tokenizationkey         = sanitize_text_field( $this->get_option( 'tokenizationkey' ) );
		$this->transactiontype         = sanitize_text_field( $this->get_option( 'transactiontype' ) );
		$this->finalorderstatus        = $this->get_option( 'finalorderstatus' );
		$this->redirecturl             = $this->get_option( 'redirecturl' );
		$this->savepaymentmethodtoggle = $this->get_option( 'savepaymentmethodstoggle' );
		$this->paymenttype             = $this->get_option( 'paymenttype' );
		$this->debug                   = 'yes' === $this->get_option( 'debug', 'no' );

		// Actions.
		add_action( 'woocommerce_api_callback', array( $this, 'successful_request' ) );
		//add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		//add_action( 'woocommerce_confirm_order_' . $this->id, array( $this, 'confirm_order_page' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'validate_options' ) );
		add_action( 'after_woocommerce_add_payment_method', array( $this, 'add_bng_gateway_payment_method_form' ) );
		add_action( 'woocommerce_after_account_payment_methods', array( $this, 'add_nmi_delete_action_to_woocommerce' ) );
		add_action( 'woocommerce_saved_payment_methods_list', array( $this, 'get_saved_payment_method_list' ) );

		// subscription hooks and filters
		add_action( 'woocommerce_subscription_cancelled_' . $this->id, array( $this, 'cancel_bng_subscription' ) );
		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
		add_action( 'woocommerce_subscription_validate_payment_meta_' . $this->id, array( $this, 'validate_admin_paymentmethod_change' ) );
		add_action( 'woocommerce_subscription_failing_payment_method_updated_' . $this->id, array( $this, 'subscription_payment_method_change' ), 10, 2 );

		add_filter( 'woocommerce_subscription_payment_meta', array( $this, 'add_admin_change_payment_method_form' ), 10, 2 );
		add_filter( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', array( $this, 'maybe_update_all_subscriptions_payment_method' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

		if ( isset( $_GET['token-id'] ) && isset( $_GET['order'] ) && ! isset( $_GET['complete'] ) ) {
			$this->successful_request( sanitize_text_field( $_GET['token-id'] ), sanitize_text_field( $_GET['order'] ) );
		} elseif ( isset( $_POST['bng_payment_token'] ) && ! isset( $_POST['complete'] ) ) {
			$this->collectjs_to_direct_post_request();
		}

		$this->supports = array(
			'refunds',
			'tokenization',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'subscription_payment_method_delayed_change',
			'multiple_subscriptions',
		);

		if ( ( ngfw_fs()->is_not_paying() || ngfw_fs()->is_free_plan() ) && NMI_Config::$projectType == 'nmi' ) {
			$this->supports = array(
				'refunds',
				'tokenization',
			);
		}
	}

	/**
	 * Admin Panel Options.
	 */
	function admin_options() {
		?>
			<h3><?php _e( 'NMI Gateway For WooCommerce', NMI_Config::$pluginId ); ?></h3>
			<table class="form-table">
				<?php $this->generate_settings_html(); ?>
			</table> 
		<?php
	}

	/**
	 * Initialize Gateway Settings Form Fields.
	 */
	function init_form_fields() {
		global  $woocommerce;
		$shipping_methods = array();
		if ( is_admin() ) {
			foreach ( $woocommerce->shipping->load_shipping_methods() as $method ) {
				$shipping_methods[ $method->id ] = $method->get_title();
			}
		}

		$this->form_fields = array(
			'enabled'                  => array(
				'title'   => __( 'Enable/Disable', NMI_Config::$pluginId ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable NMI Gateway For WooCommerce', NMI_Config::$pluginId ),
				'default' => 'no',
			),
			'title'                    => array(
				'title'       => __( 'Title', NMI_Config::$pluginId ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', NMI_Config::$pluginId ),
				'default'     => __( 'Credit Card / ECheck (NMI)', NMI_Config::$pluginId ),
				'desc_tip'    => true,
			),
			'description'              => array(
				'title'       => __( 'Description', NMI_Config::$pluginId ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', NMI_Config::$pluginId ),
				'desc_tip'    => true,
				'default'     => __( '', NMI_Config::$pluginId ),
			),
			'instructions'             => array(
				'title'       => __( 'Instructions', NMI_Config::$pluginId ),
				'type'        => 'textarea',
				'description' => __( 'Instructions that will be added to the thank you page.', NMI_Config::$pluginId ),
				'desc_tip'    => true,
				'default'     => __( '', NMI_Config::$pluginId ),
			),
			'apikey'                   => array(
				'title'       => __( 'API Key (Required)', NMI_Config::$pluginId ),
				'type'        => 'password',
				'description' => __( 'NMI merchant account API key', NMI_Config::$pluginId ),
				'desc_tip'    => true,
				'default'     => '',
			),
			'tokenizationkey'          => array(
				'title'       => __( 'Tokenization Key (Optional)', NMI_Config::$pluginId ),
				'type'        => 'password',
				'description' => __( 'Tokenization Key integrates with collect.js to allow merchants collect sensitive payment data from customers. You can choose to use collect.js by inserting a tokenization key, otherwise, simply leave this part blank.', NMI_Config::$pluginId ),
				'desc_tip'    => true,
				'default'     => '',
			),
			'savepaymentmethodstoggle' => array(
				'title'       => __( 'Turn Saved Payment Methods On/Off', NMI_Config::$pluginId ),
				'type'        => 'select',
				'description' => __( 'Allows you to turn saved payment methods on and off.', NMI_Config::$pluginId ),
				'default'     => 'off',
				'desc_tip'    => true,
				'options'     => array(
					'on'  => 'On',
					'off' => 'Off',
				),
			),
			'transactiontype'          => array(
				'title'       => __( 'Transaction Type', NMI_Config::$pluginId ),
				'type'        => 'select',
				'description' => __( 'Authorize only transaction types works when only credit card payment type is used.', NMI_Config::$pluginId ),
				'default'     => 'sale',
				'desc_tip'    => true,
				'options'     => array(
					'auth' => 'Authorize Only',
					'sale' => 'Authorize & Capture',
				),
			),
			'paymenttype'              => array(
				'title'       => __( 'Payment Type', NMI_Config::$pluginId ),
				'type'        => 'select',
				'description' => __( 'Allows you to make payments with either credit cards, electronic checks, or both. If credit card and echecks are selected, echeck payments will be authorized and captured.', NMI_Config::$pluginId ),
				'default'     => 'both',
				'desc_tip'    => true,
				'options'     => array(
					'cc'   => 'Credit Card Only',
					'ach'  => 'ECheck Only',
					'both' => 'Credit Card and ECheck',
				),
			),
			'finalorderstatus'         => array(
				'title'       => __( 'Final Order Status', NMI_Config::$pluginId ),
				'type'        => 'select',
				'description' => __( 'This option allows you to set the final status of an order after it has been processed successfully by the gateway.', NMI_Config::$pluginId ),
				'default'     => 'Processing',
				'desc_tip'    => true,
				'options'     => array(
					'Processing' => 'Processing',
					'Pending'    => 'Pending',
					'On-Hold'    => 'On-Hold',
					'Completed'  => 'Completed',
				),
			),
			'redirecturl'              => array(
				'title'       => __( 'Return URL', NMI_Config::$pluginId ),
				'type'        => 'text',
				'description' => '<b>' . __( '*OPTIONAL*', NMI_Config::$pluginId ) . '</b> <br />' . __( 'This is the URL the user will be taken to once the sale has been completed. Please enter the full URL of the page. It must be an active page on the same website. If left blank, it will take the buyer to the default order received page.', NMI_Config::$pluginId ),
				'desc_tip'    => true,
				'default'     => '',
			),

			'debug' => array(
				'title'         => __( 'Debug Log' ),
				'type'          => 'checkbox',
				'label'         => __( 'Enable logging' ),
				'default'       => 'no',
				'description'   => __( 'Log Gateway events, such as IPN requests' ),
			)
		);
	}

	/**
	 * Sets up payment fields
	 */
	function payment_fields() {
		echo '<div class="woo-nmi-new-card" id="nmi-payment-data">';

		if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }
		if ( $this->apikey && $this->tokenizationkey ) {
			$this->collect_js_form();
		} else {
			$this->form();
		}
		echo '</div>';
	}

	/**
	 * Credit card form
	 */
	public function collect_js_form() {
		?>
		<fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent;">
			<?php do_action( 'woocommerce_credit_card_form_start', $this->id ); ?>

            <div class="form-row form-row-wide">
                <label for="woo-nmi-card-number-element"><?php esc_html_e( 'Card Number' ); ?> <span class="required">*</span></label>
                <div class="woo-nmi-card-group">
                    <div id="woo-nmi-card-number-element" class="wc-nmi-elements-field">
                    <!-- a NMI Element will be inserted here. -->
                    </div>

                    <i class="woo-nmi-credit-card-brand nmi-card-brand" alt="Credit Card"></i>
                </div>
            </div>

            <div class="form-row form-row-first">
                <label for="woo-nmi-card-expiry-element"><?php esc_html_e( 'Expiry Date' ); ?> <span class="required">*</span></label>

                <div id="woo-nmi-card-expiry-element" class="wc-nmi-elements-field">
                <!-- a NMI Element will be inserted here. -->
                </div>
            </div>

            <div class="form-row form-row-last">
                <label for="woo-nmi-card-cvc-element"><?php esc_html_e( 'Card Code (CVC)' ); ?> <span class="required">*</span></label>
                <div id="woo-nmi-card-cvc-element" class="wc-nmi-elements-field">
                <!-- a NMI Element will be inserted here. -->
                </div>
            </div>
            <div class="clear"></div>

			<!-- Used to display form errors -->
			<div class="woo-nmi-source-errors" role="alert"></div>
			<br />
			<?php do_action( 'woocommerce_credit_card_form_end', $this->id ); ?>
			<div class="clear"></div>
		</fieldset>
		<?php
	}


	public function payment_scripts() {
		if ( ! $this->tokenizationkey || ! $this->apikey || ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) && ! is_add_payment_method_page() ) ) {
			return;
		}

        add_filter( 'script_loader_tag', array( $this, 'add_public_key_to_js' ), 10, 2 );

		wp_enqueue_script( 'nmi-collect-js', 'https://secure.nmi.com/token/Collect.js', '', null, true );
		wp_enqueue_script( 'wc_nmi_checkout', NMI_WOO_PLUGIN_URL. 'js/checkout.js' , array( 'jquery-payment', 'nmi-collect-js' ), '1.0.0', true );

		$nmi_params = array(
			'public_key'           	=> $this->apikey,
			'i18n_terms'           	=> __( 'Please accept the terms and conditions first' ),
			'i18n_required_fields'	=> __( 'Please fill in required checkout fields first' ),
            'card_disallowed_error' => __( 'Card Type Not Accepted.' ),
            'placeholder_cvc'	 	=> __( 'CVC', 'woocommerce' ),
            'placeholder_expiry' 	=> __( 'MM / YY', 'woocommerce' ),
            'card_number_error' 	=> __( 'Invalid card number.' ),
            'card_expiry_error' 	=> __( 'Invalid card expiry date.' ),
            'card_cvc_error' 		=> __( 'Invalid card CVC.' ),
            'error_ref' 			=> __( '(Ref: [ref])' ),
            'timeout_error' 		=> __( 'The tokenization did not respond in the expected timeframe. Please make sure the fields are correctly filled in and submit the form again.' ),
		);
        $nmi_params['is_checkout'] = ( is_checkout() && empty( $_GET['pay_for_order'] ) ) ? 'yes' : 'no'; // wpcs: csrf ok.

		wp_localize_script( 'wc_nmi_checkout', 'wc_nmi_checkout_params', apply_filters( 'wc_nmi_checkout_params', $nmi_params ) );
	}

	/**
	 * Add the public key to the src
	 */
	public function add_public_key_to_js( $tag, $handle ) {
		if ( 'nmi-collect-js' !== $handle ) return $tag;
		return str_replace( ' src', ' data-tokenization-key="' . $this->tokenizationkey . '" src', $tag );
	}

	/**
	 * Process the nmi response
	 */
	public function get_nmi_js_response() {
        if( !isset( $_POST['nmi_js_response'] ) ) {
            return false;
        }
		$response = json_decode( stripslashes( $_POST['nmi_js_response'] ), 1 );
		return $response;
	}

	/**
	 * Returns a list of saved payment methods if the user is on the premium version of the plugin
	 * If the user is utilizing the free version, and there are saved payment methods,
	 * it removes all the saved payment methods and deletes the customer vault from the gateway
	 *
	 * @param array $savedMethods - a list of all saved payment methods
	 *
	 * @return array an array of saved payment methods
	 */
	function get_saved_payment_method_list( $savedMethods ) {
		if ( empty( $savedMethods ) || ngfw_fs()->is_plan( 'Premium' ) || NMI_Config::$projectType === 'bng' ) {
			bng701_screen_payment_methods_against_nmi( $this->apikey );
		} else {
			// else delete pm in woo and delete vault in nmi
			$paymentTokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id() );

			foreach ( $paymentTokens as $paymentToken ) {
				WC_Payment_Tokens::delete( $paymentToken->get_id() );
				$customerVaultId = $paymentToken->get_meta( 'vaultid' );
			}

			if ( ! empty( $customerVaultId ) ) {
				$query  = 'customer_vault=delete_customer';
				$query .= '&customer_vault_id=' . urlencode( $customerVaultId );
				$query .= '&security_key=' . urlencode( $this->apikey );

				$response = bng701_doCurl( $query );
				WC_NMI_Logger::log( $response['responsetext'] );
			}
		}
		return wc_get_account_saved_payment_methods_list( [], get_current_user_id() );
	}

	/**
	 * Sets up the receipt page
	 *
	 * @param string $order_id - the order id
	 */
	function receipt_page( $order_id ) {
		if ( function_exists( 'wcs_is_subscription' ) ) {
			$order = wcs_is_subscription( $order_id ) ? new WC_Subscription( $order_id ) : wc_get_order( $order_id );
		} else {
			$order = wc_get_order( $order_id );
		}

		if ( get_current_user_id() > 0 ) {
			$userid     = get_current_user_id();
			$user       = get_userdata( $userid );
			$user_email = sanitize_email( $user->user_email );
		} else {
			$user_email = '';
		}
		// get settings
		$this->init_settings();

		$order_shipping = $order->get_shipping_total();
		$order_tax      = $order->get_total_tax();
		$order_total    = $order->get_total();

		// fix for having product names with double quotes - thanks to abirchler for pointing this one out
		$data = array(
			'orderid'           => bng701_cleanTheData( $order_id, 'integer' ),
			'transactiontype'   => bng701_cleanTheData( $this->transactiontype, 'string' ),
			'paymenttype'       => bng701_cleanTheData( $this->paymenttype, 'string' ),
			'user_email'        => sanitize_email( $user_email ),
			'ordertotal'        => $order_total,
			'ordertax'          => $order_tax,
			'ordershipping'     => $order_shipping,
			'billingfirstname'  => bng701_cleanTheData( $order->get_billing_first_name(), 'string' ),
			'billinglastname'   => bng701_cleanTheData( $order->get_billing_last_name(), 'string' ),
			'billingaddress1'   => bng701_cleanTheData( $order->get_billing_address_1(), 'string' ),
			'billingcity'       => bng701_cleanTheData( $order->get_billing_city(), 'string' ),
			'billingstate'      => bng701_cleanTheData( $order->get_billing_state(), 'string' ),
			'billingpostcode'   => bng701_cleanTheData( $order->get_billing_postcode(), 'string' ),
			'billingcountry'    => bng701_cleanTheData( $order->get_billing_country(), 'string' ),
			'billingemail'      => sanitize_email( $order->get_billing_email() ),
			'billingphone'      => sanitize_text_field( $order->get_billing_phone() ),
			'billingcompany'    => bng701_cleanTheData( $order->get_billing_company(), 'string' ),
			'billingaddress2'   => bng701_cleanTheData( $order->get_billing_address_2(), 'string' ),
			'shippingfirstname' => bng701_cleanTheData( $order->get_shipping_first_name(), 'string' ),
			'shippinglastname'  => bng701_cleanTheData( $order->get_shipping_last_name(), 'string' ),
			'shippingaddress1'  => bng701_cleanTheData( $order->get_shipping_address_1(), 'string' ),
			'shippingcity'      => bng701_cleanTheData( $order->get_shipping_city(), 'string' ),
			'shippingstate'     => bng701_cleanTheData( $order->get_shipping_state(), 'string' ),
			'shippingpostcode'  => sanitize_text_field( $order->get_shipping_postcode() ),
			'shippingcountry'   => bng701_cleanTheData( $order->get_shipping_country(), 'string' ),
			'shippingphone'     => sanitize_text_field( $order->get_billing_phone() ),
			'shippingcompany'   => bng701_cleanTheData( $order->get_shipping_company(), 'string' ),
			'shippingaddress2'  => bng701_cleanTheData( $order->get_shipping_address_2(), 'string' ),
			'security'          => wp_create_nonce( 'checkout-nonce' ),
		);

		if ( $this->apikey != '' ) {
			$paymentMethods = array();

			if ( ngfw_fs()->is_plan( 'Premium' ) || NMI_Config::$projectType == 'bng' ) {
				if ( is_user_logged_in() && $this->savepaymentmethodtoggle == 'on' ) {
					// display saved pm's (new way)
					// get the saved payment tokens from WC
					$temp_payment_tokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id() );

					if ( ! empty( $temp_payment_tokens ) ) {
						bng701_screen_payment_methods_against_nmi( $this->apikey );

						// after screening against nmi, grab data again for display
						$highlight      = false;
						$payment_tokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id() );
						if ( class_exists( 'WC_Subscriptions' ) ) {
							if ( wcs_order_contains_resubscribe( $order ) || wcs_order_contains_renewal( $order ) || wcs_is_subscription( $order_id ) ) {
								if ( wcs_order_contains_renewal( $order ) ) {
									$sub_id = (int) $order->get_meta( '_subscription_renewal' );
									$order  = wcs_get_subscription( $sub_id );
								}
								$sub_billingId = $order->get_meta( '_bng_gateway_billing_id' );
							}
						}

						foreach ( $payment_tokens as $pt ) {
							$billing_id    = $pt->get_token();
							$paymentmethod = bng701_getPMDetailsByBillingId( $billing_id, $this->apikey );
							$type          = $pt->get_type();
							if ( ! empty( $sub_billingId ) && $sub_billingId === $billing_id ) {
								$highlight = true;
							}

							$thisPaymentMethod               = array();
							$thisPaymentMethod['internalId'] = $pt->get_id();
							$thisPaymentMethod['highlight']  = $highlight;
							$thisPaymentMethod['type']       = $type;

							if ( $type == 'CC' ) {
								$thisPaymentMethod['ccNumber'] = $paymentmethod['customer']['billing']['cc_number'];
								$thisPaymentMethod['ccExp']    = substr_replace( $paymentmethod['customer']['billing']['cc_exp'], '/', 2, 0 );

								$cardtype                  = bng701_getCardType( $paymentmethod['customer']['billing']['cc_type'] );
								$thisPaymentMethod['card'] = plugins_url( "img/icon_cc_{$cardtype}.png", __FILE__ );
								if ( $pt->get_data()['card_type'] == 'unknown' ) {
									$pt->update_meta_data( 'card_type', $cardtype );
									$pt->save();
								}
							} elseif ( $type == 'eCheck' ) {
								$thisPaymentMethod['acctName']   = $paymentmethod['customer']['billing']['check_name'];
								$thisPaymentMethod['acctNumber'] = $paymentmethod['customer']['billing']['check_account'];

								$thisPaymentMethod['check'] = plugins_url( 'img/icon_check.png', __FILE__ );
							}

							if ( $highlight === true ) {
								$highlight = false;
							}

							$thisPaymentMethod['nonce'] = wp_create_nonce( 'delete_pm' . $billing_id );
							array_push( $paymentMethods, $thisPaymentMethod );
						}
					}
				}
			}

			bng701_html( $this->savepaymentmethodtoggle, $this->tokenizationkey, $data, $order_id, $paymentMethods );
		} else {
			WC_NMI_Logger::log( "Error : Current user {$userid}, does not have an api key" );
			?>
			<div style="color: red;font-size:16px;border:1px solid red;border-radius:10px;background-color:#FDFDFD;padding:15px;">
				<b>Checkout is not available at this time.</b><br>
				Please try again later, once you have the API Key.
			</div>            
			<?php
		}
	}


	function process_inline_order( $order_id ) {
		if ( function_exists( 'wcs_is_subscription' ) ) {
			$order = wcs_is_subscription( $order_id ) ? new WC_Subscription( $order_id ) : wc_get_order( $order_id );
		} else {
			$order = wc_get_order( $order_id );
		}

		if ( get_current_user_id() > 0 ) {
			$userid     = get_current_user_id();
			$user       = get_userdata( $userid );
			$user_email = sanitize_email( $user->user_email );
		} else {
			$user_email = '';
		}
		// get settings
		$this->init_settings();

		$order_shipping = $order->get_shipping_total();
		$order_tax      = $order->get_total_tax();
		$order_total    = $order->get_total();

		// fix for having product names with double quotes - thanks to abirchler for pointing this one out
		$data = array(
			'orderid'           => bng701_cleanTheData( $order_id, 'integer' ),
			'transactiontype'   => bng701_cleanTheData( $this->transactiontype, 'string' ),
			'paymenttype'       => bng701_cleanTheData( $this->paymenttype, 'string' ),
			'user_email'        => sanitize_email( $user_email ),
			'ordertotal'        => $order_total,
			'ordertax'          => $order_tax,
			'ordershipping'     => $order_shipping,
			'billingfirstname'  => bng701_cleanTheData( $order->get_billing_first_name(), 'string' ),
			'billinglastname'   => bng701_cleanTheData( $order->get_billing_last_name(), 'string' ),
			'billingaddress1'   => bng701_cleanTheData( $order->get_billing_address_1(), 'string' ),
			'billingcity'       => bng701_cleanTheData( $order->get_billing_city(), 'string' ),
			'billingstate'      => bng701_cleanTheData( $order->get_billing_state(), 'string' ),
			'billingpostcode'   => bng701_cleanTheData( $order->get_billing_postcode(), 'string' ),
			'billingcountry'    => bng701_cleanTheData( $order->get_billing_country(), 'string' ),
			'billingemail'      => sanitize_email( $order->get_billing_email() ),
			'billingphone'      => sanitize_text_field( $order->get_billing_phone() ),
			'billingcompany'    => bng701_cleanTheData( $order->get_billing_company(), 'string' ),
			'billingaddress2'   => bng701_cleanTheData( $order->get_billing_address_2(), 'string' ),
			'shippingfirstname' => bng701_cleanTheData( $order->get_shipping_first_name(), 'string' ),
			'shippinglastname'  => bng701_cleanTheData( $order->get_shipping_last_name(), 'string' ),
			'shippingaddress1'  => bng701_cleanTheData( $order->get_shipping_address_1(), 'string' ),
			'shippingcity'      => bng701_cleanTheData( $order->get_shipping_city(), 'string' ),
			'shippingstate'     => bng701_cleanTheData( $order->get_shipping_state(), 'string' ),
			'shippingpostcode'  => sanitize_text_field( $order->get_shipping_postcode() ),
			'shippingcountry'   => bng701_cleanTheData( $order->get_shipping_country(), 'string' ),
			'shippingphone'     => sanitize_text_field( $order->get_billing_phone() ),
			'shippingcompany'   => bng701_cleanTheData( $order->get_shipping_company(), 'string' ),
			'shippingaddress2'  => bng701_cleanTheData( $order->get_shipping_address_2(), 'string' ),
			'security'          => wp_create_nonce( 'checkout-nonce' ),
		);

		if ( $this->apikey != '' ) {
			$paymentMethods = array();

			if ( ngfw_fs()->is_plan( 'Premium' ) || NMI_Config::$projectType == 'bng' ) {
				if ( is_user_logged_in() && $this->savepaymentmethodtoggle == 'on' ) {
					// display saved pm's (new way)
					// get the saved payment tokens from WC
					$temp_payment_tokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id() );

					if ( ! empty( $temp_payment_tokens ) ) {
						bng701_screen_payment_methods_against_nmi( $this->apikey );

						// after screening against nmi, grab data again for display
						$highlight      = false;
						$payment_tokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id() );
						if ( class_exists( 'WC_Subscriptions' ) ) {
							if ( wcs_order_contains_resubscribe( $order ) || wcs_order_contains_renewal( $order ) || wcs_is_subscription( $order_id ) ) {
								if ( wcs_order_contains_renewal( $order ) ) {
									$sub_id = (int) $order->get_meta( '_subscription_renewal' );
									$order  = wcs_get_subscription( $sub_id );
								}
								$sub_billingId = $order->get_meta( '_bng_gateway_billing_id' );
							}
						}

						foreach ( $payment_tokens as $pt ) {
							$billing_id    = $pt->get_token();
							$paymentmethod = bng701_getPMDetailsByBillingId( $billing_id, $this->apikey );
							$type          = $pt->get_type();
							if ( ! empty( $sub_billingId ) && $sub_billingId === $billing_id ) {
								$highlight = true;
							}

							$thisPaymentMethod               = array();
							$thisPaymentMethod['internalId'] = $pt->get_id();
							$thisPaymentMethod['highlight']  = $highlight;
							$thisPaymentMethod['type']       = $type;

							if ( $type == 'CC' ) {
								$thisPaymentMethod['ccNumber'] = $paymentmethod['customer']['billing']['cc_number'];
								$thisPaymentMethod['ccExp']    = substr_replace( $paymentmethod['customer']['billing']['cc_exp'], '/', 2, 0 );

								$cardtype                  = bng701_getCardType( $paymentmethod['customer']['billing']['cc_type'] );
								$thisPaymentMethod['card'] = plugins_url( "img/icon_cc_{$cardtype}.png", __FILE__ );
								if ( $pt->get_data()['card_type'] == 'unknown' ) {
									$pt->update_meta_data( 'card_type', $cardtype );
									$pt->save();
								}
							} elseif ( $type == 'eCheck' ) {
								$thisPaymentMethod['acctName']   = $paymentmethod['customer']['billing']['check_name'];
								$thisPaymentMethod['acctNumber'] = $paymentmethod['customer']['billing']['check_account'];

								$thisPaymentMethod['check'] = plugins_url( 'img/icon_check.png', __FILE__ );
							}

							if ( $highlight === true ) {
								$highlight = false;
							}

							$thisPaymentMethod['nonce'] = wp_create_nonce( 'delete_pm' . $billing_id );
							array_push( $paymentMethods, $thisPaymentMethod );
						}
					}
				}
			}

			bng701_html( $this->savepaymentmethodtoggle, $this->tokenizationkey, $data, $order_id, $paymentMethods );
		} else {
			WC_NMI_Logger::log( "Error : Current user {$userid}, does not have an api key " );
			?>
			<div style="color: red;font-size:16px;border:1px solid red;border-radius:10px;background-color:#FDFDFD;padding:15px;">
				<b>Checkout is not available at this time.</b><br>
				Please try again later, once you have the API Key.
			</div>            
			<?php
		}
	}


	/**
	 * Adds payment method form to the account screen page
	 * if the user is using the premium version
	 */
	function add_bng_gateway_payment_method_form() {
		if ( $this->apikey ) {
			// this assumes the store only saves payment methods using our gateway
			if ( is_user_logged_in() && $this->savepaymentmethodtoggle == 'on' ) {
				if ( ngfw_fs()->is_plan( 'Premium' ) || NMI_Config::$projectType === 'bng' ) {
					bng701_html( $this->savepaymentmethodtoggle, $this->tokenizationkey, array( 'isAcctScreen' => true ) );

					?>
						<script type="text/javascript">
							if (jQuery('#payment_method_<?php echo esc_attr( $this->id ); ?>').prop('checked')) {
								jQuery('#payment_method_<?php echo esc_attr( $this->id ); ?>').prop('parentElement').appendChild(jQuery('.bng-form-group').get()[0]);

								jQuery('.bng_acct_screen').removeClass('cc_type');
								jQuery('#bng_submitPayment').remove();

								jQuery('.detailsDiv').hide();
								jQuery('.bng_acct_screen').hide();
							}

							jQuery('#place_order').on('click', async function(event) {
								event.preventDefault();
								event.target.disabled = true;
								if (storedVariables.useTokenization()) submitOrderUsingOldPaymentMethod();
								else await bng701_cc_validate(true);
							});
						</script>
					<?php
				}
			}
		}
	}

	/**
	 * Adds onclick method to the delete buttton of the account screen page
	 * Inorder to allow users to delete payment methods in the gateway without
	 * being on the checkout page
	 *
	 * @param bool $hasMethods - specifies if the plugin has payment methods
	 */
	function add_nmi_delete_action_to_woocommerce( $hasMethods ) {
		if ( $hasMethods ) {
			?>
				<script type="text/javascript">
					jQuery('.button.delete').on('click', async function(event) {
						event.target.disabled = true;
						deleteObj = event.target;
						var tempHref = deleteObj.href;
						
						var linkArray = tempHref.split('/');                        
						deleteObj.setAttribute('href', 'javascript:;');
						deleteObj.setAttribute('tokenId', linkArray[5]);

						var toContinue = await bng701_deletePM( deleteObj, true );
						if (typeof(toContinue) === "boolean") {
							if ( !toContinue ) {
								return false;
							}
						}
						
						location.href = tempHref;
						return true;
					});
				</script>
			<?php
		}
	}

	/**
	 * Adds ability to change payment method for subscription
	 * on the admin screen
	 *
	 * @param array  $payment_meta - the payment method meta data
	 * @param object $subscription - WC_Subscription object
	 *
	 * @return array $payment meta
	 */
	function add_admin_change_payment_method_form( $payment_meta, $subscription ) {
		if ( class_exists( 'WC_Subscriptions' ) && ( ngfw_fs()->is_plan( 'Premium' ) || NMI_Config::$projectType === 'bng' ) ) {
			$vaultId   = empty( $subscription->get_meta( '_nmi_gateway_vault_id' ) ) ? 'null' : $subscription->get_meta( '_nmi_gateway_vault_id' );
			$billingId = empty( $subscription->get_meta( '_nmi_gateway_billing_id' ) ) ? 'null' : $subscription->get_meta( '_nmi_gateway_billing_id' );

			$payment_meta[ $this->id ] = array(
				'post_meta' => array(
					'_nmi_gateway_vault_id'   => array(
						'value' => $vaultId,
						'label' => 'NMI Gateway Customer ID',
					),
					'_nmi_gateway_billing_id' => array(
						'value' => $billingId,
						'label' => 'NMI Gateway Billing ID',
					),
				),
			);
		}
		return $payment_meta;
	}

	/**
	 * Adds new payment method from the account screen page to
	 * woocommerce and the gateway
	 */
	function add_payment_method() {
		$success = ( $_POST['saved_new_pm'] == 'true' ) ? true : false;
		if ( $success ) {
			return array(
				'result'   => 'success',
				'redirect' => wc_get_endpoint_url( 'payment-methods' ),
			);
		} else {
			return array(
				'result'   => 'failure',
				'redirect' => wc_get_endpoint_url( 'payment-methods' ),
			);
		}
	}

	/**
	 * Successful Payment
	 * Used to complete processing transaction through three step
	 **/
	function successful_request( $tokenid, $orderid ) {
		$_GET['complete'] = 'true';

		// check to see if the order var contains an action or transactiontype
		if ( stristr( $orderid, '***' ) ) {
			$splitme = explode( '***', $orderid );
			$orderid = $splitme[0];

			$splitme_action = explode( '=', $splitme[1] );
			$action         = $splitme_action[1];
			$trans_type     = sanitize_text_field( explode( '=', $splitme[3] )[1] );
			$acctScreen     = isset( $splitme[4] ) ? (bool) sanitize_text_field( explode( '=', $splitme[4] )[1] ) : '';

			if ( $acctScreen ) {
				$_POST['woocommerce_add_payment_method']          = sanitize_text_field( explode( '=', $splitme[6] )[1] );
				$_POST['payment_method']                          = sanitize_text_field( $this->id );
				$_REQUEST['woocommerce-add-payment-method-nonce'] = sanitize_text_field( explode( '=', $splitme[5] )[1] );
			}
		}

		// define redirect url.  pull from settings value or use default
		$order       = wc_get_order( $orderid );
		$APIKey      = $this->apikey;
		$token       = sanitize_text_field( $tokenid );
		$orderStatus = 'error';
		$newPmToken  = '';

		if ( ! empty( $action ) && $action == 'addbilling' ) {
			// addbilling action
			// check order status
			if ( $acctScreen || ( ! empty( $order ) && $order->get_status() != 'Completed' ) ) {
				$result = bng701_start_complete_order_process( $APIKey, $token );

				if ( $result['result'] == 1 ) {
					// pull values from response
					$billingid       = isset( $result['billing']['billing-id'] ) ? $result['billing']['billing-id'] : '';
					$customervaultid = isset( $result['customer-vault-id'] ) ? $result['customer-vault-id'] : '';
					$newPmToken      = bng701_create_woocommerce_payment_token( $billingid, $customervaultid, $this->apikey );

					// if acct screen dont do this just go to complete
					if ( ! $acctScreen || ( function_exists( 'wcs_is_subscription' ) && ! wcs_is_subscription( $orderid ) ) ) {
						// send order to gateway
						$body = '<' . $trans_type . '> 
                                    <api-key>' . $APIKey . '</api-key>
                                    <amount>' . $order->get_total() . '</amount>
                                    <customer-vault-id>' . $customervaultid . '</customer-vault-id>
                                    <order-id>' . $orderid . '</order-id>
                                    <billing>
                                        <billing-id>' . $billingid . '</billing-id>
                                    </billing>';

						$items = $order->get_items();
						foreach ( $items as $item ) {
							$body .= '<product>';
							$body .= '   <product-code>' . $item['product_id'] . '</product-code>';
							$body .= '   <description>' . urlencode( $item['name'] ) . '</description>';
							$body .= '   <commodity-code></commodity-code>';
							$body .= '   <unit-of-measure></unit-of-measure>';
							$body .= '   <unit-cost>' . round( $item['line_total'], 2 ) . '</unit-cost>';
							$body .= '   <quantity>' . round( $item['quantity'] ) . '</quantity>';
							$body .= '   <total-amount>' . round( $item['line_subtotal'], 2 ) . '</total-amount>';
							$body .= '   <tax-amount></tax-amount>';
							$body .= '   <tax-rate>1.00</tax-rate>';
							$body .= '   <discount-amount></discount-amount>';
							$body .= '   <discount-rate></discount-rate>';
							$body .= '   <tax-type></tax-type>';
							$body .= '   <alternate-tax-id></alternate-tax-id>';
							$body .= '</product>';
						}

						$body .= '</' . $trans_type . '>';

						$args = array(
							'headers' => array(
								'Content-type' => 'text/xml; charset="UTF-8"',
							),
							'body'    => $body,
						);

						// use wp function to handle curl calls
						$response = wp_remote_post( NMI_Config::$pluginUrl, $args );

						if ( is_wp_error( $response ) ) {
							wp_send_json_error( $response->get_error_message(), $response->get_error_code() );
						} else {
							$xml    = simplexml_load_string( $response['body'], 'SimpleXMLElement', LIBXML_NOCDATA );
							$json   = json_encode( $xml );
							$result = json_decode( $json, true );
						}
					}

					if ( $result['result'] == 1 ) {
						$orderStatus = 'success';
					}
				}
			}
		} else {
			// sale action
			// check order status
			if ( $order->get_status() != 'Completed' ) {
				$result = bng701_start_complete_order_process( $APIKey, $token );

				if ( $result['result'] == 1 ) {
					$orderStatus     = 'success';
					$trans_type      = isset( $result['action-type'] ) ? $result['action-type'] : '';
					$billingid       = isset( $result['billing']['billing-id'] ) ? $result['billing']['billing-id'] : '';
					$customervaultid = isset( $result['customer-vault-id'] ) ? $result['customer-vault-id'] : '';

					// check if woocommerce payment token was created
					$customerTokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id() );
					$donotcreate    = false;
					if ( empty( $customerTokens ) ) {
						// need to know that we are saving for a customer
						if ( empty( $customervaultid ) || empty( $billingid ) ) {
							$donotcreate = true;
						}
					} else {
						if ( empty( $billingid ) ) {
							$donotcreate = true;
						} else {
							foreach ( $customerTokens as $token ) {
								if ( $token->get_token() == $billingid ) {
									$donotcreate = true;
								}
							}
						}
					}
					if ( $donotcreate === false ) {
						$newPmToken = bng701_create_woocommerce_payment_token( $billingid, $customervaultid, $this->apikey );
					}
				}
			}
		}

		if ( class_exists( 'WC_Subscriptions' ) && ( NMI_Config::$projectType == 'bng' || ngfw_fs()->is_plan( 'Premium' ) ) ) {
			if ( $orderStatus == 'success' && ( wcs_order_contains_resubscribe( $order ) || wcs_order_contains_renewal( $order ) || wcs_order_contains_subscription( $order ) || wcs_is_subscription( $orderid ) ) ) {
				bng701_update_paymentmethod_subscription( $newPmToken, $order, $billingid );
			}
		}
		bng701_complete_order( $order, $orderStatus, $result, $trans_type, $this, $acctScreen );
	}

	/**
	 * Submits order using collect js payment token key
	 */
	function collectjs_to_direct_post_request() {
		$_POST['complete'] = 'true';

		$payment_token   = $_POST['bng_payment_token'];
		$order_id        = $_POST['order_id'];
		$paymentType     = $_POST['payment'];
		$hasVault        = $_POST['has_vault'];
		$transactionType = sanitize_text_field( $_POST['bng_transaction_type'] );
		if ( $paymentType == 'check' && $this->transactiontype == 'auth' ) {
			$transactionType = 'sale';
		}

		if ( isset( $_POST['woocommerce_add_payment_method'] ) ) {
			$acctScreen = true;
		} else {
			$acctScreen = false;
			$order      = wc_get_order( $order_id );
			$orderTotal = $order->get_total();
		}

		$query = $vaultId = $billingId = $savePm = $woo_token = '';

		if ( ! empty( $hasVault ) ) {
			if ( isset( $_POST['bng_save_paymentmethod'] ) ) {
				$savePm = $_POST['bng_save_paymentmethod'];
			}
			if ( isset( $_POST['bng_woo_token'] ) ) {
				$woo_token = $_POST['bng_woo_token'];
			}

			// if customer is using premium or on the bng side
			// has vault will not be empty
			if ( $hasVault == 'true' ) {
				// find customer vault and add new billing
				$paymentTokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id() );

				if ( ! empty( $woo_token ) ) {
					// use this when grabbing stored payment methods
					$oldPm     = $paymentTokens[ $woo_token ];
					$billingId = $oldPm->get_token();
					$vaultId   = $oldPm->get_meta( 'vaultid' );

					// means user is trying to change payment method
					if ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order_id ) && $orderTotal == 0 ) {
						bng701_update_paymentmethod_subscription( $oldPm, $order, $billingId );

						wp_safe_redirect( wc_get_endpoint_url( 'subscriptions', '', wc_get_page_permalink( 'myaccount' ) ) );
						exit();
					}

					$savePm = ''; // reset savePm
				} elseif ( $savePm == 'on' ) {
					// use this when saving new payment methods to a customer vault
					bng701_create_new_ids( $paymentTokens, $vaultId, $billingId );
					$query .= '&customer_vault=add_billing';
				}

				if ( empty( $vaultId ) && ! empty( $paymentTokens ) ) {
					$vaultId = current( $paymentTokens )->get_meta( 'vaultid' );
				}
			} elseif ( $hasVault == 'false' ) {
				// customer doesn't have a vault so create
				if ( $savePm == 'on' ) {
					$billingId = bng701_create_random_string();
					$vaultId   = bng701_create_random_string();
					$query    .= '&customer_vault=add_customer';
				}
			}
		}

		$query .= '&customer_vault_id=' . urlencode( $vaultId );
		$query .= '&billing_id=' . urlencode( $billingId );

		if ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order_id ) && $orderTotal == 0 ) {
			$query .= bng701_generic_collectjs_query( $order, $this->apikey, $paymentType, $orderTotal, $payment_token, false );
		} elseif ( $acctScreen === true ) {
			$query .= bng701_generic_collectjs_query( $order, $this->apikey, $paymentType, $orderTotal, $payment_token, false, true );
		} else {
			$query .= bng701_generic_collectjs_query( $order, $this->apikey, $paymentType, $orderTotal, $payment_token );
			$query .= '&type=' . $transactionType;
		}

		$responses = bng701_doCurl( $query );

		$orderStatus = 'error';
		if ( $responses['response'] == '1' ) {
			$orderStatus = 'success';
			// complete the order
			// add payment to woocommerce if customer asks to save
			$newPmToken = '';
			if ( $savePm == 'on' ) {
				$newPmToken = bng701_create_woocommerce_payment_token( $billingId, $vaultId, $this->apikey );
			}

			if ( class_exists( 'WC_Subscriptions' ) && ( NMI_Config::$projectType == 'bng' || ngfw_fs()->is_plan( 'Premium' ) ) ) {
				if ( $orderStatus == 'success' && ( wcs_order_contains_resubscribe( $order ) || wcs_order_contains_renewal( $order ) || wcs_order_contains_subscription( $order ) || wcs_is_subscription( $order_id ) ) ) {
					bng701_update_paymentmethod_subscription( $newPmToken, $order, $billingId );
				}
			}
		}

		$result = array(
			'result-code'        => $responses['response_code'],
			'result-text'        => $responses['responsetext'],
			'transaction-id'     => $responses['transactionid'],
			'authorization-code' => $responses['authcode'],
			'avs-result'         => $responses['avsresponse'],
			'cvv-result'         => $responses['cvvresponse'],

		);
		if ( $paymentType == 'check' ) {
			$result['sec-code'] = 'WEB';
		}

		bng701_complete_order( $order, $orderStatus, $result, $transactionType, $this, $acctScreen );
	}

	/**
	 * Handles scheduled subscription payments
	 *
	 * @param string|number $total - the $total amount to be paid on subscription
	 * @param object        $order - the schedules order to be submitted
	 */
	function scheduled_subscription_payment( $total, $order ) {
		$result = $this->process_subscription_payment( $order, $total );

		if ( is_wp_error( $result ) ) {
			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );

			foreach ( $subscriptions as $subscription ) {
				$subscription->payment_failed();
			}
		} else {
			$order->payment_complete( $result['transactionid'] );
		}
	}

	/**
	 * Processes subscription cancellation
	 *
	 * @param $object|WC_Order $order - the subscription
	 */
	function cancel_bng_subscription( $subscription ) {
		WC_Subscriptions_Manager::cancel_subscriptions_for_order( $subscription );
		bng701_remove_payment_meta_from_suscription( $subscription );
	}

	/**
	 * Updates the subscription meta on payment method change
	 * In order to keep track of newly created order when a
	 * subscription payment is processed for a renewal order or fails
	 *
	 * @param object|WC_Order $original_order - the parent order
	 * @param object|WC_Order $new_Renewal_order - the renewal order
	 */
	function subscription_payment_method_change( $original_order, $new_renewal_order ) {
		update_post_meta( $original_order->id, '_your_gateway_customer_token_id', get_post_meta( $new_renewal_order->id, '_your_gateway_customer_token_id', true ) );
	}

	/**
	 * Allows payment method change on a subscription to affect all subscription in an account
	 *
	 * @param bool                  $update - indicates whether to not update all or to update all
	 * @param array                 $new_payment_meta - the new payment meta
	 * @param bject|WC_Subscription $subscription - the WC_Subscription
	 *
	 * @return bool - false, to update all. Otherwise, true.
	 */
	function maybe_update_all_subscriptions_payment_method( $update, $new_payment_method, $subscription ) {
		if ( $this->id == $new_payment_method ) {
			$update = false;
		}

		return $update;
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param string $order_id - the order id
	 */
	function process_payment( $order_id ) {
		$order     	= wc_get_order( $order_id );
		$order_key 	= $order->get_order_key();

		$token_id 	= isset( $_POST['wc-nmi-payment-token'] ) ? wc_clean( $_POST['wc-nmi-payment-token'] ) : '';

		// Use NMI CURL API for payment
		try {
			$post_data = array();
			$payment_args = array();

			if( !$this->get_nmi_js_response() ) {

				// Check for CC details filled or not
				if ( empty( $_POST['woo-nmi-card-number'] ) || empty( $_POST['woo-nmi-card-expiry'] ) || empty( $_POST['woo-nmi-card-cvc'] ) ) {
					throw new Exception( __( 'Credit card details cannot be left incomplete.', 'wc-nmi' ) );
				}
			}

			if( $js_response = $this->get_nmi_js_response() ) {
				$post_data['payment_token'] = $js_response['token'];
			} else {
				$expiry = explode( ' / ', wc_clean( $_POST['woo-nmi-card-expiry'] ) );
				$expiry[1] = substr( $expiry[1], -2 );
				$post_data['ccnumber']	= wc_clean( $_POST['woo-nmi-card-number'] );
				$post_data['ccexp']		= $expiry[0] . $expiry[1];
				$post_data['cvv']		= wc_clean( $_POST['woo-nmi-card-cvc'] );
			}

			$description = sprintf( __( '%s - Order %s', 'wc-nmi' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number() );

			$description .= ' (' . $this->get_line_items( $order ) . ')';
			

			$payment_args = array(
				'orderid'	 		=> $order->get_order_number(),
				'order_description'	=> $description,
				'amount'			=> $order->get_total(),
				'transactionid'		=> $order->get_transaction_id(),
				'type'				=> $this->transactiontype,
				'first_name'		=> $order->get_billing_first_name(),
				'last_name'			=> $order->get_billing_last_name(),
				'address1'			=> $order->get_billing_address_1(),
				'address2'			=> $order->get_billing_address_2(),
				'city'				=> $order->get_billing_city(),
				'state'				=> $order->get_billing_state(),
				'country'			=> $order->get_billing_country(),
				'zip'				=> $order->get_billing_postcode(),
				'email' 			=> $order->get_billing_email(),
				'phone'				=> $order->get_billing_phone(),
				'company'			=> $order->get_billing_company(),
				'currency'			=> $this->get_payment_currency( $order_id ),
			);

			$payment_args = array_merge( $payment_args, $post_data );

			$payment_args = apply_filters( 'wc_nmi_request_args', $payment_args, $order );

			$response = $this->remote_request( $payment_args );

			if ( is_wp_error( $response ) ) {
				throw new Exception( $response->get_error_message() );
			}

			// Store charge ID
			$order->update_meta_data( '_nmi_charge_id', $response['transactionid'] );

			if ( $response['response'] == 1 ) {
				$order->set_transaction_id( $response['transactionid'] );

				if( $payment_args['type'] == 'sale' ) {

					// Store captured value
					$order->update_meta_data( '_nmi_charge_captured', 'yes' );
					$order->update_meta_data( 'NMI Payment ID', $response['transactionid'] );

					// Payment complete
					$order->payment_complete( $response['transactionid'] );

					// Add order note
					$complete_message = sprintf( __( 'NMI charge complete (Charge ID: %s)', 'wc-nmi' ), $response['transactionid'] );
					$order->add_order_note( $complete_message );

				} else {

					// Store captured value
					$order->update_meta_data( '_nmi_charge_captured', 'no' );

					if ( $order->has_status( array( 'pending', 'failed' ) ) ) {
						wc_reduce_stock_levels( $order_id );
					}

					// Mark as on-hold
					$authorized_message = sprintf( __( 'NMI charge authorized (Charge ID: %s). Process order to take payment, or cancel to remove the pre-authorization.', 'wc-nmi' ), $response['transactionid'] );
					$order->update_status( 'on-hold', $authorized_message );
				}

				$order->save();

			}

			// Remove cart
			WC()->cart->empty_cart();

			do_action( 'wc_gateway_nmi_process_payment', $response, $order );

			// Return thank you page redirect
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order )
			);

		} catch ( Exception $e ) {
			wc_add_notice( sprintf( __( 'Gateway Error: %s', 'wc-nmi' ), $e->getMessage() ), 'error' );
			WC_NMI_Logger::log( sprintf( __( 'Gateway Error: %s', 'wc-nmi' ), $e->getMessage() ) );

			do_action( 'wc_gateway_nmi_process_payment_error', $e, $order );

			$order->update_status( 'failed' );

			return array(
				'result'   => 'fail',
				'redirect' => ''
			);

		}
	}


	/**
	 * Get payment currency, either from current order or WC settings
	 *
	 * @return string three-letter currency code
	 */
	function get_payment_currency( $order_id = false ) {
		$currency = get_woocommerce_currency();
	   	$order_id = ! $order_id ? $this->get_checkout_pay_page_order_id() : $order_id;

		// Gets currency for the current order, that is about to be paid for
		if ( $order_id ) {
			$order    = wc_get_order( $order_id );
			$currency = $order->get_currency();
		}
		return $currency;
	}

	function remote_request( $args ) {

        $request_url = 'https://secure.networkmerchants.com/api/transact.php';

        $auth_params = array( 'security_key' => $this->apikey );

		$args['customer_receipt'] = isset( $args['customer_receipt'] ) ? $args['customer_receipt'] : true;
		$args['ipaddress'] = isset( $args['ipaddress'] ) ? $args['ipaddress'] : WC_Geolocation::get_ip_address();

        if( isset( $args['transactionid'] ) && empty( $args['transactionid'] ) ) {
            unset( $args['transactionid'] );
        }

        if( isset( $args['currency'] ) && empty( $args['currency'] ) ) {
            $args['currency'] = get_woocommerce_currency();
        }

        if( isset( $args['state'] ) && empty( $args['state'] ) && ! in_array( $args['type'], array( 'capture', 'void', 'refund' ) ) ) {
            $args['state'] = 'NA';
        }

        $args = array_merge( $args, $auth_params );

        // Setting custom timeout for the HTTP request
		add_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ), 9999 );

        //$headers = array( 'Content-Type' => 'application/json' );
        $headers = array();
        $response = wp_remote_post( $request_url, array( 'body' => $args , 'headers' => $headers ) );

		$result = is_wp_error( $response ) ? $response : wp_remote_retrieve_body( $response );

        // Saving to Log here
		if( $this->debug ) {
			$message = sprintf( "\nPosting to: \n%s\nRequest: \n%sResponse: \n%s", $request_url, print_r( $args, 1 ), print_r( $result, 1 ) );
			WC_NMI_Logger::log( $message );
		}

		remove_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ), 9999 );

		if ( is_wp_error( $result ) ) {
			return $result;
		} elseif( empty( $result ) ) {
			return new WP_Error( 'invalid_response', __( 'There was an error with the gateway response.', 'wc-nmi' ) );
		}

        parse_str( $result, $result );

        if( count( $result ) < 8 ) {
            return new WP_Error( 'invalid_response', sprintf( __( 'Unrecognized response from the gateway: %s', 'wc-nmi' ), $response ) );
        }

        if( !isset( $result['response'] ) || !in_array( $result['response'], array( 1, 2, 3 ) ) ) {
            return new WP_Error( 'invalid_response', __( 'There was an error with the gateway response.', 'wc-nmi' ) );
        }

        if( $result['response'] == 2 ) {
            return new WP_Error( 'decline_response', '<!-- Error: ' . $result['response_code'] . ' --> ' . __( 'Your card has been declined.', 'wc-nmi' ), $result );
		}

        if( $result['response'] == 3 ) {
            return new WP_Error( 'error_response', '<!-- Error: ' . $result['response_code'] . ' --> ' . $result['responsetext'], $result );
		}

        return $result;

	}

	/**
	 * HTTP timeout
	 */
	public function http_request_timeout( $timeout_value ) {
		return 45; // 45 seconds. Too much for production, only for testing.
	}

	function get_line_items( $order ) {
		$line_items = array();
		// order line items
		foreach ( $order->get_items() as $item ) {
			$line_items[] = $item->get_name() . ' x ' .$item->get_quantity();
		}
		return implode( ', ', $line_items );
	}

	/**
	 * Processes refunds
	 *
	 * @param string $orderid
	 * @param number $amount
	 * @param string $reason
	 */
	function process_refund( $order_id, $amount = null, $reason = '' ) {
		// Do your refund here. Refund $amount for the order with ID $order_id
		$order         = wc_get_order( $order_id );
		$total         = $order->get_total();
		$transaction   = bng701_getTransaction( $order->get_transaction_id(), $this->apikey );
		$transactionid = $order->get_transaction_id();
		$isrefunded    = 'N';
		$toVoid        = $transaction['condition'] == 'pendingsettlement';

		if ( $toVoid === true ) {
			// void order and not refund
			$body = '<void> 
                <api-key>' . $this->apikey . '</api-key>
                <transaction-id>' . $transactionid . '</transaction-id>
            </void>';
		} else {
			$body = '<refund> 
                <api-key>' . $this->apikey . '</api-key>
                <transaction-id>' . $transactionid . '</transaction-id>
                <amount>' . $amount . '</amount>
            </refund>';
		}

		$args = array(
			'headers' => array(
				'Content-type' => 'text/xml; charset="UTF-8"',
			),
			'body'    => $body,
		);

		// use wp function to handle curl calls
		$response = wp_remote_post( NMI_Config::$pluginUrl, $args );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message(), $response->get_error_code() );
		} else {
			$xml    = simplexml_load_string( $response['body'], 'SimpleXMLElement', LIBXML_NOCDATA );
			$json   = json_encode( $xml );
			$result = json_decode( $json, true );

			$result_id   = $result['result'];         // 1, 3
			$result_text = $result['result-text'];  // Transaction Void Successful, Only transactions pending settlement can be voided

			if ( $result_id == 1 ) {
				$isrefunded = 'Y';

				if ( $toVoid === true ) {
					$note = 'Transaction voided because it was pending settlement';
				} elseif ( $amount == $total ) {
					$note = 'This transaction was refunded in full';
				} else {
					$note = '$' . $amount . ' was refunded toward this transaction.';
				}

				$order->update_status( $result_text, $note );
			} else {
				$note = 'There was an error in posting the refund to this order: ' . $result_text;
			}
		}

		// Add helpful notes
		if ( ! empty( $reason ) ) {
			$note = "$note\n$reason";
		}
		$order->add_order_note( __( $note, NMI_Config::$pluginId ) );

		if ( $isrefunded == 'Y' ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Processes subscription payment for renewals, failures
	 * and resubscription
	 *
	 * @param object|WC_Order $order
	 * @param number          $amount_to_charge
	 */
	function process_subscription_payment( $order, $amount_to_charge ) {
		try {
			$billingId = $order->get_meta( '_nmi_gateway_billing_id' );
			$vaultId   = $order->get_meta( '_nmi_gateway_vault_id' );

			bng701_verify_nmi_payment_method_details( $billingId, $vaultId, $this->apikey );

			if ( empty( $cc_number ) ) {
				$paymentType = 'check';
			} else {
				$paymentType = 'creditcard';
			}

			$query  = bng701_generic_collectjs_query( $order, $this->apikey, $paymentType, $amount_to_charge );
			$query .= '&customer_vault_id=' . urlencode( $vaultId );
			$query .= '&billing_id=' . urlencode( $billingId );
			$query .= '&type=sale';

			$responses = bng701_doCurl( $query );

			if ( $responses['response'] != '1' ) {
				return new WP_Error( $responses['responsetext'] );
			}
			return $responses;
		} catch ( Exception $ex ) {
			WC_NMI_Logger::log( $ex->getMessage() );
			return new WP_Error( $ex->getMessage() );
		}
	}

	/**
	 * Output for the order received page.
	 */
	function thankyou() {
		echo $this->instructions != '' ? wpautop( $this->instructions ) : '';
	}

	/**
	 * Validates the payment method meta entry
	 * in the admin page or during subsription updates
	 *
	 * @param array $payment_meta
	 */
	function validate_admin_paymentmethod_change( $payment_meta ) {
		if ( ! isset( $payment_meta['post_meta']['_nmi_gateway_vault_id']['value'] ) || empty( $payment_meta['post_meta']['_nmi_gateway_vault_id']['value'] ) ) {
			WC_NMI_Logger::log( 'Customer vault id is required to update payment method' );
			throw new Exception( 'A customer vault identifier is required.' );
		}

		if ( ! isset( $payment_meta['post_meta']['_nmi_gateway_billing_id']['value'] ) || empty( $payment_meta['post_meta']['_nmi_gateway_billing_id']['value'] ) ) {
			WC_NMI_Logger::log( 'Billing id is required to update payment method' );
			throw new Exception( 'A billing identifier is required.' );
		}

		// if both values exists, then verify it exists in the nmi gateway
		$vaultId   = $payment_meta['post_meta']['_nmi_gateway_vault_id']['value'];
		$billingId = $payment_meta['post_meta']['_nmi_gateway_billing_id']['value'];

		if ( 'null' != $vaultId && 'null' != $billingId ) {
			try {
				bng701_verify_nmi_payment_method_details( $billingId, $vaultId, $this->apikey );
			} catch ( Exception $ex ) {
				WC_NMI_Logger::log( $ex->getMessage() );
				throw new Exception( __( $ex->getMessage(), NMI_Config::$pluginId ) );
			}
		}
	}

	/**
	 * Validates the options to only allow valid api keys
	 * If valid, plugin saves the options
	 */
	function validate_options() {
		$response = $this->validate_api_key( $_POST['woocommerce_nmi_gateway_apikey'] );
		$valid    = true;
		$error    = $msg = '';

		if ( isset( $response['customer_vault'] ) ) {
			// connection passed, process and save options
			if ( $_POST['woocommerce_nmi_gateway_paymenttype'] == 'ach' && $_POST['woocommerce_nmi_gateway_transactiontype'] == 'auth' ) {
				$valid = false;
				$error = 'Invalid selected options. Cannot use authorization only transaction type for Echeck payments';
			} elseif ( $_POST['woocommerce_nmi_gateway_paymenttype'] == 'both' && $_POST['woocommerce_nmi_gateway_transactiontype'] == 'auth' ) {
				$msg = 'Keep in mind, Credit Card payments will be authorized only. While, ECheck payments will be authorized and captured.';
			}
		} else {
			// connection failed - log, add and display error
			$valid = false;
			$error = $response['error_response'];
		}

		if ( $valid === true ) {
			if ( ! empty( $msg ) ) {
				WC_NMI_Logger::log( $msg );
				$this->add_error( $msg );
				$this->display_errors();
			}
			$this->process_admin_options();
		} else {
			WC_NMI_Logger::log( $error );
			$this->add_error( $error );
			$this->display_errors();
		}
	}

	/**
	 * Return whether or not this gateway still requires setup to function.
	 *
	 * @return bool
	 */
	public function needs_setup() {
		$response = $this->validate_api_key( $this->apikey );

		if ( isset( $response['customer_vault'] ) ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Helper to validate api key
	 *
	 * @param string $apikey - the api key
	 *
	 * @return array queryAPI response
	 */
	public function validate_api_key( $apikey ) {
		// Create body
		$body = [
			'keytext'      => $apikey,
			'report_type'  => 'customer_vault',
			'ver'          => 2,
			'result_limit' => 1,
		];

		return bng701_doQueryApi( $body );
	}
}

// shared methods
/**
 * This function adds the customized capture charge action to the order actions menu
 *
 * @param array $actions - predefined actions
 */
function bng701_add_order_capture_charge_action( $actions ) {
	global $theorder;

	$doNotAddCaptureAction = true;
	$trans_id              = $theorder->get_transaction_id();
	$apiKey                = bng701_get_apikey();

	$transaction = bng701_getTransaction( $trans_id, $apiKey );
	if ( isset( $transaction['condition'] ) ) {
		if ( $transaction['condition'] === 'pending' ) {
			$doNotAddCaptureAction = false;
		}
	}

	// check that the post meta for capture exists
	// or if transactionhas been captured in the user's NMI account
	if ( $doNotAddCaptureAction === true ) {
		if ( ! empty( get_post_meta( $theorder->get_id(), 'order_charge_captured', true ) ) ) {

			// capture processed in nmi
			$theorder->add_order_note( __( 'Authorization has been captured using NMI account', NMI_Config::$pluginId ) );

			if ( isset( $transaction['action'][1]['response_text'] ) ) {
				$result                       = array();
				$result['result-text']        = $transaction['action'][1]['response_text'];
				$result['result-code']        = $transaction['action'][1]['response_code'];
				$result['authorization-code'] = $transaction['authorization_code'];
				$result['transaction-id']     = $theorder->get_transaction_id();

				$note = bng701_get_order_completion_notes( 'success', $result );
				$theorder->add_order_note( __( $note, NMI_Config::$pluginId ) );
			}

			$theorder->payment_complete( $theorder->get_transaction_id() );

			// delete post meta to disallow further capturing
			delete_post_meta( $theorder->get_id(), 'order_charge_captured' );
		}
		return $actions;
	}

	// add custom order action to  capture charge
	$actions['capture_charge_action'] = __( 'Capture Charge', NMI_Config::$pluginId );
	return $actions;
}

/**
 * This function starts processing the nmi-three-step third process before finalizing the order
 *
 * @param string $apikey - the api key
 * @param string $token - the transaction token
 *
 * @return array
 */
function bng701_start_complete_order_process( $apikey, $token ) {
	// step 3 - Get cURL resource - Create body
	$body = '<complete-action> 
        <api-key>' . $apikey . '</api-key>
        <token-id>' . $token . '</token-id>
    </complete-action>';

	$args = array(
		'headers' => array(
			'Content-type' => 'text/xml; charset="UTF-8"',
		),
		'body'    => $body,
	);

	// use wp function to handle curl calls
	$response = wp_remote_post( NMI_Config::$pluginUrl, $args );

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( $response->get_error_message(), $response->get_error_code() );
	} else {
		$xml    = simplexml_load_string( $response['body'], 'SimpleXMLElement', LIBXML_NOCDATA );
		$json   = json_encode( $xml );
		$result = json_decode( $json, true );
	}
	return $result;
}

/**
 * This function finalizes a transaction
 * Its a generic function used whenever a transaction is processed
 *
 * @param object $order - the order object
 * @param object $status - the status of the post call i.e success or failure
 * @param array  $result - the gateway response after post calls
 * @param string $trans_type - the transaction type
 * @param object $bngGatewayObj - the plugin object
 * @param bool   $isAccount - indicates whether user is in the account management page or not. default is false.
 */
function bng701_complete_order( $order, $status, $result, $trans_type, $bngGatewayObj, $isAcctScreen = false ) {
	global $woocommerce;

	$oncomplete_action = $bngGatewayObj->finalorderstatus;

	if ( $status == 'success' ) {
		if ( $isAcctScreen ) {
			$_POST['saved_new_pm'] = true;
		} else {
			if ( function_exists( 'wcs_is_subscription' ) && ! wcs_is_subscription( $order ) || ! function_exists( 'wcs_is_subscription' ) ) {
				// add meta key to signify capture if transaction type is auth
				if ( $trans_type == 'auth' ) {
					add_post_meta( $order->get_id(), 'order_charge_captured', true, true );
					$order->add_order_note( __( 'NMI Gateway payment authorization completed.', NMI_Config::$pluginId ) );
					$order->set_transaction_id( $result['transaction-id'] );
					$order->save();
				} else {
					$order->add_order_note( __( 'NMI Gateway payment completed.', NMI_Config::$pluginId ) );
					$order->payment_complete( $result['transaction-id'] ); // Mark as paid/payment complete
				}

				// Add helpful notes
				$note = bng701_get_order_completion_notes( 'success', $result );
				$order->add_order_note( __( $note, NMI_Config::$pluginId ) );

				// Empty cart
				$woocommerce->cart->empty_cart();

				// only flag as completed if the settings tell us to do so
				if ( $oncomplete_action == 'Completed' ) {
					// flag the order as completed in the eyes of woo
					$order->update_status( 'completed', 'Successful payment by the NMI Three Step Gateway' );
				} elseif ( $oncomplete_action == 'Ready to Ship' ) {
					// flag the order as completed in the eyes of woo
					$order->update_status( 'ready-to-ship', 'Successful payment by the NMI Three Step Gateway' );
				}

				if ( $trans_type == 'auth' ) {
					$order->update_status( 'on-hold' );
					$order->add_order_note( __( 'Awaiting payment capture.', NMI_Config::$pluginId ) );
				}

				// if woocommerce_thankyou exists and the settings checkbox is checked, run it
				if ( has_action( 'woocommerce_thankyou' ) ) {
					do_action( 'woocommerce_thankyou', $order->get_id() );
				}

				if ( isset( $result['sec-code'] ) ) {
					$order->add_order_note( __( 'This transaction was paid using ECheck. Delay shipping until payment clears.', NMI_Config::$pluginId ) );
				}

				// display confirmation message
				if ( function_exists( 'wc_add_notice' ) ) {
					wc_add_notice( __( 'Your order is complete! Thank you!', NMI_Config::$pluginId ), $status );
				}
			}
		}
	} else {
		$dsp_error = $result['result-text'];
		// throw error notice and do not complete order
		// Add helpful notes
		if ( $isAcctScreen ) {
			$_POST['saved_new_pm'] = false;
		} elseif ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order ) ) {
			$order->add_order_note( __( 'Failure: Payment method could not be changed.', NMI_Config::$pluginId ) );
			$order->add_order_note( __( "Reason - {$dsp_error}", NMI_Config::$pluginId ) );

			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( __( "Failure: Payment method could not be changed on subscription {$order->get_id()}.", NMI_Config::$pluginId ), $status );
			}
		} else {
			// Add helpful notes
			$note = bng701_get_order_completion_notes( 'error', $result );
			$order->add_order_note( __( $note, NMI_Config::$pluginId ) );

			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( __( 'An error occurred completing order: ' . $dsp_error, NMI_Config::$pluginId ), $status );
			}
			$order->update_status( 'failed', $dsp_error );
		}
	}

	// redirection
	if ( $isAcctScreen ) {
		if ( isset( $_GET['complete'] ) ) {
			WC_Form_Handler::add_payment_method_action();
		}
	} elseif ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order ) ) {
		wp_safe_redirect( wc_get_endpoint_url( 'subscriptions', '', wc_get_page_permalink( 'myaccount' ) ) );
		exit();
	} else {
		$redirect_url = $bngGatewayObj->redirecturl;
		if ( $redirect_url == '' ) {
			$redirect_url = $order->get_checkout_order_received_url();
		}

		wp_safe_redirect( $redirect_url );
		exit();
	}
}

/**
 * This function processes the capture charge action
 *
 * @param object $order - the order object
 */
function bng701_process_capture_charge_action( $order ) {
	$apiKey = bng701_get_apikey();
	$query  = 'security_key=' . urlencode( $apiKey );
	// Transaction Information
	$query .= '&transactionid=' . urlencode( $order->get_transaction_id() );
	$query .= '&amount=' . urlencode( $order->get_total() );
	$query .= '&orderid=' . urlencode( $order->get_id() );
	$query .= '&type=capture';

	$responses = bng701_doCurl( $query );
	$result    = array(
		'result-code'        => $responses['response_code'],
		'result-text'        => $responses['responsetext'],
		'transaction-id'     => $responses['transactionid'],
		'authorization-code' => $responses['authcode'],
		'avs-result'         => $responses['avsresponse'],
		'cvv-result'         => $responses['cvvresponse'],
	);

	if ( $responses['response'] == '1' ) {
		// capture passed
		// if capture is successful go ahead and update this
		$order->add_order_note( __( 'Authorization capture successful', NMI_Config::$pluginId ) );
		$note = bng701_get_order_completion_notes( 'success', $result );
		$order->add_order_note( __( $note, NMI_Config::$pluginId ) );
		$order->payment_complete( $order->get_transaction_id() );

		// delete post meta to disallow further capturing
		delete_post_meta( $order->get_id(), 'order_charge_captured' );
	} else {
		// capture failed
		$dsp_error = $responses['responsetext'];
		$order->add_order_note( __( 'Authorization capture failed', NMI_Config::$pluginId ) );
		$order->add_order_note( __( $dsp_error, NMI_Config::$pluginId ) );

		$note = bng701_get_order_completion_notes( 'error', $result );
		$order->add_order_note( __( $note, NMI_Config::$pluginId ) );
	}
}

/**
 * Generic function to build html for payment methods
 *
 * @param array  $data
 * @param string $tokenizationkey
 * @param string $order_id
 * @param array  $paymentMethods
 * @param string $savepaymentmethodtoggle
 *
 * @return void
 */
function bng701_html( $savepaymentmethodtoggle, $tokenizationkey = '', $data = '', $order_id = '', $paymentMethods = '' ) {
	$pluginPath         = NMI_WOO_PLUGIN_URL;
	$useTokenizationKey = $showSavePmLater = $forceSavePm = false;
	$hasVault           = '';
	$pageMessage        = 'Pay via the NMI Payment Gateway';

	if ( NMI_Config::$projectType == 'bng' || ngfw_fs()->is_plan( 'Premium' ) ) {
		// to display save payment method checkbox
		if ( is_user_logged_in() && $savepaymentmethodtoggle == 'on' ) {
			$showSavePmLater = true;
			if ( empty( $order_id ) ) {
				$forceSavePm = true;
			}
		}

		// if order number is a subscription number type, force payment method save
		if ( ( is_user_logged_in() && $savepaymentmethodtoggle == 'on' && class_exists( 'WC_Subscriptions' ) ) &&
			( wcs_order_contains_resubscribe( $order_id ) || wcs_order_contains_renewal( $order_id ) || wcs_order_contains_subscription( $order_id ) || wcs_is_subscription( $order_id ) ) ) {
			$forceSavePm = true;
		}

		if ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order_id ) ) {
			$pageMessage = 'Select Desired Payment Method via the NMI Payment Gateway';
		}

		// find out if customer has nmi vault or not
		$wc_tokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id() );
		if ( ! empty( $wc_tokens ) ) {
			$hasVault = 'true';
		} else {
			$hasVault = 'false';
		}
	}

	?>
		<h3 class='bng_acct_screen'><?php echo $pageMessage; ?></h3>
		<form name="bng_submitPayment" id="bng_submitPayment" action="" method="POST">
			<div class="savedPms">
				<h4>Saved Payment Methods</h4>
				<ul class="paymentMethods" id="paymentMethods">
				</ul>    
			</div>

			<h4 class="savedPms">New Payment Method</h4>
			<h4 class='bng_acct_screen'>Please Enter Your Payment Information Below</h4>

			<div id="timeoutdsp" class="woocommerce" style="display:none;">
				<ul class="woocommerce-info" style="list-style:none;">
					<li>Your checkout has been sitting still for a while.  Please submit your payment or we\'ll take you back to your cart contents in a few minutes.</li>
				</ul>
			</div>      
			
			<div class="bng-form-group active">
				<div class="preferred_payment_method">
					<p>Please select your preferred payment method:</p>
					<div class='bng_checkboxes'>
						<input type="radio" id="cc" name="paymentType" checked>
						<label for="cc" >Credit Card</label>
					</div>
					<div class='bng_checkboxes'>
						<input type="radio" id="ach" name="paymentType" class="display_ach">
						<label for="ach">Electronic Check</label>
					</div>

					<input type="hidden" id="payment" name="payment" value="">
				</div>          
	<?php

	if ( $tokenizationkey ) {
		$useTokenizationKey = true;
		?>
			<script 
				src = "https://secure.networkmerchants.com/token/Collect.js"
				data-tokenization-key = "<?php echo $tokenizationkey; ?>"
			>
			</script>

				<div>
					<input type="hidden" name="order_id" value="<?php echo $order_id; ?>" />
					<input type="hidden" name="has_vault" id="has_vault" value="<?php echo $hasVault; ?>" />
					<input type="hidden" name="bng_payment_token" id="bng_payment_token" value="" />
					<input type="hidden" name="bng_woo_token" id="bng_woo_token" value="" />
					<input type="hidden" name="bng_transaction_type" id="bng_transaction_type" value="" />
					<input type="hidden" name="payment_type" id="payment_type" value="" />
				</div>

				<div class="row savePmLater" >
					<input type="checkbox" name="bng_save_paymentmethod" id="bng_savePaymentMethod" /> 
					<label for="bng_savePaymentMethod"> Save this payment method for later? </label>
				</div>
			</div>
			<br clear="all">

			<button id="bng_payButton" type="button" class='detailsDiv' onclick="submitOrderUsingOldPaymentMethod()">Pay for Order</button>
			
		<?php
	} else {
		?>
				<div class="cc_row">
					<label class="cc_label cc_type bngg_required">Credit Card Number</label>
					<input class="col cc_type" type ="text" name="billing-cc-number" id="billingCcNumber" >

					<label class="cc_label ach_type bngg_required">Name on Check</label>
					<input class="col ach_type" type ="text" name="billing-account-name" id="billingAccountName" >
				</div>
				
				<div class="cc_row">
					<label class="cc_label cc_type bngg_required">Expiration Date</label>
					<input class="cc_exp col cc_type" type="month" format="MMYYYY" name="billing-cc-year-month" id="billingdate" />
					<input class="cc_type" type="hidden" name="billing-cc-exp" id="billingccexp" value="">                        
					
					<label class="cc_label ach_type bngg_required">Account Number</label>
					<input class="col ach_type" type="text" name="billing-account-number" id="billingAccountNumber"  />
				</div>
				
				<div class="cc_row ach_type_extra">
					<label class="cc_label ach_type bngg_required">Re-Enter Account Number</label>
					<input class="col ach_type" type="text" name="reenter-billing-account-number" id="reenterBillingAccountNumber"  />
				</div>
				
				<div class="cc_row">
					<label class="bng_acct_screen cc_label cc_type">CVV</label>
					<input class="bng_acct_screen col cc_type" type="text" name="billing-cvv" id="billingcvv"  />
					
					<label class="cc_label ach_type bngg_required">Routing Number</label>
					<input class="col ach_type" type="text" name="billing-routing-number" id="billingRoutingNumber" >
				</div>
				
				<div class="cc_row ach_type_extra">
					<label class="cc_label bngg_required">Check Account Type</label>
					<select class="cc_exp col" name="billing-account-type" id="billingAccountType" >
						<option value="checking">Checking</option>
						<option value="savings">Savings</option>
					</select>
				</div>
				
				<div class="cc_row ach_type_extra">
					<label class="cc_label bngg_required">Check Entity Type</label>
					<select class="cc_exp col" name="billing-entity-type" id="billingEntityType" >
						<option value="personal">Personal</option>
						<option value="business">Business</option>
					</select>
				</div>

				<div class="row savePmLater">
					<input type="checkbox" name="bng_save_paymentmethod" id="bng_savePaymentMethod" /> 
					<label for="bng_savePaymentMethod"> Save this payment method for later? </label>
				</div>
			</div>

			<div class='bng_buttons'>
				<input type ='button' id='bng_backButton' value='Back to Cart' class='detailsDiv'
					onclick='bng701_backToCheckout();' >
				<input type='button' id='bng_submitButton' value='Submit' class='detailsDiv submit' 
					onclick='bng701_cc_validate();'>
			</div>
		<?php
	}

	?>
			<img src='<?php echo plugins_url( 'img/spinner.gif', __FILE__ ); ?>' id='bng_spinner' class='bng_spinner'>
		</form>

		<script type="text/javascript"> 
			storedVariables.init({
				"PluginPath": "<?php echo $pluginPath; ?>",
				"CustomerHasVault": "<?php echo $hasVault; ?>",
				"AllowSavePayment": "<?php echo (bool) $showSavePmLater; ?>",
				"ForceSavePayment": "<?php echo (bool) $forceSavePm; ?>",
				"CartUrl": "<?php echo get_site_url() . '/cart/'; ?>",
				"UseTokenization": "<?php echo (bool) $useTokenizationKey; ?>",
				"ReceiptData": <?php echo json_encode( $data ); ?>,
				"PaymentMethods": <?php echo json_encode( $paymentMethods ); ?>
			} );       

			// populate the saved methods
			populateStoredPaymentMethods();
			if (!storedVariables.useTokenization()) populateMinExpiration();
			
			jQuery( '.bng-form-group' ).on("click", function(event) {
				event.preventDefault(); 
				bng701_toggleState(event.currentTarget);
			});

			jQuery('#bng_savePaymentMethod').on('click', function(event) {
				event.stopPropagation();
				if (storedVariables.forceSavePayment()) event.target.checked = true;
			});

			jQuery('#billingdate').on('click', function(event) {
				event.stopPropagation();
			});

			jQuery('label[for="bng_savePaymentMethod"]').on('click', function(event) {
				event.stopPropagation();
			});

			jQuery('#cc').on( 'click', (event) => display_cc_elements(event) );
			
			jQuery('label[for="cc"]').on('click', (event) => display_cc_elements(event) );

			jQuery('#ach').on( 'click', (event) => display_check_elements(event) );

			jQuery('label[for="ach"]').on('click', (event) => display_check_elements(event) );
			
			populatePaymentMethods();
		</script>
	<?php
}

/**
 * Specifies the credit card type from the details retrieved from the gateway
 *
 * @param string $type - the credit card type in gateway
 *
 * @return string
 */
function bng701_getCardType( $type ) {
	if ( stristr( $type, 'visa' ) ) {
		return 'visa';
	} elseif ( stristr( $type, 'american' ) ) {
		return 'amex';
	} elseif ( stristr( $type, 'discover' ) ) {
		return 'discover';
	} elseif ( stristr( $type, 'master' ) ) {
		return 'mastercard';
	} elseif ( stristr( $type, "diner's" ) ) {
		return 'diner';
	} elseif ( stristr( $type, 'jcb' ) ) {
		return 'jcb';
	} elseif ( stristr( $type, 'maestro' ) ) {
		return 'maestro';
	} else {
		return 'blank';
	}
}

/**
 * Returns a description of the rsult code received from nmi
 *
 * @param string $code - the result code
 * @return string code description
 */
function bng701_getResultCodeText( $code ) {
	// definitions
	$codes = array(
		1       => 'Transaction was approved',
		100     => 'Transaction was approved',
		200     => 'Transaction was delined by processor',
		201     => 'Do not honor',
		202     => 'Insufficient funds',
		203     => 'Over limit',
		204     => 'Transaction not allowed',
		220     => 'Incorrect payment information',
		221     => 'No such card issuer',
		222     => 'No card number on file with issuer',
		223     => 'Expired card',
		224     => 'Invalid expiration date',
		225     => 'Invalid card security code',
		240     => 'Call issuer for further information',
		250     => 'Pick up card',
		251     => 'Lost card',
		252     => 'Stolen card',
		253     => 'Fraudulent Card',
		260     => 'Declined with further instructions available',
		261     => 'Declined-Stop all recurring payments',
		262     => 'Declined-Stop this recurring program',
		263     => 'Declined-Update cardholder data available',
		264     => 'Declined-Retry in a few days',
		300     => 'Transaction was rejected by gateway',
		400     => 'Transaction error returned by processor',
		410     => 'Invalid merchant configuration',
		411     => 'Merchant account is inactive',
		420     => 'Communication error',
		421     => 'Communication error with issuer',
		430     => 'Duplicate transaction at processor',
		440     => 'Processor format error',
		441     => 'Invalid transaction information',
		460     => 'Processor feature not available',
		461     => 'Unsupported card type',

		// CVV Response Codes
		'CVV-M' => 'CVV2/CVC2 match',
		'CVV-N' => 'CVV2/CVC2 no match',
		'CVV-P' => 'Not processed',
		'CVV-S' => 'Merchant has indicated that CVV2/CVC2 is not present on card',
		'CVV-U' => 'Issuer is not certified and/or has not provided Visa encryption keys',

		// AVS Response Codes
		'AVS-X' => 'Exact match, 9-character numeric ZIP',
		'AVS-Y' => 'Exact match, 5-character numeric ZIP',
		'AVS-D' => 'Exact match, 5-character numeric ZIP',
		'AVS-M' => 'Exact match, 5-character numeric ZIP',
		'AVS-2' => 'Exact match, 5-character numeric ZIP, customer name',
		'AVS-6' => 'Exact match, 5-character numeric ZIP, customer name',
		'AVS-A' => 'Address match only',
		'AVS-B' => 'Address match only',
		'AVS-3' => 'Address, customer name match only',
		'AVS-7' => 'Address, customer name match only',
		'AVS-W' => '9-character numeric ZIP match only',
		'AVS-Z' => '5-character ZIP match only',
		'AVS-P' => '5-character ZIP match only',
		'AVS-L' => '5-character ZIP match only',
		'AVS-1' => '5-character ZIP, customer name match only',
		'AVS-5' => '5-character ZIP, customer name match only',
		'AVS-N' => 'No address or ZIP match only',
		'AVS-C' => 'No address or ZIP match only',
		'AVS-4' => 'No address or ZIP or customer name match only',
		'AVS-8' => 'No address or ZIP or customer name match only',
		'AVS-U' => 'Address unavailable',
		'AVS-G' => 'Non-U.S. issuer does not participate',
		'AVS-I' => 'Non-U.S. issuer does not participate',
		'AVS-R' => 'Issuer system unavailable',
		'AVS-E' => 'Not a mail/phone order',
		'AVS-S' => 'Service not supported',
		'AVS-0' => 'AVS not available',
		'AVS-O' => 'AVS not available',
		'AVS-B' => 'AVS not available',
	);

	return in_array( $code, array_keys( $codes ) ) ? $codes[ $code ] : '';
}

function bng701_cleanTheData( $data, $datatype = 'none' ) {
	// per WP's requirements, we need to sanitze, validate and escape data passed in from the form.  I'm attempting to do that in one function
	// $data is the value to clean, $datatype (if defined) is the expected data type of $data
	// based on the datatype, we'll run some extra validation as well.  in the end, we'll return either the scrubbed data value or a null value if it doesn't comply

	// validate data
	switch ( $datatype ) {
		case 'string':
			if ( gettype( $data ) != 'string' ) {
				$data = '';
			}
			$data = sanitize_text_field( $data );
			break;
		case 'int':
			if ( gettype( $data ) != 'integer' ) {
				$data = '0';
			}
			$data = sanitize_text_field( $data );
			break;
		case 'url':
			if ( filter_var( $data, FILTER_VALIDATE_URL ) == false ) {
				$data = '';
			}
			$data = sanitize_text_field( $data );
			break;
		case 'email':
			if ( filter_var( $data, FILTER_VALIDATE_EMAIL ) === false ) {
				$data = '';
			}
			$data = sanitize_email( $data );
			break;
	}

	// sanitize data
	if ( $data != '' ) {
		$data = esc_html( htmlspecialchars( $data ) );
	}

	return $data;
}

function bng701_pw_load_scripts() {
	wp_enqueue_script( 'my_script', NMI_WOO_PLUGIN_URL . 'js/my_script.js', array( 'jquery' ) );
	wp_localize_script( 'my_script', 'frontendajax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );
	wp_enqueue_script( 'backThatUp', NMI_WOO_PLUGIN_URL . 'js/backToCheckout.js' );
	wp_enqueue_style( 'my_styles', NMI_WOO_PLUGIN_URL . 'css/my_styles.css' );

	wp_enqueue_script( 'bng701_ajax_custom_script', NMI_WOO_PLUGIN_URL . 'js/stepOne.js', array( 'jquery' ) );
	wp_localize_script( 'bng701_ajax_custom_script', 'frontendajax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );
	wp_enqueue_script( 'bng701_ajax_custom_script1', NMI_WOO_PLUGIN_URL . 'js/deletePaymentMethod.js', array( 'jquery' ) );
	wp_localize_script( 'bng701_ajax_custom_script1', 'frontendajax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );
}

// region Javascript Ajax Functions

/**
 * Processes step one for the nmi-three-step.
 * Specifically adds a new billing method to an existing customer
 */
function bng701_stepOne_addBilling() {
	$data     = $_POST['data'];
	$security = sanitize_text_field( $data['security'] );
	check_ajax_referer( 'checkout-nonce', $security, false );

	// catch variables passed in and define them
	$pluginId                             = NMI_Config::$pluginId;
	$apikey                               = bng701_get_apikey();
	$orderid                              = sanitize_text_field( $data['orderid'] );
	$isAcctScreen                         = $data['isAcctScreen'];
	$woocommerce_add_payment_method_nonce = $data['woocommerce-add-payment-method-nonce'];
	$woocommerce_add_payment_method       = $data['woocommerce_add_payment_method'];
	$transactiontype                      = sanitize_text_field( $data['transactiontype'] );
	$paymentType                          = sanitize_text_field( $data['paymentType'] );
	$billingfirstname                     = sanitize_text_field( $data['billingfirstname'] );
	$billinglastname                      = sanitize_text_field( $data['billinglastname'] );
	$billingaddress1                      = sanitize_text_field( $data['billingaddress1'] );
	$billingcity                          = sanitize_text_field( $data['billingcity'] );
	$billingstate                         = sanitize_text_field( $data['billingstate'] );
	$billingpostcode                      = sanitize_text_field( $data['billingpostcode'] );
	$billingcountry                       = sanitize_text_field( $data['billingcountry'] );
	$billingemail                         = sanitize_email( $data['billingemail'] );
	$billingcompany                       = sanitize_text_field( $data['billingcompany'] );

	if ( $paymentType == 'check' && $transactiontype == 'auth' ) {
		$transactiontype = 'sale';
	}

	$referrer     = explode( '?', $_SERVER['HTTP_REFERER'] );
	$thisreferrer = $referrer[0];

	if ( ! empty( $isAcctScreen ) ) {
		$thisreferrer .= "?order={$orderid}***action=addbilling***plugin={$pluginId}***type={$transactiontype}***isAcctScreen={$isAcctScreen}";
		$thisreferrer .= "***woocommerce_add_payment_method_nonce={$woocommerce_add_payment_method_nonce}***woocommerce_add_payment_method={$woocommerce_add_payment_method}";
	} else {
		$thisreferrer .= "?order={$orderid}***action=addbilling***plugin={$pluginId}***type={$transactiontype}";
	}

	// get the saved payment tokens from WC
	$payment_tokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id() );
	if ( ! empty( $payment_tokens ) ) {
		$customerVaultId = $billingId = '';
		bng701_create_new_ids( $payment_tokens, $customerVaultId, $billingId );
	} else {
		wp_send_json_error( 'To add new payment method, customer should have previous payment methods', 400 );
	}

	$body = '<add-billing>
                <api-key>' . $apikey . '</api-key>
                <redirect-url>' . $thisreferrer . '</redirect-url>
                <customer-vault-id>' . $customerVaultId . '</customer-vault-id>
                <billing>
                    <billing-id>' . $billingId . '</billing-id>
                    <email>' . $billingemail . '</email>
                    <first-name>' . $billingfirstname . '</first-name>
                    <last-name>' . $billinglastname . '</last-name>
                    <address1>' . $billingaddress1 . '</address1>
                    <city>' . $billingcity . '</city>
                    <state>' . $billingstate . '</state>
                    <postal>' . $billingpostcode . '</postal>
                    <country>' . $billingcountry . '</country>
                    <company>' . $billingcompany . '</company>
                    <address2 />
                </billing>
            </add-billing>';

	$args = array(
		'headers' => array(
			'Content-type' => 'text/xml; charset="UTF-8"',
		),
		'body'    => $body,
	);

	$response = wp_remote_post( NMI_Config::$pluginUrl, $args );

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( $response->get_error_message(), $response->get_error_code() );
	} else {
		$xml           = simplexml_load_string( $response['body'], 'SimpleXMLElement', LIBXML_NOCDATA );
		$json          = json_encode( $xml );
		$full_response = json_decode( $json, true );

		if ( isset( $full_response['form-url'] ) ) {
			// if successful, submit order thru the direct post now that we have the billing id/token
			echo $full_response['form-url'] . '--||--' . $billingId;
			wp_die();
		} else {
			wp_send_json_error( $full_response['result-text'], 400 );
		}
	}
}

/**
 * Processes nmi-three-step first step
 */
function bng701_stepOne() {
	$data     = $_POST['data'];
	$pluginId = NMI_Config::$pluginId;
	$apikey   = bng701_get_apikey();
	$security = sanitize_text_field( $data['security'] );

	check_ajax_referer( 'checkout-nonce', $security, false );

	// needed to add new payment methods on account management page
	$isAcctScreen                         = $data['isAcctScreen'];
	$woocommerce_add_payment_method_nonce = $data['woocommerce-add-payment-method-nonce'];
	$woocommerce_add_payment_method       = $data['woocommerce_add_payment_method'];

	$orderid           = sanitize_text_field( $data['orderid'] );
	$tokenId           = sanitize_text_field( $data['tokenid'] );
	$transactiontype   = sanitize_text_field( $data['transactiontype'] );
	$paymentType       = sanitize_text_field( $data['paymentType'] );
	$ordertotal        = sanitize_text_field( $data['ordertotal'] );
	$savepaymentmethod = sanitize_text_field( $data['savepaymentmethod'] );
	$userid            = get_current_user_id();

	// referrer url issue - bng can't accept a url with a query string in it (http://someurl.com/checkout/?key=wc_order_996889&order=555)
	$referrer = explode( '?', $_SERVER['HTTP_REFERER'] );
	$referrer = $referrer[0];

	if ( empty( $isAcctScreen ) ) {
		$referrer .= '?order=' . $orderid;
	} else {
		$referrer .= "?order={$orderid}***action=***plugin={$pluginId}***type={$transactiontype}***isAcctScreen={$isAcctScreen}";
		$referrer .= "***woocommerce_add_payment_method_nonce={$woocommerce_add_payment_method_nonce}***woocommerce_add_payment_method={$woocommerce_add_payment_method}";
	}

	if ( $paymentType == 'check' && $transactiontype == 'auth' ) {
		$transactiontype = 'sale';
	}
	$payment_tokens  = WC_Payment_Tokens::get_customer_tokens( $userid );
	$customervaultid = $billingid = '';

	if ( ! empty( $tokenId ) ) {
		// implies user selected a previously existing payment method (billing id)
		$billingid       = $payment_tokens[ $tokenId ]->get_token();
		$customervaultid = $payment_tokens[ $tokenId ]->get_meta( 'vaultid' );

		// means the customer wants to change the payment method used in subscription
		if ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $orderid ) && $ordertotal == 0 ) {
			bng701_update_paymentmethod_subscription( '', wc_get_order( $orderid ), $billingid );

			echo wc_get_endpoint_url( 'subscriptions', '', wc_get_page_permalink( 'myaccount' ) );
			wp_die();
		} else {
			// Create body
			$body  = '<' . $transactiontype . '>';
			$body .= bng701_generic_threestep_body( $apikey, $referrer, $customervaultid, $billingid );
			$body .= '</' . $transactiontype . '>';

			$args = array(
				'headers' => array(
					'Content-type' => 'text/xml; charset="UTF-8"',
				),
				'body'    => $body,
			);

			// use wp function to handle curl calls
			$response = wp_remote_post( NMI_Config::$pluginUrl, $args );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( $response->get_error_message(), $response->get_error_code() );
			} else {
				$xml           = simplexml_load_string( $response['body'], 'SimpleXMLElement', LIBXML_NOCDATA );
				$json          = json_encode( $xml );
				$full_response = json_decode( $json, true );

				$formURL = $full_response['form-url'];
				$rc      = $full_response['result'];
				$tid     = $full_response['transaction-id'];
				$ac      = $full_response['authorization-code'];
				$ar      = $full_response['avs-result'];

				if ( $full_response['result'] == '1' ) {
					echo $formURL . '||' . $rc . '||' . $tid . '||' . $ac . '||' . $ar;
				} else {
					wp_send_json_error( $full_response['result-text'], 400 );
				}
			}
		}
	} elseif ( $savepaymentmethod == 'Y' ) {
		bng701_create_new_ids( $payment_tokens, $customervaultid, $billingid );

		if ( empty( $customervaultid ) ) {
			$customervaultid = bng701_create_random_string();

			if ( ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $orderid ) ) || ! empty( $isAcctScreen ) ) {
				$body  = '<add-customer>';
				$body .= bng701_generic_threestep_body( $apikey, $referrer, $customervaultid, $billingid, false );
				$body .= '</add-customer>';
			} else {
				$body  = '<' . $transactiontype . '>';
				$body .= bng701_generic_threestep_body( $apikey, $referrer, $customervaultid, $billingid, true, false );
				$body .= '<add-customer>
                                <customer-vault-id>' . $customervaultid . '</customer-vault-id>
                            </add-customer>';
				$body .= '</' . $transactiontype . '>';
			}
		} else {
			bng701_stepOne_addBilling();
		}

		$args = array(
			'headers' => array(
				'Content-type' => 'text/xml; charset="UTF-8"',
			),
			'body'    => $body,
		);

		// use wp function to handle curl calls
		$response = wp_remote_post( NMI_Config::$pluginUrl, $args );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message(), $response->get_error_code() );
		} else {
			$xml           = simplexml_load_string( $response['body'], 'SimpleXMLElement', LIBXML_NOCDATA );
			$json          = json_encode( $xml );
			$full_response = json_decode( $json, true );

			if ( isset( $full_response['form-url'] ) ) {
				echo $full_response['form-url'];
			} else {
				wp_send_json_error( $full_response['result-text'], 400 );
			}
		}
	} else {
		// implies one time sale, do not save the payment method for later
		// Create body
		$body  = '<' . $transactiontype . '>';
		$body .= bng701_generic_threestep_body( $apikey, $referrer, $customervaultid, $billingid, true, false, false );
		$body .= '</' . $transactiontype . '>';

		$args = array(
			'headers' => array(
				'Content-type' => 'text/xml; charset="UTF-8"',
			),
			'body'    => $body,
		);

		// use wp function to handle curl calls
		$response = wp_remote_post( NMI_Config::$pluginUrl, $args );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message(), $response->get_error_code() );
		} else {
			$xml           = simplexml_load_string( $response['body'], 'SimpleXMLElement', LIBXML_NOCDATA );
			$json          = json_encode( $xml );
			$full_response = json_decode( $json, true );

			if ( isset( $full_response['form-url'] ) ) {
				echo $full_response['form-url'];
			} else {
				wp_send_json_error( $full_response['result-text'], 400 );
			}
		}
	}

	wp_die(); // this is required to terminate immediately and return a proper response
}

/**
 * Deletes the payment method in both the gateway and woocommerce
 */
function bng701_deletePaymentMethod() {
	$apikey          = bng701_get_apikey();
	$isAccountScreen = ( $_POST['isAcctScreen'] == 'true' ) ? true : false;

	$tokenId  = bng701_cleanTheData( $_POST['tokenId'], 'string' );
	$security = $_POST['security'];
	// if security is not null and post acct screen false
	if ( ! empty( $security ) && ! $isAccountScreen ) {
		check_ajax_referer( 'delete-pm-nonce', $security, false );
	}

	// use the token id to get the billing and valut id
	$paymentTokenToDelete = WC_Payment_Tokens::get_customer_tokens( get_current_user_id() )[ $tokenId ];
	$billingId            = $paymentTokenToDelete->get_token();
	$vaultId              = $paymentTokenToDelete->get_meta( 'vaultid' );
	$customerDetails      = bng701_getCustomerDetails( $vaultId, $apikey );

	if ( isset( $customerDetails['billing']['@attributes'] ) ) {
		// only one billing
		$body = '<delete-customer>
                    <api-key>' . $apikey . '</api-key>
                    <customer-vault-id>' . $vaultId . '</customer-vault-id>
                </delete-customer>';
	} else {
		// means there are more tHan one billing
		$body = '<delete-billing>
                    <api-key>' . $apikey . '</api-key>
                    <customer-vault-id>' . $vaultId . '</customer-vault-id>
                    <billing>
                        <billing-id>' . $billingId . '</billing-id>
                    </billing>
                </delete-billing>';
	}

	$args = array(
		'headers' => array(
			'Content-type' => 'text/xml; charset="UTF-8"',
		),
		'body'    => $body,
	);

	// use wp function to handle curl calls
	$response = wp_remote_post( NMI_Config::$pluginUrl, $args );

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( $response->get_error_message(), $response->get_error_code() );
	} else {
		$xml           = simplexml_load_string( $response['body'], 'SimpleXMLElement', LIBXML_NOCDATA );
		$json          = json_encode( $xml );
		$full_response = json_decode( $json, true );

		$resultId = $full_response['result'];

		// delete local token reference
		if ( $resultId == 1 ) {
			if ( ! $isAccountScreen ) {
				WC_Payment_Tokens::delete( $tokenId );
			}
			echo $resultId;
		} else {
			wp_send_json_error( 'Error deleting payment method - ' . $full_response['result-text'], 400 );
		}
	}
}

// endregion

/**
 * Creates a random string as an identifier for the gateway
 *
 * @return string
 */
function bng701_create_random_string() {
	$characters       = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
	$charactersLength = strlen( $characters );
	$randomString     = '';
	for ( $i = 0;  $i < 10;  $i++ ) {
		$randomString .= $characters[ rand( 0, $charactersLength - 1 ) ];
	}
	return $randomString;
}

/**
 * Helper to create new id for the gateway
 *
 * @param array  $payment_tokens - the used payment tokens
 * @param string &$customerVaultId - the customer vault id
 * @param string &$billingId - the billing id
 */
function bng701_create_new_ids( $payment_tokens, &$customerVaultId, &$billingId ) {
	$usedBillingIds = array();
	foreach ( $payment_tokens as $bngPt ) {
		$usedBillingId    = $bngPt->get_token();
		$customerVaultId  = $bngPt->get_meta( 'vaultid' );
		$usedBillingIds[] = $usedBillingId;
	}

	$isNew = 'Y';
	while ( $isNew == 'Y' ) {
		$billingId = bng701_create_random_string();
		if ( ! in_array( $billingId, $usedBillingIds ) ) {
			$isNew = 'N';
		}
	}
}

/**
 * Screens the payment method tikens in woomerce against the billing methods in NMI
 * Makes sure the payment methods sync accurately
 *
 * @param string $apikey
 */
function bng701_screen_payment_methods_against_nmi( $apikey ) {
	$temp_payment_tokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id() );
	if ( ! empty( $temp_payment_tokens ) ) {
		$customervaultid    = current( $temp_payment_tokens )->get_meta( 'vaultid' );
		$nmiCustomerDetails = bng701_getCustomerDetails( $customervaultid, $apikey );

		// if nmi is empty, delete whatever is in the paymenttoken
		if ( empty( $nmiCustomerDetails ) ) {
			foreach ( $temp_payment_tokens as $payment_token ) {
				WC_Payment_Tokens::delete( $payment_token->get_id() );
			}
		} else {
			$nmi_billings = [];
			if ( isset( $nmiCustomerDetails['billing']['@attributes'] ) ) {
				$nmi_billings[] = $nmiCustomerDetails['billing'];
			} else {
				$nmi_billings = $nmiCustomerDetails['billing'];
			}

			// Check if the payment token is not in nmi tokens, then remove from payment token
			// and continue
			foreach ( $temp_payment_tokens as $payment_token ) {
				$bid   = $payment_token->get_token();
				$found = array_filter(
					$nmi_billings,
					function( $billing ) use ( $bid ) {
						return $billing['@attributes']['id'] == $bid;
					}
				);

				// if woo token is not in nmi, delete from woo
				if ( empty( $found ) ) {
					WC_Payment_Tokens::delete( $payment_token->get_id() );
				}
			}

			// this is for nmi tokens, if an nmi token is not in woo
			// add it to woo
			$woo_ids = [];
			foreach ( $temp_payment_tokens as $tempPt ) {
				$woo_ids[] = $tempPt->get_token();
			}

			foreach ( $nmi_billings as $nmi_billing ) {
				$bid = $nmi_billing['@attributes']['id'];
				if ( ! in_array( $bid, $woo_ids ) ) {
					bng701_create_woocommerce_payment_token( $bid, $customervaultid, $apikey, $nmi_billing );
				}
			}
		}
	}
}

/**
 * Generic function that aids in creating a woocommerce token
 *
 * @param string $billingId - the nmi payment method billing id
 * @param string $vaultId - the nmi payment method vault id
 * @param string $apikey - the nmi account api key
 * @param string $paymentDetails - the nmi payment method details. defaults to empty string
 *
 * @return object WC_Payment_Token
 */
function bng701_create_woocommerce_payment_token( $billingId, $vaultId, $apikey, $paymentDetails = '' ) {
	if ( empty( $paymentDetails ) ) {
		$paymentDetails = bng701_getPMDetailsByBillingId( $billingId, $apikey )['customer']['billing'];
	}

	if ( empty( $paymentDetails['cc_number'] ) ) {
		$acctNum = substr( $paymentDetails['check_account'], -4 );

		$newPmToken = new WC_Payment_Token_ECheck();
		$newPmToken->set_last4( $acctNum );
	} else {
		$ccnumber = $paymentDetails['cc_number'];
		$ccexp    = $paymentDetails['cc_exp'];
		$last4    = substr( $ccnumber, -4 );

		$newPmToken = new WC_Payment_Token_CC();
		$newPmToken->set_last4( $last4 );
		$newPmToken->set_expiry_month( substr( $ccexp, 0, 2 ) );
		$newPmToken->set_expiry_year( '20' . substr( $ccexp, -2 ) );
		$newPmToken->set_card_type( $paymentDetails['cc_type'] );
	}

	if ( ! empty( $newPmToken ) ) {
		$newPmToken->set_token( $billingId );
		$newPmToken->set_gateway_id( '' );
		$newPmToken->set_user_id( get_current_user_id() );
		$newPmToken->add_meta_data( 'vaultid', $vaultId );
		$newPmToken->save();
	}
	return $newPmToken;
}

/**
 * Updates the payment method on a subscription
 *
 * @param object|WC_Payment_Token         $newPmToken - the new woocommerce payment token
 * @param object|WC_Order|WC_Subscription $order - the order or subscription order
 * @param string                          $billingId
 */
function bng701_update_paymentmethod_subscription( $newPmToken, $order, $billingId ) {
	if ( NMI_Config::$projectType == 'bng' || ngfw_fs()->is_plan( 'Premium' ) ) {
		$orderid = $order->get_id();

		// Remove subscription key from payment method
		if ( wcs_is_subscription( $orderid ) || wcs_order_contains_resubscribe( $order ) || wcs_order_contains_renewal( $order ) ) {
			$sub_id = $orderid;
			if ( wcs_order_contains_renewal( $order ) ) {
				$sub_id = (int) $order->get_meta( '_subscription_renewal' );
			}
		}

		// if a stored token is used, find the token using the billing id
		$payment_tokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id() );
		if ( empty( $newPmToken ) ) {
			foreach ( $payment_tokens as $pt ) {
				if ( $pt->get_token() == $billingId ) {
					$newPmToken = $pt;
				}
			}
		}

		// update payment method meta on the subscription
		if ( wcs_is_subscription( $orderid ) || wcs_order_contains_resubscribe( $order ) || wcs_order_contains_subscription( $order ) || wcs_order_contains_renewal( $order ) ) {
			$payment_meta              = [];
			$payment_meta['post_meta'] = array(
				'_nmi_gateway_vault_id'   => array(
					'value' => $newPmToken->get_meta( 'vaultid' ),
					'label' => 'NMI Gateway Customer ID',
				),
				'_nmi_gateway_billing_id' => array(
					'value' => $billingId,
					'label' => 'NMI Gateway Billing ID',
				),
			);

			if ( wcs_order_contains_renewal( $order ) ) {
				$order = wcs_get_subscription( $sub_id );
			}

			if ( wcs_order_contains_subscription( $order ) ) {
				foreach ( wcs_get_subscriptions_for_order( $orderid ) as $subscription ) {
					$subscription->set_payment_method( NMI_Config::$pluginId, $payment_meta );
					$subscription->save();
					bng701_payment_method_change_notification( $newPmToken, $subscription );
				}

				if ( wcs_order_contains_resubscribe( $order ) ) {
					$sub_id      = (int) $order->get_meta( '_subscription_resubscribe' );
					$get_old_sub = wcs_get_subscription( $sub_id );
					bng701_remove_payment_meta_from_suscription( $get_old_sub );
				}
			} else {
				$order->set_payment_method( NMI_Config::$pluginId, $payment_meta );
				$order->save();
				bng701_payment_method_change_notification( $newPmToken, $order );
			}

			// update all subscriptions payment meta is selected by user
			if ( wcs_is_subscription( $order ) && WC_Subscriptions_Change_Payment_Gateway::will_subscription_update_all_payment_methods( $order ) ) {
				$update_all = WC_Subscriptions_Change_Payment_Gateway::update_all_payment_methods_from_subscription( $order, NMI_Config::$pluginId );

				if ( $update_all === true ) {
					$subscriptions = wcs_get_users_subscriptions( get_current_user_id() );

					foreach ( $subscriptions as $subscription ) {
						if ( $subscription->get_id() == $orderid ) {
							continue;
						}

						if ( $subscription->get_time( 'next_payment' ) <= 0 || ! $subscription->has_status( array( 'active', 'on-hold' ) ) ) {
							continue;
						}

						bng701_payment_method_change_notification( $newPmToken, $subscription );
					}
				}
			}
		}
	}
}

/**
 * Add WordPress notices when a payment method is successfully changes
 *
 * @param object|WC_Payment_Token         $token
 * @param object|WC_Order|WC_Subscription
 */
function bng701_payment_method_change_notification( $token, $order ) {
	$cardType = $token->get_data()['card_type'];
	$last4    = $token->get_data()['last4'];
	$msg      = ( empty( $cardType ) ) ? 'eCheck with account number' : "{$cardType}";

	$order->add_order_note( __( "Recurring payment method has been changed to {$msg} ending in '{$last4}'.", NMI_Config::$pluginId ) );
	if ( function_exists( 'wc_add_notice' ) ) {
		wc_add_notice( __( "Payment method successfully changed on subscription {$order->get_id()}.", NMI_Config::$pluginId ) );
	}
}

/**
 * computes and returns notes for order completion
 *
 * @param string $status - indicates whether the transaction is a success or an error. 'success' or 'error'
 * @param array  $result
 *
 * @return string
 */
function bng701_get_order_completion_notes( $status, $result ) {
	$resultCodeText = bng701_getResultCodeText( $result['result-code'] );
	$dsp_error      = $result['result-text'];

	if ( $status == 'success' ) {
		// Add helpful notes
		$note  = "Order Details:\n";
		$note .= 'Transaction ID: ' . sanitize_text_field( $result['transaction-id'] ) . "\n";
		$note .= 'Result Code Text: ' . sanitize_text_field( $resultCodeText ) . ' (Code: ' . $result['result-code'] . ")\n";
		$note .= 'Authorization Code: ' . sanitize_text_field( $result['authorization-code'] ) . "\n";
		$note .= empty( $result['avs-result'] ) ? '' : 'Avs Address Match: ' . sanitize_text_field( $result['avs-result'] ) . ' - ' . bng701_getResultCodeText( 'AVS-' . $result['avs-result'] ) . "\n";
		$note .= empty( $result['cvv-result'] ) ? '' : 'Cvv Address Match: ' . sanitize_text_field( $result['cvv-result'] ) . ' - ' . bng701_getResultCodeText( 'CVV-' . $result['cvv-result'] ) . "\n";
	} else {
		// Add helpful notes
		$note  = "Failure Details:\n";
		$note .= 'Result Code Text: ' . $resultCodeText . ' (Code: ' . $result['result-code'] . ")\n";
		$note .= 'Error: ' . $dsp_error . "\n";
	}
	return $note;
}

/**
 * Removes payment from subscription when canceled
 *
 * @param object|WC_Subscription
 */
function bng701_remove_payment_meta_from_suscription( $subscription ) {
	$id = $subscription->get_id();
	$subscription->delete_meta_data( '_nmi_gateway_vault_id' );
	$subscription->delete_meta_data( '_nmi_gateway_billing_id' );
	$subscription->save_meta_data();
	$subscription->add_order_note( __( 'Recurring payment method has been removed.', NMI_Config::$pluginId ) );
	if ( function_exists( 'wc_add_notice' ) ) {
		wc_add_notice( __( "Payment method has been deleted from subscription - {$id}", NMI_Config::$pluginId ) );
	}
}

/**
 * Generic function to retrieve apikey
 */
function bng701_get_apikey() {
	$thisPluginId = NMI_Config::$pluginId;
	$settingsName = "woocommerce_{$thisPluginId}_settings";
	$settings     = get_option( $settingsName, array() );
	return sanitize_text_field( $settings['apikey'] );
}

/**
 * Generic query for collectJs api calls
 *
 * @param object|WC_Order|WC_Subscriptions $order
 * @param string                           $apikey
 * @param string                           $paymentType
 * @param string|number                    $orderTotal
 * @param object|WC_Payment_Token          $payment_token
 * @param bool                             $include_total - indicates whether to include order amount or not
 * @param bool                             $add_biling - indicates whether to return an add billing query
 *
 * @return string
 */
function bng701_generic_collectjs_query( $order, $apikey, $paymentType, $orderTotal, $payment_token = '', $include_total = true, $add_billing = false ) {
	// api and type info
	$query = '&security_key=' . urlencode( $apikey );
	// sales info
	$query .= '&payment_token=' . urlencode( $payment_token );
	if ( $include_total == true ) {
		$query .= '&amount=' . urlencode( $orderTotal );
	}
	$query .= '&payment=' . urlencode( $paymentType );
	$query .= '&currency=' . urlencode( get_woocommerce_currency() );
	if ( $paymentType == 'check' ) {
		$query .= '&sec_code=WEB';
	}

	if ( $add_billing === false ) {
		$email = $order->get_billing_email();

		$query .= '&tax=' . urlencode( $order->get_total_tax() );
		$query .= '&shipping=' . urlencode( $order->get_shipping_total() );
		$query .= '&ponumber=' . urlencode( $order->get_id() );
		// order info
		$query .= '&orderid=' . urlencode( $order->get_id() );
		$query .= '&orderdescription=Online Order';
		$query .= '&ipaddress=' . urlencode( $order->get_customer_ip_address() );
		if ( ! empty( $email ) ) {
			$query .= '&customer_receipt=true';
		}
		// Billing Information
		$query .= '&firstname=' . urlencode( $order->get_billing_first_name() );
		$query .= '&lastname=' . urlencode( $order->get_billing_last_name() );
		$query .= '&company=' . urlencode( $order->get_billing_company() );
		$query .= '&address1=' . urlencode( $order->get_billing_address_1() );
		$query .= '&address2=' . urlencode( $order->get_billing_address_2() );
		$query .= '&city=' . urlencode( $order->get_billing_city() );
		$query .= '&state=' . urlencode( $order->get_billing_state() );
		$query .= '&zip=' . urlencode( $order->get_billing_postcode() );
		$query .= '&country=' . urlencode( $order->get_billing_country() );
		$query .= '&phone=' . urlencode( $order->get_billing_phone() );
		$query .= '&email=' . urlencode( $email );
		// Shipping Information
		$query .= '&shipping_firstname=' . urlencode( $order->get_shipping_first_name() );
		$query .= '&shipping_lastname=' . urlencode( $order->get_shipping_last_name() );
		$query .= '&shipping_company=' . urlencode( $order->get_shipping_company() );
		$query .= '&shipping_address1=' . urlencode( $order->get_shipping_address_1() );
		$query .= '&shipping_address2=' . urlencode( $order->get_shipping_address_2() );
		$query .= '&shipping_city=' . urlencode( $order->get_shipping_city() );
		$query .= '&shipping_state=' . urlencode( $order->get_shipping_state() );
		$query .= '&shipping_zip=' . urlencode( $order->get_shipping_postcode() );
		$query .= '&shipping_country=' . urlencode( $order->get_shipping_country() );
		// products
		foreach ( $order->get_items() as $key => $item ) {
			$product = $item->get_product();
			$query  .= '&item_product_code_' . $key . '=' . urlencode( $product->get_sku() );
			( empty( $product->get_description() ) ) ? $query .= '&item_description_' . $key . '=' . urlencode( $product->get_short_description() ) : $query .= '&item_description_' . $key . '=' . urlencode( $product->get_description() );
			$query .= '&item_quantity_' . $key . '=' . urlencode( $item->get_quantity() );
			$query .= '&item_total_amount_' . $key . '=' . urlencode( $item->get_total() );
			$query .= '&item_tax_amount_' . $key . '=' . urlencode( $item->get_total_tax() );
		}
	}
	return $query;
}

/**
 * Generic body for nmi three step api calls
 *
 * @param string $apikey
 * @param string $referrer
 * @param string $customervaultid
 * @param string $billingid
 * @param bool   $include_total
 * @param bool   $include_vault_id
 * @param bool   $include_billing_id
 *
 * @return string
 */
function bng701_generic_threestep_body( $apikey, $referrer, $customervaultid, $billingid, $include_total = true, $include_vault_id = true, $include_billing_id = true ) {
	$data = $_POST['data'];

	$ipaddress         = $_SERVER['REMOTE_ADDR'];
	$billingemail      = sanitize_email( $data['billingemail'] );
	$orderid           = sanitize_text_field( $data['orderid'] );
	$ordertotal        = sanitize_text_field( $data['ordertotal'] );
	$ordertax          = sanitize_text_field( $data['ordertax'] );
	$ordershipping     = sanitize_text_field( $data['ordershipping'] );
	$paymentType       = sanitize_text_field( $data['paymentType'] );
	$acctType          = sanitize_text_field( $data['acctType'] );
	$entityType        = sanitize_text_field( $data['entityType'] );
	$billingfirstname  = sanitize_text_field( $data['billingfirstname'] );
	$billinglastname   = sanitize_text_field( $data['billinglastname'] );
	$billingaddress1   = sanitize_text_field( $data['billingaddress1'] );
	$billingcity       = sanitize_text_field( $data['billingcity'] );
	$billingstate      = sanitize_text_field( $data['billingstate'] );
	$billingpostcode   = sanitize_text_field( $data['billingpostcode'] );
	$billingcountry    = sanitize_text_field( $data['billingcountry'] );
	$billingphone      = sanitize_text_field( $data['billingphone'] );
	$billingcompany    = sanitize_text_field( $data['billingcompany'] );
	$billingaddress2   = sanitize_text_field( $data['billingaddress2'] );
	$shippingfirstname = sanitize_text_field( $data['shippingfirstname'] );
	$shippinglastname  = sanitize_text_field( $data['shippinglastname'] );
	$shippingaddress1  = sanitize_text_field( $data['shippingaddress1'] );
	$shippingcity      = sanitize_text_field( $data['shippingcity'] );
	$shippingstate     = sanitize_text_field( $data['shippingstate'] );
	$shippingpostcode  = sanitize_text_field( $data['shippingpostcode'] );
	$shippingcountry   = sanitize_text_field( $data['shippingcountry'] );
	$shippingphone     = sanitize_text_field( $data['shippingphone'] );
	$shippingcompany   = sanitize_text_field( $data['shippingcompany'] );
	$shippingaddress2  = sanitize_text_field( $data['shippingaddress2'] );
	$items             = wc_get_order( $orderid )->get_items();

	$secCodeBody           = '<sec-code></sec-code>';
	$additionalBillingBody = '<account-type></account-type> <entity-type></entity-type>';

	if ( $paymentType == 'check' ) {
		$secCodeBody           = '<sec-code>WEB</sec-code>';
		$additionalBillingBody = '<account-type>' . $acctType . '</account-type>
            <entity-type>' . $entityType . '</entity-type>';
	}

	$body  = '<api-key>' . $apikey . '</api-key>
                <redirect-url>' . $referrer . '</redirect-url>';
	$body .= ( $include_total === true ) ? '<amount>' . $ordertotal . '</amount>' : '';
	$body .= '<ip-address>' . $ipaddress . '</ip-address>
                <currency>' . get_woocommerce_currency() . '</currency>
                <order-id>' . $orderid . '</order-id>
                <order-description>Online Order</order-description>
                <tax-amount>' . $ordertax . '</tax-amount>
                <shipping-amount>' . $ordershipping . '</shipping-amount>
                <po-number>' . $orderid . '</po-number>';
	$body .= $secCodeBody;
	$body .= ( $include_vault_id === true ) ? '<customer-vault-id>' . $customervaultid . '</customer-vault-id>' : '';
	$body .= '<billing>';
	$body .= ( $include_billing_id === true ) ? '<billing-id>' . $billingid . '</billing-id>' : '';
	$body .= '<first-name>' . $billingfirstname . '</first-name>
                    <last-name>' . $billinglastname . '</last-name>
                    <address1>' . $billingaddress1 . '</address1>
                    <city>' . $billingcity . '</city>
                    <state>' . $billingstate . '</state>
                    <postal>' . $billingpostcode . '</postal>
                    <country>' . $billingcountry . '</country>
                    <email>' . $billingemail . '</email>
                    <phone>' . $billingphone . '</phone>
                    <company>' . $billingcompany . '</company>
                    <address2>' . $billingaddress2 . '</address2>
                    <fax></fax>' . $additionalBillingBody .
				'</billing>
                <shipping>
                    <first-name>' . $shippingfirstname . '</first-name>
                    <last-name>' . $shippinglastname . '</last-name>
                    <address1>' . $shippingaddress1 . '</address1>
                    <city>' . $shippingcity . '</city>
                    <state>' . $shippingstate . '</state>
                    <postal>' . $shippingpostcode . '</postal>
                    <country>' . $shippingcountry . '</country>
                    <phone>' . $shippingphone . '</phone>
                    <company>' . $shippingcompany . '</company>
                    <address2>' . $shippingaddress2 . '</address2>
                </shipping>';

	foreach ( $items as $item ) {
		$body .= '<product>';
		$body .= '<product-code>' . $item['product_id'] . '</product-code>';
		$body .= '<description>' . urlencode( $item['name'] ) . '</description>';
		$body .= '<commodity-code></commodity-code>';
		$body .= '<unit-of-measure></unit-of-measure>';
		$body .= '<unit-cost>' . round( $item['line_total'], 2 ) . '</unit-cost>';
		$body .= '<quantity>' . round( $item['quantity'] ) . '</quantity>';
		$body .= '<total-amount>' . round( $item['line_subtotal'], 2 ) . '</total-amount>';
		$body .= '<tax-amount></tax-amount>';
		$body .= '<tax-rate>1.00</tax-rate>';
		$body .= '<discount-amount></discount-amount>';
		$body .= '<discount-rate></discount-rate>';
		$body .= '<tax-type></tax-type>';
		$body .= '<alternate-tax-id></alternate-tax-id>';
		$body .= '</product>';
	}

	return $body;
}

/**
 * Verifies the nmi payment method details
 *
 * @param string $billingId
 * @param string $vaultId
 * @param string $apikey
 *
 * @throws Exception
 */
function bng701_verify_nmi_payment_method_details( $billingId, $vaultId, $apikey ) {
	$customerVault = bng701_getPMDetailsByBillingId( $billingId, $apikey );

	if ( empty( $customerVault ) ) {
		throw new Exception( "Billing identifier - {$billingId} was not found in our system" );
	} else {
		if ( $vaultId !== $customerVault['customer']['@attributes']['id'] ) {
			throw new Exception( "Customer vault id - {$vaultId} does not match the vault id in our system" );
		}
	}
}

// region API Calls

/**
 * Retrieves the customer details from the gateway
 *
 * @param string $vaultId - the customer vault id
 * @param string $apiKey - the gateway api key
 *
 * @return array
 */
function bng701_getCustomerDetails( $vaultId, $apiKey ) {
	$apiKey = sanitize_text_field( $apiKey );

	// Create body
	$body = [
		'keytext'           => $apiKey,
		'report_type'       => 'customer_vault',
		'ver'               => 2,
		'customer_vault_id' => $vaultId,
	];

	$response = bng701_doQueryApi( $body );

	 // get all the billing details
	if ( isset( $response['customer_vault']['customer'] ) ) {
		return $response['customer_vault']['customer'];
	}
}

/**
 * Retrieves the payment method details from the gateway
 * using the customer's billing id
 *
 * @param string $billingid - the billing id
 * @param string $apikey - the api key
 *
 * @return array
 */
function bng701_getPMDetailsByBillingId( $billingid, $apikey ) {
	// gather payment methods for this customervaultid
	$APIKey = sanitize_text_field( $apikey );

	// Create body
	$body = [
		'keytext'     => $APIKey,
		'report_type' => 'customer_vault',
		'ver'         => 2,
		'billing_id'  => $billingid,
	];

	$response = bng701_doQueryApi( $body );

	// gets details on bng entry by billing id and api key
	if ( isset( $response['customer_vault'] ) ) {
		return $response['customer_vault'];
	}
}

/**
 * Retrieves the transaction from nmi
 *
 * @param string $trans_id the transaction id
 * @param string $apikey
 *
 * @return array
 */
function bng701_getTransaction( $trans_id, $apikey ) {
	// Create body
	$body = [
		'keytext'        => $apikey,
		'ver'            => 2,
		'transaction_id' => $trans_id,
	];

	$response = bng701_doQueryApi( $body );

	if ( isset( $response['transaction'] ) ) {
		return $response['transaction'];
	}
}

/**
 * Post call to NMI's Query API
 *
 * @param string $body - the body of the api call
 *
 * @return array - an array of query api response
 */
function bng701_doQueryApi( $body ) {
	$url = 'https://secure.networkmerchants.com/api/query.php';

	$body = http_build_query( $body );

	$args = array(
		'headers' => array(
			'Content-type' => 'application/x-www-form-urlencoded; charset=utf-8',
		),
		'body'    => $body,
	);

	// use wp function to handle curl calls
	$response = wp_remote_post( $url, $args );

	// check
	if ( is_wp_error( $response ) ) {
		wp_send_json_error( $response->get_error_message(), $response->get_error_code() );
	} else {
		$xml    = simplexml_load_string( $response['body'], 'SimpleXMLElement', LIBXML_NOCDATA );
		$json   = json_encode( $xml );
		$result = json_decode( $json, true );

		return $result;
	}
}

/**
 * creates curl request
 *
 * @param string $query - the query for the curl call
 *
 * @return array
 */
function bng701_doCurl( $query ) {
	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, 'https://secure.networkmerchants.com/api/transact.php' );
	curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 30 );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
	curl_setopt( $ch, CURLOPT_HEADER, 0 );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 1 ); // change to 0 in debug mode or local machine

	curl_setopt( $ch, CURLOPT_POSTFIELDS, $query );
	curl_setopt( $ch, CURLOPT_POST, 1 );

	if ( ! ( $data = curl_exec( $ch ) ) ) {
		return 3;
	}

	curl_close( $ch );
	unset( $ch );

	$data = explode( '&', $data );
	for ( $i = 0;$i < count( $data );$i++ ) {
		$rdata                  = explode( '=', $data[ $i ] );
		$responses[ $rdata[0] ] = $rdata[1];
	}

	return $responses;
}

// endregion

// add action hooks to plugin
add_action( 'wp_enqueue_scripts', 'bng701_pw_load_scripts' );

add_action( 'wp_ajax_nopriv_bng701_stepOne_addBilling', 'bng701_stepOne_addBilling' );
add_action( 'wp_ajax_bng701_stepOne_addBilling', 'bng701_stepOne_addBilling' );

add_action( 'wp_ajax_nopriv_bng701_stepOne', 'bng701_stepOne' );
add_action( 'wp_ajax_bng701_stepOne', 'bng701_stepOne' );

add_action( 'wp_ajax_nopriv_bng701_deletePaymentMethod', 'bng701_deletePaymentMethod' );
add_action( 'wp_ajax_bng701_deletePaymentMethod', 'bng701_deletePaymentMethod' );

add_action( 'woocommerce_order_actions', 'bng701_add_order_capture_charge_action' );
add_action( 'woocommerce_order_action_capture_charge_action', 'bng701_process_capture_charge_action' );
?>
