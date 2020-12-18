<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


class NMI_GATEWAY_WOO extends WC_Payment_Gateway {


	public function __construct() {
		$this->id                 = NMI_Config::$pluginId;
		$this->icon               = NMI_Config::$pluginIcon;
		$this->has_fields         = NMI_Config::$pluginHasFields;
		$this->order_button_text  = __( NMI_Config::$pluginButtonText, 'woo-nmi-gateway' );
		$this->method_title       = __( NMI_Config::$pluginMethodTitle, 'woo-nmi-gateway' );
		$this->method_description = __( NMI_Config::$pluginDescription, 'woo-nmi-gateway' );
		$this->gatewayURL         = NMI_Config::$pluginUrl;
		$this->chosen             = false; // set plugin to be selected on checkout page

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
		// add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		// add_action( 'woocommerce_confirm_order_' . $this->id, array( $this, 'confirm_order_page' ) );
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
	}
	
	/**
	 * Admin Panel Options.
	 */
	function admin_options() {
		?>
			<h3><?php _e( 'NMI Gateway For WooCommerce', 'woo-nmi-gateway' ); ?></h3>
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
				'title'   => __( 'Enable/Disable', 'woo-nmi-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable NMI Gateway For WooCommerce', 'woo-nmi-gateway' ),
				'default' => 'no',
			),
			'title'                    => array(
				'title'       => __( 'Title', 'woo-nmi-gateway' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woo-nmi-gateway' ),
				'default'     => __( 'Credit Card (NMI)', 'woo-nmi-gateway' ),
				'desc_tip'    => true,
			),
			'description'              => array(
				'title'       => __( 'Description', 'woo-nmi-gateway' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woo-nmi-gateway' ),
				'desc_tip'    => true,
				'default'     => __( '', 'woo-nmi-gateway' ),
			),
			'instructions'             => array(
				'title'       => __( 'Instructions', 'woo-nmi-gateway' ),
				'type'        => 'textarea',
				'description' => __( 'Instructions that will be added to the thank you page.', 'woo-nmi-gateway' ),
				'desc_tip'    => true,
				'default'     => __( '', 'woo-nmi-gateway' ),
			),
			'apikey'                   => array(
				'title'       => __( 'API Key (Required)', 'woo-nmi-gateway' ),
				'type'        => 'password',
				'description' => __( 'NMI merchant account API key', 'woo-nmi-gateway' ),
				'desc_tip'    => true,
				'default'     => '',
			),
			'tokenizationkey'          => array(
				'title'       => __( 'Tokenization Key (Optional)', 'woo-nmi-gateway' ),
				'type'        => 'password',
				'description' => __( 'Tokenization Key integrates with collect.js to allow merchants collect sensitive payment data from customers. You can choose to use collect.js by inserting a tokenization key, otherwise, simply leave this part blank.', 'woo-nmi-gateway' ),
				'desc_tip'    => true,
				'default'     => '',
			),
			'savepaymentmethodstoggle' => array(
				'title'       => __( 'Turn Saved Payment Methods On/Off', 'woo-nmi-gateway' ),
				'type'        => 'select',
				'description' => __( 'Allows you to turn saved payment methods on and off.', 'woo-nmi-gateway' ),
				'default'     => 'off',
				'desc_tip'    => true,
				'options'     => array(
					'on'  => 'On',
					'off' => 'Off',
				),
			),
			'transactiontype'          => array(
				'title'       => __( 'Transaction Type', 'woo-nmi-gateway' ),
				'type'        => 'select',
				'description' => __( 'Authorize only transaction types works when only credit card payment type is used.', 'woo-nmi-gateway' ),
				'default'     => 'sale',
				'desc_tip'    => true,
				'options'     => array(
					'auth' => 'Authorize Only',
					'sale' => 'Authorize & Capture',
				),
			),
			'paymenttype'              => array(
				'title'       => __( 'Payment Type', 'woo-nmi-gateway' ),
				'type'        => 'select',
				'description' => __( 'Allows you to make payments with either credit cards, electronic checks, or both. If credit card and echecks are selected, echeck payments will be authorized and captured.', 'woo-nmi-gateway' ),
				'default'     => 'both',
				'desc_tip'    => true,
				'options'     => array(
					'cc'   => 'Credit Card Only',
					'ach'  => 'ECheck Only',
					'both' => 'Credit Card and ECheck',
				),
			),
			'finalorderstatus'         => array(
				'title'       => __( 'Final Order Status', 'woo-nmi-gateway' ),
				'type'        => 'select',
				'description' => __( 'This option allows you to set the final status of an order after it has been processed successfully by the gateway.', 'woo-nmi-gateway' ),
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
				'title'       => __( 'Return URL', 'woo-nmi-gateway' ),
				'type'        => 'text',
				'description' => '<b>' . __( '*OPTIONAL*', 'woo-nmi-gateway' ) . '</b> <br />' . __( 'This is the URL the user will be taken to once the sale has been completed. Please enter the full URL of the page. It must be an active page on the same website. If left blank, it will take the buyer to the default order received page.', 'woo-nmi-gateway' ),
				'desc_tip'    => true,
				'default'     => '',
			),

			'debug'                    => array(
				'title'       => __( 'Debug Log' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable logging' ),
				'default'     => 'no',
				'description' => __( 'Log Gateway events, such as IPN requests' ),
			),
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
		wp_enqueue_script( 'wc_nmi_checkout', NMI_WOO_PLUGIN_URL . 'js/checkout.js', array( 'jquery-payment', 'nmi-collect-js' ), '1.0.0', true );

		$nmi_params                = array(
			'public_key'            => $this->apikey,
			'i18n_terms'            => __( 'Please accept the terms and conditions first' ),
			'i18n_required_fields'  => __( 'Please fill in required checkout fields first' ),
			'card_disallowed_error' => __( 'Card Type Not Accepted.' ),
			'placeholder_cvc'       => __( 'CVC', 'woocommerce' ),
			'placeholder_expiry'    => __( 'MM / YY', 'woocommerce' ),
			'card_number_error'     => __( 'Invalid card number.' ),
			'card_expiry_error'     => __( 'Invalid card expiry date.' ),
			'card_cvc_error'        => __( 'Invalid card CVC.' ),
			'error_ref'             => __( '(Ref: [ref])' ),
			'timeout_error'         => __( 'The tokenization did not respond in the expected timeframe. Please make sure the fields are correctly filled in and submit the form again.' ),
		);
		$nmi_params['is_checkout'] = ( is_checkout() && empty( $_GET['pay_for_order'] ) ) ? 'yes' : 'no'; // wpcs: csrf ok.

		wp_localize_script( 'wc_nmi_checkout', 'wc_nmi_checkout_params', apply_filters( 'wc_nmi_checkout_params', $nmi_params ) );
	}

	/**
	 * Add the public key to the src
	 */
	public function add_public_key_to_js( $tag, $handle ) {
		if ( 'nmi-collect-js' !== $handle ) {
			return $tag;
		}
		return str_replace( ' src', ' data-tokenization-key="' . $this->tokenizationkey . '" src', $tag );
	}

	/**
	 * Process the nmi response
	 */
	public function get_nmi_js_response() {
		if ( ! isset( $_POST['nmi_js_response'] ) ) {
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
		if ( empty( $savedMethods ) || NMI_Config::$projectType === 'bng' ) {
			woo_nmi_screen_payment_methods_against_nmi( $this->apikey );
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

				$response = woo_nmi_doCurl( $query );
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
			'orderid'           => woo_nmi_cleanTheData( $order_id, 'integer' ),
			'transactiontype'   => woo_nmi_cleanTheData( $this->transactiontype, 'string' ),
			'paymenttype'       => woo_nmi_cleanTheData( $this->paymenttype, 'string' ),
			'user_email'        => sanitize_email( $user_email ),
			'ordertotal'        => $order_total,
			'ordertax'          => $order_tax,
			'ordershipping'     => $order_shipping,
			'billingfirstname'  => woo_nmi_cleanTheData( $order->get_billing_first_name(), 'string' ),
			'billinglastname'   => woo_nmi_cleanTheData( $order->get_billing_last_name(), 'string' ),
			'billingaddress1'   => woo_nmi_cleanTheData( $order->get_billing_address_1(), 'string' ),
			'billingcity'       => woo_nmi_cleanTheData( $order->get_billing_city(), 'string' ),
			'billingstate'      => woo_nmi_cleanTheData( $order->get_billing_state(), 'string' ),
			'billingpostcode'   => woo_nmi_cleanTheData( $order->get_billing_postcode(), 'string' ),
			'billingcountry'    => woo_nmi_cleanTheData( $order->get_billing_country(), 'string' ),
			'billingemail'      => sanitize_email( $order->get_billing_email() ),
			'billingphone'      => sanitize_text_field( $order->get_billing_phone() ),
			'billingcompany'    => woo_nmi_cleanTheData( $order->get_billing_company(), 'string' ),
			'billingaddress2'   => woo_nmi_cleanTheData( $order->get_billing_address_2(), 'string' ),
			'shippingfirstname' => woo_nmi_cleanTheData( $order->get_shipping_first_name(), 'string' ),
			'shippinglastname'  => woo_nmi_cleanTheData( $order->get_shipping_last_name(), 'string' ),
			'shippingaddress1'  => woo_nmi_cleanTheData( $order->get_shipping_address_1(), 'string' ),
			'shippingcity'      => woo_nmi_cleanTheData( $order->get_shipping_city(), 'string' ),
			'shippingstate'     => woo_nmi_cleanTheData( $order->get_shipping_state(), 'string' ),
			'shippingpostcode'  => sanitize_text_field( $order->get_shipping_postcode() ),
			'shippingcountry'   => woo_nmi_cleanTheData( $order->get_shipping_country(), 'string' ),
			'shippingphone'     => sanitize_text_field( $order->get_billing_phone() ),
			'shippingcompany'   => woo_nmi_cleanTheData( $order->get_shipping_company(), 'string' ),
			'shippingaddress2'  => woo_nmi_cleanTheData( $order->get_shipping_address_2(), 'string' ),
			'security'          => wp_create_nonce( 'checkout-nonce' ),
		);

		if ( $this->apikey != '' ) {
			$paymentMethods = array();

			if ( NMI_Config::$projectType == 'bng' ) {
				if ( is_user_logged_in() && $this->savepaymentmethodtoggle == 'on' ) {
					// display saved pm's (new way)
					// get the saved payment tokens from WC
					$temp_payment_tokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id() );

					if ( ! empty( $temp_payment_tokens ) ) {
						woo_nmi_screen_payment_methods_against_nmi( $this->apikey );

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
							$paymentmethod = woo_nmi_getPMDetailsByBillingId( $billing_id, $this->apikey );
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

								$cardtype                  = woo_nmi_get_card_type( $paymentmethod['customer']['billing']['cc_type'] );
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

			woo_nmi_payment_html( $this->savepaymentmethodtoggle, $this->tokenizationkey, $data, $order_id, $paymentMethods );
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


	/**
	 * Adds payment method form to the account screen page
	 * if the user is using the premium version
	 */
	function add_bng_gateway_payment_method_form() {
		if ( $this->apikey ) {
			// this assumes the store only saves payment methods using our gateway
			if ( is_user_logged_in() && $this->savepaymentmethodtoggle == 'on' ) {
				if ( NMI_Config::$projectType === 'bng' ) {
					woo_nmi_payment_html( $this->savepaymentmethodtoggle, $this->tokenizationkey, array( 'isAcctScreen' => true ) );

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
								else await woo_nmi_cc_validate(true);
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

						var toContinue = await woo_nmi_deletePM( deleteObj, true );
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
		if ( class_exists( 'WC_Subscriptions' ) && NMI_Config::$projectType === 'bng' ) {
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
				$result = woo_nmi_start_complete_order_process( $APIKey, $token );

				if ( $result['result'] == 1 ) {
					// pull values from response
					$billingid       = isset( $result['billing']['billing-id'] ) ? $result['billing']['billing-id'] : '';
					$customervaultid = isset( $result['customer-vault-id'] ) ? $result['customer-vault-id'] : '';
					$newPmToken      = woo_nmi_create_woocommerce_payment_token( $billingid, $customervaultid, $this->apikey );

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
				$result = woo_nmi_start_complete_order_process( $APIKey, $token );

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
						$newPmToken = woo_nmi_create_woocommerce_payment_token( $billingid, $customervaultid, $this->apikey );
					}
				}
			}
		}

		if ( class_exists( 'WC_Subscriptions' ) && ( NMI_Config::$projectType == 'bng' ) ) {
			if ( $orderStatus == 'success' && ( wcs_order_contains_resubscribe( $order ) || wcs_order_contains_renewal( $order ) || wcs_order_contains_subscription( $order ) || wcs_is_subscription( $orderid ) ) ) {
				woo_nmi_update_paymentmethod_subscription( $newPmToken, $order, $billingid );
			}
		}
		woo_nmi_complete_order( $order, $orderStatus, $result, $trans_type, $this, $acctScreen );
	}


	function complete_js_request( $token ) {
		if ( get_current_user_id() > 0 ) {
			$paymentTokens 	= WC_Payment_Tokens::get_customer_tokens( get_current_user_id() );
			$oldPm     		= $paymentTokens[ $woo_token ];
			$billingId 		= $oldPm->get_token();
			$vaultId   		= $oldPm->get_meta( 'vaultid' );

			// means user is trying to change payment method
			if ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order_id ) && $orderTotal == 0 ) {
				woo_nmi_update_paymentmethod_subscription( $oldPm, $order, $billingId );
			}
		}
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
						woo_nmi_update_paymentmethod_subscription( $oldPm, $order, $billingId );

						wp_safe_redirect( wc_get_endpoint_url( 'subscriptions', '', wc_get_page_permalink( 'myaccount' ) ) );
						exit();
					}

					$savePm = ''; // reset savePm
				} elseif ( $savePm == 'on' ) {
					// use this when saving new payment methods to a customer vault
					woo_nmi_create_new_ids( $paymentTokens, $vaultId, $billingId );
					$query .= '&customer_vault=add_billing';
				}

				if ( empty( $vaultId ) && ! empty( $paymentTokens ) ) {
					$vaultId = current( $paymentTokens )->get_meta( 'vaultid' );
				}
			} elseif ( $hasVault == 'false' ) {
				// customer doesn't have a vault so create
				if ( $savePm == 'on' ) {
					$billingId = woo_nmi_create_random_string();
					$vaultId   = woo_nmi_create_random_string();
					$query    .= '&customer_vault=add_customer';
				}
			}
		}

		$query .= '&customer_vault_id=' . urlencode( $vaultId );
		$query .= '&billing_id=' . urlencode( $billingId );

		if ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order_id ) && $orderTotal == 0 ) {
			$query .= woo_nmi_generic_collectjs_query( $order, $this->apikey, $paymentType, $orderTotal, $payment_token, false );
		} elseif ( $acctScreen === true ) {
			$query .= woo_nmi_generic_collectjs_query( $order, $this->apikey, $paymentType, $orderTotal, $payment_token, false, true );
		} else {
			$query .= woo_nmi_generic_collectjs_query( $order, $this->apikey, $paymentType, $orderTotal, $payment_token );
			$query .= '&type=' . $transactionType;
		}

		$responses = woo_nmi_doCurl( $query );

		$orderStatus = 'error';
		if ( $responses['response'] == '1' ) {
			$orderStatus = 'success';
			// complete the order
			// add payment to woocommerce if customer asks to save
			$newPmToken = '';
			if ( $savePm == 'on' ) {
				$newPmToken = woo_nmi_create_woocommerce_payment_token( $billingId, $vaultId, $this->apikey );
			}

			if ( class_exists( 'WC_Subscriptions' ) && NMI_Config::$projectType == 'bng' ) {
				if ( $orderStatus == 'success' && ( wcs_order_contains_resubscribe( $order ) || wcs_order_contains_renewal( $order ) || wcs_order_contains_subscription( $order ) || wcs_is_subscription( $order_id ) ) ) {
					woo_nmi_update_paymentmethod_subscription( $newPmToken, $order, $billingId );
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

		woo_nmi_complete_order( $order, $orderStatus, $result, $transactionType, $this, $acctScreen );
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
		woo_nmi_remove_payment_meta_from_suscription( $subscription );
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
		$order     = wc_get_order( $order_id );
		$order_key = $order->get_order_key();

		$token_id = isset( $_POST['wc-nmi-payment-token'] ) ? wc_clean( $_POST['wc-nmi-payment-token'] ) : '';

		// Use NMI CURL API for payment
		try {
			$post_data    = array();
			$payment_args = array();

			if ( ! $this->get_nmi_js_response() ) {

				// Check for CC details filled or not
				if ( empty( $_POST['woo-nmi-card-number'] ) || empty( $_POST['woo-nmi-card-expiry'] ) || empty( $_POST['woo-nmi-card-cvc'] ) ) {
					throw new Exception( __( 'Credit card details cannot be left incomplete.', 'wc-nmi' ) );
				}
			}

			if ( $js_response = $this->get_nmi_js_response() ) {
				$post_data['payment_token'] = $js_response['token'];
			} else {
				$expiry                = explode( ' / ', wc_clean( $_POST['woo-nmi-card-expiry'] ) );
				$expiry[1]             = substr( $expiry[1], -2 );
				$post_data['ccnumber'] = wc_clean( $_POST['woo-nmi-card-number'] );
				$post_data['ccexp']    = $expiry[0] . $expiry[1];
				$post_data['cvv']      = wc_clean( $_POST['woo-nmi-card-cvc'] );
			}

			$description = sprintf( __( '%1$s - Order %2$s', 'wc-nmi' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number() );

			$description .= ' (' . $this->get_line_items( $order ) . ')';

			$payment_args = array(
				'orderid'           => $order->get_order_number(),
				'order_description' => $description,
				'amount'            => $order->get_total(),
				'transactionid'     => $order->get_transaction_id(),
				'type'              => $this->transactiontype,
				'first_name'        => $order->get_billing_first_name(),
				'last_name'         => $order->get_billing_last_name(),
				'address1'          => $order->get_billing_address_1(),
				'address2'          => $order->get_billing_address_2(),
				'city'              => $order->get_billing_city(),
				'state'             => $order->get_billing_state(),
				'country'           => $order->get_billing_country(),
				'zip'               => $order->get_billing_postcode(),
				'email'             => $order->get_billing_email(),
				'phone'             => $order->get_billing_phone(),
				'company'           => $order->get_billing_company(),
				'currency'          => $this->get_payment_currency( $order_id ),
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

				$billingid       = isset( $response['billing']['billing-id'] ) ? $response['billing']['billing-id'] : '';
				$customervaultid = isset( $response['customer-vault-id'] ) ? $response['customer-vault-id'] : '';
				$newPmToken      = woo_nmi_create_woocommerce_payment_token( $billingid, $customervaultid, $this->apikey );
				

				if ( $payment_args['type'] == 'sale' ) {

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

				//Subscription process
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
					$resp = wp_remote_post( NMI_Config::$pluginUrl, $args );

					if ( !is_wp_error( $resp ) ) {
						$xml    = simplexml_load_string( $resp['body'], 'SimpleXMLElement', LIBXML_NOCDATA );
						$json   = json_encode( $xml );
						$result = json_decode( $json, true );
						WC_NMI_Logger::log( $json );
					}
				}
				$order->save();

			}

			// Remove cart
			WC()->cart->empty_cart();

			do_action( 'wc_gateway_nmi_process_payment', $response, $order );

			// Return thank you page redirect
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);

		} catch ( Exception $e ) {
			wc_add_notice( sprintf( __( 'Gateway Error: %s', 'wc-nmi' ), $e->getMessage() ), 'error' );
			WC_NMI_Logger::log( sprintf( __( 'Gateway Error: %s', 'wc-nmi' ), $e->getMessage() ) );

			do_action( 'wc_gateway_nmi_process_payment_error', $e, $order );

			$order->update_status( 'failed' );

			return array(
				'result'   => 'fail',
				'redirect' => '',
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
		$args['ipaddress']        = isset( $args['ipaddress'] ) ? $args['ipaddress'] : WC_Geolocation::get_ip_address();

		if ( isset( $args['transactionid'] ) && empty( $args['transactionid'] ) ) {
			unset( $args['transactionid'] );
		}

		if ( isset( $args['currency'] ) && empty( $args['currency'] ) ) {
			$args['currency'] = get_woocommerce_currency();
		}

		if ( isset( $args['state'] ) && empty( $args['state'] ) && ! in_array( $args['type'], array( 'capture', 'void', 'refund' ) ) ) {
			$args['state'] = 'NA';
		}

		$args = array_merge( $args, $auth_params );

		// Setting custom timeout for the HTTP request
		add_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ), 9999 );

		// $headers = array( 'Content-Type' => 'application/json' );
		$headers  = array();
		$response = wp_remote_post(
			$request_url,
			array(
				'body'    => $args,
				'headers' => $headers,
			)
		);

		$result = is_wp_error( $response ) ? $response : wp_remote_retrieve_body( $response );

		// Saving to Log here
		if ( $this->debug ) {
			$message = sprintf( "\nPosting to: \n%s\nRequest: \n%sResponse: \n%s", $request_url, print_r( $args, 1 ), print_r( $result, 1 ) );
			WC_NMI_Logger::log( $message );
		}

		remove_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ), 9999 );

		if ( is_wp_error( $result ) ) {
			return $result;
		} elseif ( empty( $result ) ) {
			return new WP_Error( 'invalid_response', __( 'There was an error with the gateway response.', 'wc-nmi' ) );
		}

		parse_str( $result, $result );

		if ( count( $result ) < 8 ) {
			return new WP_Error( 'invalid_response', sprintf( __( 'Unrecognized response from the gateway: %s', 'wc-nmi' ), $response ) );
		}

		if ( ! isset( $result['response'] ) || ! in_array( $result['response'], array( 1, 2, 3 ) ) ) {
			return new WP_Error( 'invalid_response', __( 'There was an error with the gateway response.', 'wc-nmi' ) );
		}

		if ( $result['response'] == 2 ) {
			return new WP_Error( 'decline_response', '<!-- Error: ' . $result['response_code'] . ' --> ' . __( 'Your card has been declined.', 'wc-nmi' ), $result );
		}

		if ( $result['response'] == 3 ) {
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
			$line_items[] = $item->get_name() . ' x ' . $item->get_quantity();
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
		$transaction   = woo_nmi_getTransaction( $order->get_transaction_id(), $this->apikey );
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
		$order->add_order_note( __( $note, 'woo-nmi-gateway' ) );

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

			woo_nmi_verify_nmi_payment_method_details( $billingId, $vaultId, $this->apikey );

			if ( empty( $cc_number ) ) {
				$paymentType = 'check';
			} else {
				$paymentType = 'creditcard';
			}

			$query  = woo_nmi_generic_collectjs_query( $order, $this->apikey, $paymentType, $amount_to_charge );
			$query .= '&customer_vault_id=' . urlencode( $vaultId );
			$query .= '&billing_id=' . urlencode( $billingId );
			$query .= '&type=sale';

			$responses = woo_nmi_doCurl( $query );

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
				woo_nmi_verify_nmi_payment_method_details( $billingId, $vaultId, $this->apikey );
			} catch ( Exception $ex ) {
				WC_NMI_Logger::log( $ex->getMessage() );
				throw new Exception( __( $ex->getMessage(), 'woo-nmi-gateway' ) );
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

		return woo_nmi_do_query( $body );
	}
}

?>
