<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * API for inline transactions
 * This handles the inline configuration that allows us to have an inline card in the checkout form
 * It uses the direct post method
 *
 * @author Paul Kevin <paul@hubloy.com>
 */
class NMI_GATEWAY_WOO_API {

	/**
	 * The API key
	 *
	 * @var string
	 */
	private $api_key = null;

	/**
	 * The order array
	 * This holds the order data sent to the gateway
	 *
	 * @var array
	 */
	private $order = array();

	/**
	 * The billing array
	 * This holds the billing data sent to the gateway
	 *
	 * @var array
	 */
	private $billing = array();

	/**
	 * The shipping array
	 * This holds the shipping data sent to the gateway
	 *
	 * @var array
	 */
	private $shipping = array();


	/**
	 * The items array
	 * This holds the items data sent to the gateway
	 *
	 * @var array
	 */
	private $items = array();

	/**
	 * Main constructor
	 *
	 * @param string $_api_key - the api key
	 */
	public function __construct( $_api_key ) {
		$this->api_key = $_api_key;
		$this->reset();
	}

	/**
	 * Reset array values
	 */
	public function reset() {
		$this->order    = array(); // Reset the order
		$this->billing  = array(); // Reset the billing
		$this->shipping = array(); // Reset the shipping
		$this->items    = array();
	}

	/**
	 * Populate the array values from the order
	 *
	 * @param int $order_id - the order id
	 */
	public function populate( $order_id ) {
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
		$tax        = $order->get_total_tax();
		$shipping   = $order->get_shipping_total();
		$ponumber   = $order->get_id();
		$ip_address = $order->get_customer_ip_address();
		$this->set_order( $order_id, 'Online Order', $tax, $shipping, $ponumber, $ip_address );
		$this->set_billing( $order->get_billing_first_name(), $order->get_billing_last_name(), $order->get_billing_company(), $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city(), $order->get_billing_state(), $order->get_billing_postcode(), $order->get_billing_country(), $order->get_billing_phone(), $email );
		$this->set_shipping( $order->get_shipping_first_name(), $order->get_shipping_last_name(), $order->get_shipping_company(), $order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city(), $order->get_shipping_state(), $order->get_shipping_postcode(), $order->get_shipping_country(), $email );
		$this->set_items( $order->get_items() );
	}

	/**
	 * Set order details
	 */
	public function set_order( $order_id, $description, $tax, $shipping, $ponumber, $ip_address ) {
		$this->order['orderid']          = $order_id;
		$this->order['orderdescription'] = $description;
		$this->order['tax']              = $tax;
		$this->order['shipping']         = $shipping;
		$this->order['ponumber']         = $ponumber;
		$this->order['ipaddress']        = $ip_address;
	}

	/**
	 * Set billing info
	 */
	public function set_billing( $firstname, $lastname, $company, $address1, $address2, $city, $state, $zip, $country, $phone, $email ) {
		$this->billing['firstname'] = $firstname;
		$this->billing['lastname']  = $lastname;
		$this->billing['company']   = $company;
		$this->billing['address1']  = $address1;
		$this->billing['address2']  = $address2;
		$this->billing['city']      = $city;
		$this->billing['state']     = $state;
		$this->billing['zip']       = $zip;
		$this->billing['country']   = $country;
		$this->billing['phone']     = $phone;
		$this->billing['email']     = $email;
	}

	/**
	 * Set shipping info
	 */
	public function set_shipping( $firstname, $lastname, $company, $address1, $address2, $city, $state, $zip, $country, $email ) {
		$this->shipping['firstname'] = $firstname;
		$this->shipping['lastname']  = $lastname;
		$this->shipping['company']   = $company;
		$this->shipping['address1']  = $address1;
		$this->shipping['address2']  = $address2;
		$this->shipping['city']      = $city;
		$this->shipping['state']     = $state;
		$this->shipping['zip']       = $zip;
		$this->shipping['country']   = $country;
		$this->shipping['email']     = $email;
	}

	/**
	 * Set items
	 */
	public function set_items( $items ) {
		foreach ( $items as $key => $item ) {
			$product                                    = $item->get_product();
			$this->items[ 'item_product_code_' . $key ] = $product->get_sku();
			$this->items[ 'item_description_' . $key ]  = empty( $product->get_description() ) ? $product->get_short_description() : $product->get_description();
			$this->items[ 'item_quantity_' . $key ]     = $item->get_quantity();
			$this->items[ 'item_total_amount_' . $key ] = $item->get_total();
			$this->items[ 'item_tax_amount_' . $key ]   = $item->get_total_tax();
		}
	}

	function doSale( $amount, $ccnumber, $ccexp, $cvv = '' ) {

		$query = '';
		// Login Information
		$query .= 'security_key=' . urlencode( $this->api_key ) . '&';
		// Sales Information
		$query .= 'ccnumber=' . urlencode( $ccnumber ) . '&';
		$query .= 'ccexp=' . urlencode( $ccexp ) . '&';
		$query .= 'amount=' . urlencode( number_format( $amount, 2, '.', '' ) ) . '&';
		$query .= 'cvv=' . urlencode( $cvv ) . '&';
		// Order Information
		$query .= 'ipaddress=' . urlencode( $this->order['ipaddress'] ) . '&';
		$query .= 'orderid=' . urlencode( $this->order['orderid'] ) . '&';
		$query .= 'orderdescription=' . urlencode( $this->order['orderdescription'] ) . '&';
		$query .= 'tax=' . urlencode( number_format( $this->order['tax'], 2, '.', '' ) ) . '&';
		$query .= 'shipping=' . urlencode( number_format( $this->order['shipping'], 2, '.', '' ) ) . '&';
		$query .= 'ponumber=' . urlencode( $this->order['ponumber'] ) . '&';
		// Billing Information
		$query .= 'firstname=' . urlencode( $this->billing['firstname'] ) . '&';
		$query .= 'lastname=' . urlencode( $this->billing['lastname'] ) . '&';
		$query .= 'company=' . urlencode( $this->billing['company'] ) . '&';
		$query .= 'address1=' . urlencode( $this->billing['address1'] ) . '&';
		$query .= 'address2=' . urlencode( $this->billing['address2'] ) . '&';
		$query .= 'city=' . urlencode( $this->billing['city'] ) . '&';
		$query .= 'state=' . urlencode( $this->billing['state'] ) . '&';
		$query .= 'zip=' . urlencode( $this->billing['zip'] ) . '&';
		$query .= 'country=' . urlencode( $this->billing['country'] ) . '&';
		$query .= 'phone=' . urlencode( $this->billing['phone'] ) . '&';
		$query .= 'fax=' . urlencode( $this->billing['fax'] ) . '&';
		$query .= 'email=' . urlencode( $this->billing['email'] ) . '&';
		$query .= 'website=' . urlencode( $this->billing['website'] ) . '&';
		// Shipping Information
		$query .= 'shipping_firstname=' . urlencode( $this->shipping['firstname'] ) . '&';
		$query .= 'shipping_lastname=' . urlencode( $this->shipping['lastname'] ) . '&';
		$query .= 'shipping_company=' . urlencode( $this->shipping['company'] ) . '&';
		$query .= 'shipping_address1=' . urlencode( $this->shipping['address1'] ) . '&';
		$query .= 'shipping_address2=' . urlencode( $this->shipping['address2'] ) . '&';
		$query .= 'shipping_city=' . urlencode( $this->shipping['city'] ) . '&';
		$query .= 'shipping_state=' . urlencode( $this->shipping['state'] ) . '&';
		$query .= 'shipping_zip=' . urlencode( $this->shipping['zip'] ) . '&';
		$query .= 'shipping_country=' . urlencode( $this->shipping['country'] ) . '&';
		$query .= 'shipping_email=' . urlencode( $this->shipping['email'] ) . '&';
		$query .= 'type=sale';
		return $this->_doPost( $query );
	}

	function doAuth( $amount, $ccnumber, $ccexp, $cvv = '' ) {

		$query = '';
		// Login Information
		$query .= 'security_key=' . urlencode( $this->api_key ) . '&';
		// Sales Information
		$query .= 'ccnumber=' . urlencode( $ccnumber ) . '&';
		$query .= 'ccexp=' . urlencode( $ccexp ) . '&';
		$query .= 'amount=' . urlencode( number_format( $amount, 2, '.', '' ) ) . '&';
		$query .= 'cvv=' . urlencode( $cvv ) . '&';
		// Order Information
		$query .= 'ipaddress=' . urlencode( $this->order['ipaddress'] ) . '&';
		$query .= 'orderid=' . urlencode( $this->order['orderid'] ) . '&';
		$query .= 'orderdescription=' . urlencode( $this->order['orderdescription'] ) . '&';
		$query .= 'tax=' . urlencode( number_format( $this->order['tax'], 2, '.', '' ) ) . '&';
		$query .= 'shipping=' . urlencode( number_format( $this->order['shipping'], 2, '.', '' ) ) . '&';
		$query .= 'ponumber=' . urlencode( $this->order['ponumber'] ) . '&';
		// Billing Information
		$query .= 'firstname=' . urlencode( $this->billing['firstname'] ) . '&';
		$query .= 'lastname=' . urlencode( $this->billing['lastname'] ) . '&';
		$query .= 'company=' . urlencode( $this->billing['company'] ) . '&';
		$query .= 'address1=' . urlencode( $this->billing['address1'] ) . '&';
		$query .= 'address2=' . urlencode( $this->billing['address2'] ) . '&';
		$query .= 'city=' . urlencode( $this->billing['city'] ) . '&';
		$query .= 'state=' . urlencode( $this->billing['state'] ) . '&';
		$query .= 'zip=' . urlencode( $this->billing['zip'] ) . '&';
		$query .= 'country=' . urlencode( $this->billing['country'] ) . '&';
		$query .= 'phone=' . urlencode( $this->billing['phone'] ) . '&';
		$query .= 'fax=' . urlencode( $this->billing['fax'] ) . '&';
		$query .= 'email=' . urlencode( $this->billing['email'] ) . '&';
		$query .= 'website=' . urlencode( $this->billing['website'] ) . '&';
		// Shipping Information
		$query .= 'shipping_firstname=' . urlencode( $this->shipping['firstname'] ) . '&';
		$query .= 'shipping_lastname=' . urlencode( $this->shipping['lastname'] ) . '&';
		$query .= 'shipping_company=' . urlencode( $this->shipping['company'] ) . '&';
		$query .= 'shipping_address1=' . urlencode( $this->shipping['address1'] ) . '&';
		$query .= 'shipping_address2=' . urlencode( $this->shipping['address2'] ) . '&';
		$query .= 'shipping_city=' . urlencode( $this->shipping['city'] ) . '&';
		$query .= 'shipping_state=' . urlencode( $this->shipping['state'] ) . '&';
		$query .= 'shipping_zip=' . urlencode( $this->shipping['zip'] ) . '&';
		$query .= 'shipping_country=' . urlencode( $this->shipping['country'] ) . '&';
		$query .= 'shipping_email=' . urlencode( $this->shipping['email'] ) . '&';
		$query .= 'type=auth';
		return $this->_doPost( $query );
	}

	function doCredit( $amount, $ccnumber, $ccexp ) {

		$query = '';
		// Login Information
		$query .= 'security_key=' . urlencode( $this->api_key ) . '&';
		// Sales Information
		$query .= 'ccnumber=' . urlencode( $ccnumber ) . '&';
		$query .= 'ccexp=' . urlencode( $ccexp ) . '&';
		$query .= 'amount=' . urlencode( number_format( $amount, 2, '.', '' ) ) . '&';
		// Order Information
		$query .= 'ipaddress=' . urlencode( $this->order['ipaddress'] ) . '&';
		$query .= 'orderid=' . urlencode( $this->order['orderid'] ) . '&';
		$query .= 'orderdescription=' . urlencode( $this->order['orderdescription'] ) . '&';
		$query .= 'tax=' . urlencode( number_format( $this->order['tax'], 2, '.', '' ) ) . '&';
		$query .= 'shipping=' . urlencode( number_format( $this->order['shipping'], 2, '.', '' ) ) . '&';
		$query .= 'ponumber=' . urlencode( $this->order['ponumber'] ) . '&';
		// Billing Information
		$query .= 'firstname=' . urlencode( $this->billing['firstname'] ) . '&';
		$query .= 'lastname=' . urlencode( $this->billing['lastname'] ) . '&';
		$query .= 'company=' . urlencode( $this->billing['company'] ) . '&';
		$query .= 'address1=' . urlencode( $this->billing['address1'] ) . '&';
		$query .= 'address2=' . urlencode( $this->billing['address2'] ) . '&';
		$query .= 'city=' . urlencode( $this->billing['city'] ) . '&';
		$query .= 'state=' . urlencode( $this->billing['state'] ) . '&';
		$query .= 'zip=' . urlencode( $this->billing['zip'] ) . '&';
		$query .= 'country=' . urlencode( $this->billing['country'] ) . '&';
		$query .= 'phone=' . urlencode( $this->billing['phone'] ) . '&';
		$query .= 'fax=' . urlencode( $this->billing['fax'] ) . '&';
		$query .= 'email=' . urlencode( $this->billing['email'] ) . '&';
		$query .= 'website=' . urlencode( $this->billing['website'] ) . '&';
		$query .= 'type=credit';
		return $this->_doPost( $query );
	}

	function doOffline( $authorizationcode, $amount, $ccnumber, $ccexp ) {

		$query = '';
		// Login Information
		$query .= 'security_key=' . urlencode( $this->api_key ) . '&';
		// Sales Information
		$query .= 'ccnumber=' . urlencode( $ccnumber ) . '&';
		$query .= 'ccexp=' . urlencode( $ccexp ) . '&';
		$query .= 'amount=' . urlencode( number_format( $amount, 2, '.', '' ) ) . '&';
		$query .= 'authorizationcode=' . urlencode( $authorizationcode ) . '&';
		// Order Information
		$query .= 'ipaddress=' . urlencode( $this->order['ipaddress'] ) . '&';
		$query .= 'orderid=' . urlencode( $this->order['orderid'] ) . '&';
		$query .= 'orderdescription=' . urlencode( $this->order['orderdescription'] ) . '&';
		$query .= 'tax=' . urlencode( number_format( $this->order['tax'], 2, '.', '' ) ) . '&';
		$query .= 'shipping=' . urlencode( number_format( $this->order['shipping'], 2, '.', '' ) ) . '&';
		$query .= 'ponumber=' . urlencode( $this->order['ponumber'] ) . '&';
		// Billing Information
		$query .= 'firstname=' . urlencode( $this->billing['firstname'] ) . '&';
		$query .= 'lastname=' . urlencode( $this->billing['lastname'] ) . '&';
		$query .= 'company=' . urlencode( $this->billing['company'] ) . '&';
		$query .= 'address1=' . urlencode( $this->billing['address1'] ) . '&';
		$query .= 'address2=' . urlencode( $this->billing['address2'] ) . '&';
		$query .= 'city=' . urlencode( $this->billing['city'] ) . '&';
		$query .= 'state=' . urlencode( $this->billing['state'] ) . '&';
		$query .= 'zip=' . urlencode( $this->billing['zip'] ) . '&';
		$query .= 'country=' . urlencode( $this->billing['country'] ) . '&';
		$query .= 'phone=' . urlencode( $this->billing['phone'] ) . '&';
		$query .= 'fax=' . urlencode( $this->billing['fax'] ) . '&';
		$query .= 'email=' . urlencode( $this->billing['email'] ) . '&';
		$query .= 'website=' . urlencode( $this->billing['website'] ) . '&';
		// Shipping Information
		$query .= 'shipping_firstname=' . urlencode( $this->shipping['firstname'] ) . '&';
		$query .= 'shipping_lastname=' . urlencode( $this->shipping['lastname'] ) . '&';
		$query .= 'shipping_company=' . urlencode( $this->shipping['company'] ) . '&';
		$query .= 'shipping_address1=' . urlencode( $this->shipping['address1'] ) . '&';
		$query .= 'shipping_address2=' . urlencode( $this->shipping['address2'] ) . '&';
		$query .= 'shipping_city=' . urlencode( $this->shipping['city'] ) . '&';
		$query .= 'shipping_state=' . urlencode( $this->shipping['state'] ) . '&';
		$query .= 'shipping_zip=' . urlencode( $this->shipping['zip'] ) . '&';
		$query .= 'shipping_country=' . urlencode( $this->shipping['country'] ) . '&';
		$query .= 'shipping_email=' . urlencode( $this->shipping['email'] ) . '&';
		$query .= 'type=offline';
		return $this->_doPost( $query );
	}

	function doCapture( $transactionid, $amount = 0 ) {

		$query = '';
		// Login Information
		$query .= 'security_key=' . urlencode( $this->api_key ) . '&';
		// Transaction Information
		$query .= 'transactionid=' . urlencode( $transactionid ) . '&';
		if ( $amount > 0 ) {
			$query .= 'amount=' . urlencode( number_format( $amount, 2, '.', '' ) ) . '&';
		}
		$query .= 'type=capture';
		return $this->_doPost( $query );
	}

	function doVoid( $transactionid ) {

		$query = '';
		// Login Information
		$query .= 'security_key=' . urlencode( $this->api_key ) . '&';
		// Transaction Information
		$query .= 'transactionid=' . urlencode( $transactionid ) . '&';
		$query .= 'type=void';
		return $this->_doPost( $query );
	}

	function doRefund( $transactionid, $amount = 0 ) {

		$query = '';
		// Login Information
		$query .= 'security_key=' . urlencode( $this->api_key ) . '&';
		// Transaction Information
		$query .= 'transactionid=' . urlencode( $transactionid ) . '&';
		if ( $amount > 0 ) {
			$query .= 'amount=' . urlencode( number_format( $amount, 2, '.', '' ) ) . '&';
		}
		$query .= 'type=refund';
		return $this->_doPost( $query );
	}

	private function _doPost( $query ) {
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, 'https://secure.networkmerchants.com/api/transact.php' );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 30 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_HEADER, 0 );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );

		curl_setopt( $ch, CURLOPT_POSTFIELDS, $query );
		curl_setopt( $ch, CURLOPT_POST, 1 );

		if ( ! ( $data = curl_exec( $ch ) ) ) {
			return ERROR;
		}
		curl_close( $ch );
		unset( $ch );
		print "\n$data\n";
		$data = explode( '&', $data );
		for ( $i = 0;$i < count( $data );$i++ ) {
			$rdata                        = explode( '=', $data[ $i ] );
			$this->responses[ $rdata[0] ] = $rdata[1];
		}
		return $this->responses['response'];
	}
}

