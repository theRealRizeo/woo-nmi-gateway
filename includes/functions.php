<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


// shared methods
/**
 * This function adds the customized capture charge action to the order actions menu
 *
 * @param array $actions - predefined actions
 */
function woo_nmi_add_order_capture_charge_action( $actions ) {
	global $theorder;

	$doNotAddCaptureAction = true;
	$trans_id              = $theorder->get_transaction_id();
	$apiKey                = woo_nmi_get_api_key();

	$transaction = woo_nmi_getTransaction( $trans_id, $apiKey );
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

				$note = woo_nmi_get_order_completion_notes( 'success', $result );
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
function woo_nmi_start_complete_order_process( $apikey, $token ) {
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
function woo_nmi_complete_order( $order, $status, $result, $trans_type, $bngGatewayObj, $isAcctScreen = false ) {
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
				$note = woo_nmi_get_order_completion_notes( 'success', $result );
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
			$note = woo_nmi_get_order_completion_notes( 'error', $result );
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
function woo_nmi_process_capture_charge_action( $order ) {
	$apiKey = woo_nmi_get_api_key();
	$query  = 'security_key=' . urlencode( $apiKey );
	// Transaction Information
	$query .= '&transactionid=' . urlencode( $order->get_transaction_id() );
	$query .= '&amount=' . urlencode( $order->get_total() );
	$query .= '&orderid=' . urlencode( $order->get_id() );
	$query .= '&type=capture';

	$responses = woo_nmi_doCurl( $query );
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
		$note = woo_nmi_get_order_completion_notes( 'success', $result );
		$order->add_order_note( __( $note, NMI_Config::$pluginId ) );
		$order->payment_complete( $order->get_transaction_id() );

		// delete post meta to disallow further capturing
		delete_post_meta( $order->get_id(), 'order_charge_captured' );
	} else {
		// capture failed
		$dsp_error = $responses['responsetext'];
		$order->add_order_note( __( 'Authorization capture failed', NMI_Config::$pluginId ) );
		$order->add_order_note( __( $dsp_error, NMI_Config::$pluginId ) );

		$note = woo_nmi_get_order_completion_notes( 'error', $result );
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
function woo_nmi_payment_html( $savepaymentmethodtoggle, $tokenizationkey = '', $data = '', $order_id = '', $paymentMethods = '' ) {
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
					onclick='woo_nmi_backToCheckout();' >
				<input type='button' id='bng_submitButton' value='Submit' class='detailsDiv submit' 
					onclick='woo_nmi_cc_validate();'>
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
				woo_nmi_toggleState(event.currentTarget);
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
function woo_nmi_get_card_type( $type ) {
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
function woo_nmi_getResultCodeText( $code ) {
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

function woo_nmi_cleanTheData( $data, $datatype = 'none' ) {
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

function woo_nmi_pw_load_scripts() {
	wp_enqueue_script( 'my_script', NMI_WOO_PLUGIN_URL . 'js/my_script.js', array( 'jquery' ) );
	wp_localize_script( 'my_script', 'frontendajax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );
	wp_enqueue_script( 'backThatUp', NMI_WOO_PLUGIN_URL . 'js/backToCheckout.js' );
	wp_enqueue_style( 'my_styles', NMI_WOO_PLUGIN_URL . 'css/my_styles.css' );

	wp_enqueue_script( 'woo_nmi_ajax_custom_script', NMI_WOO_PLUGIN_URL . 'js/stepOne.js', array( 'jquery' ) );
	wp_localize_script( 'woo_nmi_ajax_custom_script', 'frontendajax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );
	wp_enqueue_script( 'woo_nmi_ajax_custom_script1', NMI_WOO_PLUGIN_URL . 'js/deletePaymentMethod.js', array( 'jquery' ) );
	wp_localize_script( 'woo_nmi_ajax_custom_script1', 'frontendajax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );
}

// region Javascript Ajax Functions

/**
 * Processes step one for the nmi-three-step.
 * Specifically adds a new billing method to an existing customer
 */
function woo_nmi_stepOne_addBilling() {
	$data     = $_POST['data'];
	$security = sanitize_text_field( $data['security'] );
	check_ajax_referer( 'checkout-nonce', $security, false );

	// catch variables passed in and define them
	$pluginId                             = NMI_Config::$pluginId;
	$apikey                               = woo_nmi_get_api_key();
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
		woo_nmi_create_new_ids( $payment_tokens, $customerVaultId, $billingId );
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
function woo_nmi_stepOne() {
	$data     = $_POST['data'];
	$pluginId = NMI_Config::$pluginId;
	$apikey   = woo_nmi_get_api_key();
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
			woo_nmi_update_paymentmethod_subscription( '', wc_get_order( $orderid ), $billingid );

			echo wc_get_endpoint_url( 'subscriptions', '', wc_get_page_permalink( 'myaccount' ) );
			wp_die();
		} else {
			// Create body
			$body  = '<' . $transactiontype . '>';
			$body .= woo_nmi_generic_threestep_body( $apikey, $referrer, $customervaultid, $billingid );
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
		woo_nmi_create_new_ids( $payment_tokens, $customervaultid, $billingid );

		if ( empty( $customervaultid ) ) {
			$customervaultid = woo_nmi_create_random_string();

			if ( ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $orderid ) ) || ! empty( $isAcctScreen ) ) {
				$body  = '<add-customer>';
				$body .= woo_nmi_generic_threestep_body( $apikey, $referrer, $customervaultid, $billingid, false );
				$body .= '</add-customer>';
			} else {
				$body  = '<' . $transactiontype . '>';
				$body .= woo_nmi_generic_threestep_body( $apikey, $referrer, $customervaultid, $billingid, true, false );
				$body .= '<add-customer>
                                <customer-vault-id>' . $customervaultid . '</customer-vault-id>
                            </add-customer>';
				$body .= '</' . $transactiontype . '>';
			}
		} else {
			woo_nmi_stepOne_addBilling();
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
		$body .= woo_nmi_generic_threestep_body( $apikey, $referrer, $customervaultid, $billingid, true, false, false );
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
function woo_nmi_deletePaymentMethod() {
	$apikey          = woo_nmi_get_api_key();
	$isAccountScreen = ( $_POST['isAcctScreen'] == 'true' ) ? true : false;

	$tokenId  = woo_nmi_cleanTheData( $_POST['tokenId'], 'string' );
	$security = $_POST['security'];
	// if security is not null and post acct screen false
	if ( ! empty( $security ) && ! $isAccountScreen ) {
		check_ajax_referer( 'delete-pm-nonce', $security, false );
	}

	// use the token id to get the billing and valut id
	$paymentTokenToDelete = WC_Payment_Tokens::get_customer_tokens( get_current_user_id() )[ $tokenId ];
	$billingId            = $paymentTokenToDelete->get_token();
	$vaultId              = $paymentTokenToDelete->get_meta( 'vaultid' );
	$customerDetails      = woo_nmi_getCustomerDetails( $vaultId, $apikey );

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
function woo_nmi_create_random_string() {
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
function woo_nmi_create_new_ids( $payment_tokens, &$customerVaultId, &$billingId ) {
	$usedBillingIds = array();
	foreach ( $payment_tokens as $bngPt ) {
		$usedBillingId    = $bngPt->get_token();
		$customerVaultId  = $bngPt->get_meta( 'vaultid' );
		$usedBillingIds[] = $usedBillingId;
	}

	$isNew = 'Y';
	while ( $isNew == 'Y' ) {
		$billingId = woo_nmi_create_random_string();
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
function woo_nmi_screen_payment_methods_against_nmi( $apikey ) {
	$temp_payment_tokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id() );
	if ( ! empty( $temp_payment_tokens ) ) {
		$customervaultid    = current( $temp_payment_tokens )->get_meta( 'vaultid' );
		$nmiCustomerDetails = woo_nmi_getCustomerDetails( $customervaultid, $apikey );

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
					woo_nmi_create_woocommerce_payment_token( $bid, $customervaultid, $apikey, $nmi_billing );
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
function woo_nmi_create_woocommerce_payment_token( $billingId, $vaultId, $apikey, $paymentDetails = '' ) {
	if ( empty( $paymentDetails ) ) {
		$paymentDetails = woo_nmi_getPMDetailsByBillingId( $billingId, $apikey )['customer']['billing'];
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
function woo_nmi_update_paymentmethod_subscription( $newPmToken, $order, $billingId ) {
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
					woo_nmi_payment_method_change_notification( $newPmToken, $subscription );
				}

				if ( wcs_order_contains_resubscribe( $order ) ) {
					$sub_id      = (int) $order->get_meta( '_subscription_resubscribe' );
					$get_old_sub = wcs_get_subscription( $sub_id );
					woo_nmi_remove_payment_meta_from_suscription( $get_old_sub );
				}
			} else {
				$order->set_payment_method( NMI_Config::$pluginId, $payment_meta );
				$order->save();
				woo_nmi_payment_method_change_notification( $newPmToken, $order );
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

						woo_nmi_payment_method_change_notification( $newPmToken, $subscription );
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
function woo_nmi_payment_method_change_notification( $token, $order ) {
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
function woo_nmi_get_order_completion_notes( $status, $result ) {
	$resultCodeText = woo_nmi_getResultCodeText( $result['result-code'] );
	$dsp_error      = $result['result-text'];

	if ( $status == 'success' ) {
		// Add helpful notes
		$note  = "Order Details:\n";
		$note .= 'Transaction ID: ' . sanitize_text_field( $result['transaction-id'] ) . "\n";
		$note .= 'Result Code Text: ' . sanitize_text_field( $resultCodeText ) . ' (Code: ' . $result['result-code'] . ")\n";
		$note .= 'Authorization Code: ' . sanitize_text_field( $result['authorization-code'] ) . "\n";
		$note .= empty( $result['avs-result'] ) ? '' : 'Avs Address Match: ' . sanitize_text_field( $result['avs-result'] ) . ' - ' . woo_nmi_getResultCodeText( 'AVS-' . $result['avs-result'] ) . "\n";
		$note .= empty( $result['cvv-result'] ) ? '' : 'Cvv Address Match: ' . sanitize_text_field( $result['cvv-result'] ) . ' - ' . woo_nmi_getResultCodeText( 'CVV-' . $result['cvv-result'] ) . "\n";
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
function woo_nmi_remove_payment_meta_from_suscription( $subscription ) {
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
function woo_nmi_get_api_key() {
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
function woo_nmi_generic_collectjs_query( $order, $apikey, $paymentType, $orderTotal, $payment_token = '', $include_total = true, $add_billing = false ) {
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
function woo_nmi_generic_threestep_body( $apikey, $referrer, $customervaultid, $billingid, $include_total = true, $include_vault_id = true, $include_billing_id = true ) {
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
function woo_nmi_verify_nmi_payment_method_details( $billingId, $vaultId, $apikey ) {
	$customerVault = woo_nmi_getPMDetailsByBillingId( $billingId, $apikey );

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
function woo_nmi_getCustomerDetails( $vaultId, $apiKey ) {
	$apiKey = sanitize_text_field( $apiKey );

	// Create body
	$body = [
		'keytext'           => $apiKey,
		'report_type'       => 'customer_vault',
		'ver'               => 2,
		'customer_vault_id' => $vaultId,
	];

	$response = woo_nmi_do_query( $body );

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
function woo_nmi_getPMDetailsByBillingId( $billingid, $apikey ) {
	// gather payment methods for this customervaultid
	$APIKey = sanitize_text_field( $apikey );

	// Create body
	$body = [
		'keytext'     => $APIKey,
		'report_type' => 'customer_vault',
		'ver'         => 2,
		'billing_id'  => $billingid,
	];

	$response = woo_nmi_do_query( $body );

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
function woo_nmi_getTransaction( $trans_id, $apikey ) {
	// Create body
	$body = [
		'keytext'        => $apikey,
		'ver'            => 2,
		'transaction_id' => $trans_id,
	];

	$response = woo_nmi_do_query( $body );

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
function woo_nmi_do_query( $body ) {
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
function woo_nmi_doCurl( $query ) {
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
add_action( 'wp_enqueue_scripts', 'woo_nmi_pw_load_scripts' );

add_action( 'wp_ajax_nopriv_woo_nmi_stepOne_addBilling', 'woo_nmi_stepOne_addBilling' );
add_action( 'wp_ajax_woo_nmi_stepOne_addBilling', 'woo_nmi_stepOne_addBilling' );

add_action( 'wp_ajax_nopriv_woo_nmi_stepOne', 'woo_nmi_stepOne' );
add_action( 'wp_ajax_woo_nmi_stepOne', 'woo_nmi_stepOne' );

add_action( 'wp_ajax_nopriv_woo_nmi_deletePaymentMethod', 'woo_nmi_deletePaymentMethod' );
add_action( 'wp_ajax_woo_nmi_deletePaymentMethod', 'woo_nmi_deletePaymentMethod' );

add_action( 'woocommerce_order_actions', 'woo_nmi_add_order_capture_charge_action' );
add_action( 'woocommerce_order_action_capture_charge_action', 'woo_nmi_process_capture_charge_action' );
?>