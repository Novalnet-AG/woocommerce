<?php
/**
 * Handling Novalnet validation / process functions
 *
 * @class    WC_Novalnet_Helper
 * @package  woocommerce-novalnet-gateway/includes/
 * @category Class
 * @author   Novalnet
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WC_Novalnet_Helper Class.
 */
class WC_Novalnet_Helper {

	/**
	 * Payport Endpoint URL.
	 *
	 * @var string
	 */
	private $endpoint = 'https://payport.novalnet.de/v2/';

	/**
	 * Status mapper.
	 *
	 * @var array
	 */
	public $statuses = array(
		'ON_HOLD'     => array( '85', '91', '98', '99', '84' ),
		'CONFIRMED'   => array( '100' ),
		'PENDING'     => array( '90', '80', '86', '83', '75' ),
		'DEACTIVATED' => array( '103' ),
	);

	/**
	 * List of BIC allowed countries.
	 *
	 * @var $bic_allowed_countries The single instance of the class.
	 * @since 12.5.6
	 */
	private $bic_allowed_countries = array( 'CH', 'MC', 'SM', 'GB', 'GI' );

	/**
	 * The single instance of the class.
	 *
	 * @var   Novalnet_Helper The single instance of the class.
	 * @since 12.0.0
	 */
	protected static $instance = null;

	/**
	 * Main Novalnet_Helper Instance.
	 *
	 * Ensures only one instance of Novalnet_Helper is loaded or can be loaded.
	 *
	 * @since  12.0.0
	 * @static
	 * @return Novalnet_Api_Callback Main instance.
	 */
	public static function instance() {

		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Submit the given request and convert the
	 * query string to array.
	 *
	 * @since 12.0.0
	 *
	 * @param array  $request The request data.
	 * @param string $url     The request url.
	 * @param array  $args    Arguments.
	 *
	 * @return array
	 */
	public function submit_request( $request, $url, $args = array() ) {

		$args = wp_parse_args(
			$args,
			array(
				'access_key' => '',
				'post_id'    => '',
			)
		);

		$override_log_setting = ( ! empty( $args['is_scheduled_payment'] ) && true === $args['is_scheduled_payment'] ) ? true : false;

		// Perform server call and format the response.
		if ( empty( $args['access_key'] ) ) {
			$args['access_key'] = WC_Novalnet_Configuration::get_global_settings( 'key_password' );
		}

		if ( ! empty( $args['access_key'] ) ) {

			// Form headers.
			$headers = array(
				'Content-Type'    => 'application/json',
				'charset'         => 'utf-8',
				'Accept'          => 'application/json',
				'X-NN-Access-Key' => base64_encode( $args['access_key'] ), // phpcs:ignore.
			);

			$json_request = wc_novalnet_serialize_data( $request );

			if ( false === strpos( $url, 'merchant/details' ) ) {
				if ( isset( $request['transaction']['payment_data'] ) ) {
					unset( $request['transaction']['payment_data'] );
				}
				$request_log = wc_novalnet_serialize_data( $request );

				$this->debug( "REQUEST: {$url} - $request_log", $args['post_id'], $override_log_setting );
			}

			// Post the values to the paygate URL.
			$response = wp_remote_post(
				$url,
				array(
					'method'  => 'POST',
					'headers' => $headers,
					'timeout' => 240,
					'body'    => $json_request,
				)
			);

			// Log and return error.
			if ( is_wp_error( $response ) ) {

				// Log error.
				$this->log_error( "API call ($url) failed due to the connection error: " . $response->get_error_message(), $args['post_id'] );

				return array(
					'result' => array(
						'status'      => 'FAILURE',
						'status_code' => '106',
						'status_text' => $response->get_error_message(),
					),
				);
			} elseif ( ! empty( $response['body'] ) ) {
				if ( false === strpos( $url, 'merchant/details' ) ) {
					$res_data = wc_novalnet_unserialize_data( $response['body'] );
					if ( isset( $res_data['transaction']['payment_data'] ) ) {
						unset( $res_data['transaction']['payment_data'] );
					}
					$response_log = wc_novalnet_serialize_data( $res_data );

					$this->debug( "RESPONSE: {$url} - " . $response_log, $args['post_id'], $override_log_setting );
				}
				return wc_novalnet_unserialize_data( $response['body'] );
			}
		}
		return array(
			'result' => array(
				'status_code' => '106',
				'status'      => 'FAILURE',
				'status_text' => __( 'Please enter the required fields under Novalnet API Configuration', 'woocommerce-novalnet-gateway' ),
			),
		);
	}

	/**
	 * Prepare the Novalnet transaction comments.
	 *
	 * @since 12.0.0
	 * @param array $data The data.
	 * @param array $wc_order The order object.
	 * @return array
	 */
	public function prepare_payment_comments( $data, $wc_order = false ) {

		// Forming basic comments.
		$comments = $this->form_comments( $data );
		if ( 'PENDING' === $data['transaction']['status'] && in_array( $data['transaction']['payment_type'], array( 'GUARANTEED_INVOICE', 'GUARANTEED_DIRECT_DEBIT_SEPA', 'INSTALMENT_INVOICE', 'INSTALMENT_DIRECT_DEBIT_SEPA' ), true ) ) {
			$comments .= PHP_EOL . PHP_EOL . __( 'Your order is under verification and we will soon update you with the order status. Please note that this may take upto 24 hours.', 'woocommerce-novalnet-gateway' );
		} elseif ( ! empty( $data ['transaction']['bank_details'] ) && ! empty( $data ['transaction']['amount'] ) && empty( $data ['instalment']['prepaid'] ) ) {
			$comments .= $this->form_amount_transfer_comments( $data, $wc_order );
		} elseif ( ! empty( $data['transaction']['nearest_stores'] ) ) {

			$comments .= $this->form_nearest_store_comments( $data );

		} elseif ( ! empty( $data['transaction']['partner_payment_reference'] ) ) {

			/* translators: %s: amount */
			$comments .= PHP_EOL . sprintf( __( 'Please use the following payment reference details to pay the amount of %s at a Multibanco ATM or through your internet banking.', 'woocommerce-novalnet-gateway' ), wc_novalnet_shop_amount_format( $data['transaction']['amount'] ) );

			/* translators: %s: partner_payment_reference */
			$comments .= PHP_EOL . sprintf( __( 'Payment Reference : %s', 'woocommerce-novalnet-gateway' ), $data['transaction']['partner_payment_reference'] ) . PHP_EOL;
		}

		return $comments;
	}

	/**
	 * Form payment comments.
	 *
	 * @since 12.0.0
	 * @param array   $data The comment data.
	 * @param boolean $is_error The error.
	 *
	 * @return string
	 */
	public function form_comments( $data, $is_error = false ) {

		$comments = '';

		if ( WC_Novalnet_Validation::is_success_status( $data ) && in_array( $data['transaction']['payment_type'], array( 'GOOGLEPAY', 'APPLEPAY' ), true ) ) {
			$payment = ( 'GOOGLEPAY' === $data['transaction']['payment_type'] ) ? 'Google Pay' : 'Apple Pay';
			/* translators: %1$s: brand, %2$s: last four */
			$card_mask = ( isset( $data['transaction']['payment_data']['card_brand'] ) ) ? sprintf(
				'(%1$s ****%2$s)',
				strtolower(
					$data['transaction']['payment_data']['card_brand']
				),
				$data['transaction']['payment_data']['last_four']
			) : '';
			/* translators: %1$s: payment, %2$s: brand, %2$s: last four */
			$comments .= sprintf( __( 'Your order was successfully processed using %1$s %2$s', 'woocommerce-novalnet-gateway' ), $payment, $card_mask ) . PHP_EOL;
		}

		if ( ! empty( $data ['transaction']['tid'] ) ) {

			/* translators: %s: TID */
			$comments .= sprintf( __( 'Novalnet transaction ID: %s', 'woocommerce-novalnet-gateway' ), $data ['transaction']['tid'] );
			if ( ! empty( $data ['transaction'] ['test_mode'] ) ) {
				$comments .= PHP_EOL . __( 'Test order', 'woocommerce-novalnet-gateway' );
			}
		}
		if ( $is_error ) {
			$comments .= PHP_EOL . wc_novalnet_response_text( $data );
		}
		return $comments;
	}

	/**
	 * Form payment comments.
	 *
	 * @since 12.0.0
	 * @param array $data The comment data.
	 *
	 * @return string
	 */
	public function format_querystring_response( $data ) {

		foreach ( array(
			'tid'          => 'transaction',
			'payment_type' => 'transaction',
			'status'       => 'result',
			'status_text'  => 'result',
		) as $parameter => $category ) {
			if ( ! empty( $data [ $parameter ] ) ) {
				$data[ $category ][ $parameter ] = $data[ $parameter ];
			}
		}
		return $data;
	}

	/**
	 * Form payment comments.
	 *
	 * @since 12.0.0
	 * @param array $data The comment data.
	 *
	 * @return string
	 */
	public function form_nearest_store_comments( $data ) {

		$nearest_stores = $data['transaction']['nearest_stores'];
		$comments       = '';

		if ( ! empty( $data['transaction']['due_date'] ) ) {
			/* translators: %s: due_date */
			$comments .= PHP_EOL . sprintf( __( 'Slip expiry date : %s', 'woocommerce-novalnet-gateway' ), wc_novalnet_formatted_date( $data['transaction']['due_date'] ) );
		}
		$comments .= PHP_EOL . PHP_EOL . __( 'Store(s) near to you: ', 'woocommerce-novalnet-gateway' ) . PHP_EOL . PHP_EOL;

		foreach ( $nearest_stores as $nearest_store ) {
			$address = array();
			foreach ( array(
				'store_name'   => 'company',
				'street'       => 'address_1',
				'city'         => 'city',
				'zip'          => 'postcode',
				'country_code' => 'country',
			) as $nn_key => $wc_key ) {
				if ( ! empty( $nearest_store[ $nn_key ] ) ) {
					$address[ $wc_key ] = $nearest_store[ $nn_key ];
				}
			}
			$comments .= WC()->countries->get_formatted_address( $address, PHP_EOL );
			$comments .= PHP_EOL;
			$comments .= PHP_EOL;
		}
		return $comments;
	}

	/**
	 * Form Bank details comments.
	 *
	 * @since 12.0.0
	 * @param array $input     The input data.
	 * @param int   $wc_order_id The order id.
	 *
	 * @return string
	 */
	public function form_amount_transfer_comments( $input, $wc_order_id = false ) {

		$order_amount = $input ['transaction']['amount'];
		if ( ! empty( $input['instalment']['cycle_amount'] ) ) {
			$order_amount = $input ['instalment']['cycle_amount'];
		}
		if ( in_array( $input['transaction']['status'], array( 'CONFIRMED', 'PENDING' ), true ) && ! empty( $input ['transaction']['due_date'] ) ) {
			/* translators: %1$s: amount, %2$s: due date */
			$comments = PHP_EOL . PHP_EOL . sprintf( __( 'Please transfer the amount of %1$s to the following account on or before %2$s', 'woocommerce-novalnet-gateway' ), wc_novalnet_shop_amount_format( $order_amount ), wc_novalnet_formatted_date( $input ['transaction']['due_date'] ) ) . PHP_EOL . PHP_EOL;

			if ( ! empty( $input['instalment']['cycle_amount'] ) ) {
				/* translators: %1$s: amount, %2$s: due date */
				$comments = PHP_EOL . PHP_EOL . sprintf( __( 'Please transfer the instalment cycle amount of %1$s to the following account on or before %2$s', 'woocommerce-novalnet-gateway' ), wc_novalnet_shop_amount_format( $order_amount ), wc_novalnet_formatted_date( $input ['transaction']['due_date'] ) ) . PHP_EOL . PHP_EOL;
			}
		} else {
			/* translators: %s: amount*/
			$comments = PHP_EOL . PHP_EOL . sprintf( __( 'Please transfer the amount of %1$s to the following account.', 'woocommerce-novalnet-gateway' ), wc_novalnet_shop_amount_format( $order_amount ) ) . PHP_EOL . PHP_EOL;

			if ( ! empty( $input['instalment']['cycle_amount'] ) ) {
				/* translators: %s: amount*/
				$comments = PHP_EOL . PHP_EOL . sprintf( __( 'Please transfer the instalment cycle amount of %1$s to the following account.', 'woocommerce-novalnet-gateway' ), wc_novalnet_shop_amount_format( $order_amount ) ) . PHP_EOL . PHP_EOL;
			}
		}

		foreach ( array(
			/* translators: %s: account_holder */
			'account_holder' => __( 'Account holder: %s', 'woocommerce-novalnet-gateway' ),

			/* translators: %s: bank_name */
			'bank_name'      => __( 'Bank: %s', 'woocommerce-novalnet-gateway' ),

			/* translators: %s: bank_place */
			'bank_place'     => __( 'Place: %s', 'woocommerce-novalnet-gateway' ),

			/* translators: %s: iban */
			'iban'           => __( 'IBAN: %s', 'woocommerce-novalnet-gateway' ),

			/* translators: %s: bic */
			'bic'            => __( 'BIC: %s', 'woocommerce-novalnet-gateway' ),
		) as $key => $text ) {
			if ( ! empty( $input ['transaction']['bank_details'][ $key ] ) ) {
				$comments .= sprintf( $text, $input ['transaction']['bank_details'][ $key ] ) . PHP_EOL;
			}
		}

		$comments .= PHP_EOL . __( 'Please use any of the following payment references when transferring the amount. This is necessary to match it with your corresponding order', 'woocommerce-novalnet-gateway' );

		/* translators: %s:  TID */
		$comments .= PHP_EOL . sprintf( __( 'Payment Reference 1: TID %s', 'woocommerce-novalnet-gateway' ), $input ['transaction']['tid'] );

		// Form reference comments.
		if ( ! empty( $input ['transaction']['invoice_ref'] ) ) {
			/* translators: %s: invoice_ref */
			$comments .= PHP_EOL . sprintf( __( 'Payment Reference 2: %s', 'woocommerce-novalnet-gateway' ), $input ['transaction']['invoice_ref'] );
		} elseif ( ! empty( $wc_order_id ) && ! empty( $input ['merchant']['project'] ) ) {
			/* translators: %s: project name */
			$comments .= PHP_EOL . sprintf( __( 'Payment Reference 2: %s', 'woocommerce-novalnet-gateway' ), 'BNR-' . $input ['merchant']['project'] . '-' . $wc_order_id );
		}

		return wc_novalnet_format_text( $comments );
	}

	/**
	 * Update transaction order comments in
	 * order and customer note.
	 *
	 * @since 12.0.0
	 * @param WC_Order $wc_order             The order object.
	 * @param string   $transaction_comments The transaction comments.
	 * @param string   $type                 The comment type.
	 * @param boolean  $notify_customer      Notify to the customer.
	 * @param boolean  $set_customer_note    Customer note falg.
	 * @param string   $customer_note        The customer given note.
	 */
	public function update_comments( $wc_order, $transaction_comments, $type = 'note', $notify_customer = true, $set_customer_note = false, $customer_note = '' ){
		$wc_order->add_order_note( wc_novalnet_format_text( $transaction_comments ), $notify_customer );
		if ( 'transaction_info' === $type && $set_customer_note ) {
			$customer_note = ( ! empty( $customer_note ) ) ? wc_novalnet_format_text( $customer_note . PHP_EOL . $transaction_comments ) : wc_novalnet_format_text( $transaction_comments );
			$wc_order->set_customer_note( $customer_note );
			$wc_order->save();
		}
	}

	/**
	 * Forms the customer payment parameters.
	 *
	 * @since 12.0.0
	 * @param WC_Order $order The order object.
	 *
	 * @return array
	 */
	public function get_customer_data( $order ) {

		$customer = array();

		// Get billing address.
		list($billing_customer, $billing_address) = $this->get_address( $order, 'billing' );

		// Add customer details.
		if ( ! empty( $billing_customer ) ) {
			$customer = $billing_customer;
		}
		$customer ['customer_ip'] = wc_novalnet_get_ip_address();
		$customer ['customer_no'] = $order->get_user_id();

		// Add billing address.
		if ( ! empty( $billing_address ) ) {
			$customer ['billing'] = $billing_address;
		}

		// Get shipping details.
		list($shipping_customer, $shipping_address) = $this->get_address( $order, 'shipping' );

		// Add shipping details.
		if ( ! empty( $shipping_address['street'] ) && ! empty( $shipping_address['city'] ) && ! empty( $shipping_address['zip'] ) && ! empty( $shipping_address['country_code'] ) ) {
			if ( $billing_address === $shipping_address ) {
				$customer ['shipping'] ['same_as_billing'] = 1;
			} else {
				$customer ['shipping'] = $shipping_address;
				if ( ! empty( $shipping_customer ) ) {
					$customer ['shipping'] = array_merge( $customer ['shipping'], $shipping_customer );
				}
			}
		}

		return $customer;
	}

	/**
	 * Get Address data.
	 *
	 * @since 12.0.0
	 * @param WC_Order $order The order object.
	 * @param string   $type billing / shipping.
	 *
	 * @return array
	 */
	public function get_address( $order, $type = 'billing' ) {

		$address  = array();
		$customer = array();
		if ( ! empty( $order ) ) {
			if ( is_array( $order ) ) {
				$prefix = '';
				if ( 'shipping' === $type ) {
					$prefix = 'shipping_';
				}
				$address = array(
					'street'       => $order [ $prefix . 'address_1' ],
					'city'         => $order [ $prefix . 'city' ],
					'zip'          => $order [ $prefix . 'postcode' ],
					'country_code' => $order [ $prefix . 'country' ],
				);
				if ( ! empty( $order [ $prefix . 'address_2' ] ) ) {
					$address ['street'] .= ' ' . $order [ $prefix . 'address_2' ];
				}
				return array( $customer, $address );
			}
			$wc_address = $order->get_address( $type );
			list($customer ['first_name'], $customer ['last_name']) = wc_novalnet_retrieve_name(
				array(
					$wc_address ['first_name'],
					$wc_address ['last_name'],
				)
			);

			if ( 'billing' === $type ) {
				if ( ! empty( $wc_address ['gender'] ) ) {
					$customer ['gender'] = strtoupper( substr( $wc_address ['gender'], 0, 1 ) );
				}
				if ( ! empty( $wc_address ['email'] ) ) {
					$customer ['email'] = $wc_address ['email'];
				}
			}
			$address ['street']       = $wc_address ['address_1'] . ' ' . $wc_address ['address_2'];
			$address ['city']         = $wc_address ['city'];
			$address ['zip']          = $wc_address ['postcode'];
			$address ['country_code'] = $wc_address ['country'];
			if ( ! empty( $wc_address['state'] ) && isset( WC()->countries->get_states( $wc_address['country'] )[ $wc_address['state'] ] ) ) {
				$address ['state'] = WC()->countries->get_states( $wc_address['country'] )[ $wc_address['state'] ];
			}
			if ( ! empty( $wc_address ['company'] ) ) {
				$address ['company'] = $wc_address ['company'];
			}
			if ( ! empty( $wc_address ['phone'] ) ) {
				$customer ['tel'] = $wc_address ['phone'];
			}
		}
		return array( $customer, $address );
	}

	/**
	 * Assign post values in session.
	 *
	 * @since 12.0.0
	 *
	 * @param string $payment_type    The payment ID.
	 * @param array  $post_array The post data.
	 */
	public function set_post_value_session( $payment_type, $post_array ) {

		$session = WC()->session->get( $payment_type );

		// Set post values in session.
		foreach ( $post_array as $value ) {
			$session_value = '';
			if ( ! empty( $session [ $value ] ) ) {
				$session_value = sanitize_text_field( trim( $session [ $value ] ) );
			}

			$session [ $value ] = $session_value;
			if ( isset( novalnet()->request [ $value ] ) && '' !== novalnet()->request [ $value ] ) {
				$session [ $value ] = sanitize_text_field( trim( novalnet()->request [ $value ] ) );
			}
		}

		// Storing the values in session.
		WC()->session->set( $payment_type, $session );
		return WC()->session->get( $payment_type );

	}

	/**
	 * Get post parent id
	 *
	 * @since 12.0.0
	 * @param WC_Order $wc_order The subscription order object.
	 *
	 * @return int
	 */
	public function get_order_post_id( $wc_order ) {
		$parent_id = $wc_order->get_parent_id();
		if ( ! empty( $parent_id ) ) {
			return $parent_id;
		}
		return $wc_order->get_id();
	}

	/**
	 * Check and maintain debug log if enabled
	 *
	 * @param string $message     Message to be logged.
	 * @param int    $wc_order_id The post ID value.
	 * @param bool   $override_log Flag to override the debug log.
	 *
	 * @since 12.0.0
	 */
	public function debug( $message, $wc_order_id = '', $override_log = false ) {
		global $current_user;

		if ( 'yes' === WC_Novalnet_Configuration::get_global_settings( 'debug_log' ) || true === $override_log ) {
			if ( ! empty( $wc_order_id ) ) {
				$message = "###$wc_order_id### $message";
			}
			if ( ! empty( $current_user->user_login ) ) {
				$message .= " - $current_user->user_login";
			}
			wc_novalnet_logger()->add( 'woocommerce-novalnet-gateway', $message, WC_Log_Levels::DEBUG );
		}
	}

	/**
	 * Log error
	 *
	 * @param string $message  Message to be logged.
	 *
	 * @since 12.0.0
	 */
	public function log_error( $message ) {
		global $current_user;

		if ( ! empty( $current_user->user_login ) ) {
			$message .= " - $current_user->user_login";
		}
		wc_novalnet_logger()->add( 'woocommerce-novalnet-gateway-error', $message, WC_Log_Levels::CRITICAL );
	}

	/**
	 * Get action URL
	 *
	 * @param string $action the action.
	 *
	 * @since 12.0.0
	 */
	public function get_action_endpoint( $action = '' ) {
		return $this->endpoint . str_replace( '_', '/', $action );
	}

	/**
	 * Status mapper
	 *
	 * @param string $status_code  The status code.
	 *
	 * @since 12.0.0
	 */
	public function status_mapper( &$status_code ) {

		if ( WC_Novalnet_Validation::is_valid_digit( $status_code ) ) {
			foreach ( $this->statuses as $status => $status_codes ) {
				if ( in_array( $status_code, $status_codes, true ) ) {
					$status_code = $status;
					break;
				}
			}
		}
	}

	/**
	 * Load the template
	 *
	 * @since 12.0.0
	 * @param string $file_name The file name.
	 * @param array  $contents The contents.
	 * @param array  $payment_type The payment type.
	 * @param string $type The name of the contents array.
	 */
	public function load_template( $file_name, $contents, $payment_type = '', $type = 'checkout' ) {

		wc_get_template(
			$file_name,
			array(
				'contents'     => $contents,
				'payment_type' => $payment_type,
			),
			'',
			dirname( dirname( __FILE__ ) ) . "/templates/$type/"
		);
	}

	/**
	 * Remove unsupported feature
	 *
	 * @since 12.0.0
	 * @param string $supports The supported feature.
	 * @param array  $feature The feature need to be unset.
	 */
	public function unset_supports( &$supports, $feature = '' ) {

		foreach ( $supports as $key => $support ) {
			if ( $support === $feature ) {
				unset( $supports[ $key ] );
			}
		}
	}

	/**
	 * Check current page is pay for order.
	 *
	 * @since 12.5.5
	 */
	public function get_pay_order() {
		global $wp;
		// If paying from order, we need to get total from order not cart.
		if ( isset( $_GET['pay_for_order'] ) && ! empty( $_GET['key'] ) && ! empty( $wp->query_vars ) ) { // @codingStandardsIgnoreLine.
			return wc_get_order( wc_clean( $wp->query_vars['order-pay'] ) );
		}
		return false;
	}
	/**
	 * Form system version details for Novalnet server parameter.
	 *
	 * @since 12.5.5
	 */
	public function get_system_version_string() {
		if ( class_exists( 'WC_Subscriptions' ) ) {
			return get_bloginfo( 'version' ) . '-' . WOOCOMMERCE_VERSION . '-' . WC_Subscriptions::$version . '-NN' . NOVALNET_VERSION;
		} else {
			return get_bloginfo( 'version' ) . '-' . WOOCOMMERCE_VERSION . '-NN' . NOVALNET_VERSION;
		}
	}

	/**
	 * Get BIC allowed countries
	 *
	 * @since 12.5.6
	 */
	public function get_bic_allowed_countries() {
		return $this->bic_allowed_countries;
	}

	/**
	 * Update the amount booked for an order that is processed as a zero amount transaction.
	 *
	 * @since 12.6.0
	 *
	 * @param int      $wc_order_id      The order id.
	 * @param WC_Order $wc_order         The order object.
	 * @param array    $booking_response The payment booking transaction response.
	 * @param array    $additional_info  The additional info.
	 */
	public function update_payment_booking( $wc_order_id, $wc_order, $booking_response, $additional_info ) {
		if ( is_object( $wc_order ) && ! empty( $booking_response ) ) {
			delete_post_meta( $wc_order_id, '_novalnet_booking_ref_order' );
			$additional_info['is_payment_booked'] = 'yes';
			novalnet()->db()->update(
				array(
					'tid'             => $booking_response ['transaction']['tid'],
					'amount'          => $booking_response ['transaction']['amount'],
					'callback_amount' => ( in_array( $booking_response['transaction']['status'], array( 'PENDING', 'ON_HOLD' ), true ) ) ? 0 : $booking_response ['transaction']['amount'],
					'gateway_status'  => $booking_response ['transaction']['status'],
					'additional_info' => wc_novalnet_serialize_data( $additional_info ),
				),
				array(
					'order_no' => $wc_order_id,
				)
			);
			$wc_order->set_transaction_id( $booking_response ['transaction']['tid'] );
			$wc_order->save();
			/* translators: %1$s: amount, %2$s: TID*/
			$message = sprintf( __( 'Your order has been booked with the amount of %1$s. Your new TID for the booked amount: %2$s', 'woocommerce-novalnet-gateway' ), wc_novalnet_shop_amount_format( $booking_response ['transaction']['amount'] ), $booking_response ['transaction']['tid'] );
			novalnet()->helper()->update_comments( $wc_order, wc_novalnet_format_text( $message ) );
			return true;
		}
		return false;
	}

	/**
	 * Prepares the data to be inserted into the Novalnet Transaction Details table.
	 *
	 * @since 12.6.1
	 *
	 * @param WC_Order $wc_order        The order object.
	 * @param string   $payment_id      The order payment id.
	 * @param array    $server_response Payment gateway reponse.
	 *
	 * @return array $insert_data
	 */
	public function prepare_transaction_table_data( $wc_order, $payment_id, $server_response ) {
		$insert_data = array(
			'order_no'       => $wc_order->get_id(),
			'tid'            => $server_response['transaction']['tid'],
			'currency'       => get_woocommerce_currency(),
			'gateway_status' => $server_response['transaction']['status'],
			'payment_type'   => $payment_id,
			'amount'         => wc_novalnet_formatted_amount( $wc_order->get_total() ),
		);

		if ( ! empty( $server_response ['subscription']['subs_id'] ) ) {
			$insert_data['subs_id'] = $server_response ['subscription']['subs_id'];
		}

		$insert_data['callback_amount'] = $insert_data ['amount'];

		if ( novalnet()->get_supports( 'instalment', $payment_id ) ) {
			$insert_data ['additional_info'] = apply_filters( 'novalnet_store_instalment_data', $server_response );
		}
		if ( ( novalnet()->get_supports( 'pay_later', $payment_id ) || 'novalnet_guaranteed_invoice' === $payment_id || 'novalnet_instalment_invoice' === $payment_id ) ) {
			if ( ! empty( $insert_data ['additional_info'] ) ) {
				$insert_data ['additional_info'] = wc_novalnet_serialize_data( wc_novalnet_unserialize_data( $insert_data ['additional_info'] ) + $server_response['transaction']['bank_details'] );
			} elseif ( ! empty( $server_response['transaction']['bank_details'] ) ) {
				$insert_data ['additional_info'] = wc_novalnet_serialize_data( $server_response['transaction']['bank_details'] );
			} elseif ( ! empty( $server_response['transaction']['nearest_stores'] ) ) {
				$insert_data ['additional_info'] = wc_novalnet_serialize_data( $server_response['transaction']['nearest_stores'] );
			}
		}

		if ( novalnet()->get_supports( 'zero_amount_booking', $payment_id ) && '1' === get_post_meta( $wc_order->get_id(), '_novalnet_booking_ref_order', 1 ) ) {
			$booking_data = array(
				'nn_booking_ref_token' => $server_response['transaction']['payment_data']['token'],
				'is_payment_booked'    => 'no',
			);
			if ( ! empty( $insert_data ['additional_info'] ) ) {
				$insert_data ['additional_info'] = wc_novalnet_serialize_data( wc_novalnet_unserialize_data( $insert_data ['additional_info'] ) + $booking_data );
			} else {
				$insert_data ['additional_info'] = wc_novalnet_serialize_data( $booking_data );
			}
			$this->update_comments( $wc_order, wc_novalnet_format_text( __( 'This order processed as a zero amount booking', 'woocommerce-novalnet-gateway' ) ) );
		}

		update_post_meta( $wc_order->get_id(), '_novalnet_gateway_status', $server_response ['transaction'] ['status'] );

		if ( in_array( $insert_data['gateway_status'], array( 'PENDING', 'ON_HOLD' ), true ) || ! WC_Novalnet_Validation::is_success_status( $server_response ) ) {
			$insert_data ['callback_amount'] = 0;
		}

		return $insert_data;
	}


	/**
	 * Inserts the change payment transaction details into the Novalnet transaction table.
	 *
	 * @since 12.6.1
	 *
	 * @param WC_Subscription $subscription    Subscription object.
	 * @param string          $payment_type    Changed payment id.
	 * @param array           $server_response Payment response.
	 */
	public function insert_change_payment_transaction_details( $subscription, $payment_type, $server_response ) {
		$is_renew_subscription = 0;
		if ( isset( $server_response['transaction']['order_no'] ) ) {
			// Check the payment method changed while renewing the subscription.
			$is_renew_subscription = get_post_meta( $server_response['transaction']['order_no'], '_novalnet_renew_subscription', true );
		}

		if ( empty( $is_renew_subscription ) && 1 !== (int) $is_renew_subscription ) { // Renewal order transaction details are stored by transaction success method.
			$insert_data = array(
				'order_no'     => $subscription->get_id(),
				'tid'          => $server_response['transaction']['tid'],
				'currency'     => get_woocommerce_currency(),
				'payment_type' => $payment_type,
				'amount'       => $server_response['transaction']['amount'],
				'subs_id'      => ! empty( $server_response ['subscription'] ['subs_id'] ) ? $server_response ['subscription'] ['subs_id'] : '',
			);

			$insert_data['callback_amount'] = $insert_data['amount'];

			if ( ! empty( $server_response ['transaction']['status'] ) ) {
				update_post_meta( $subscription->get_id(), '_novalnet_gateway_status', $server_response ['transaction']['status'] );
				$insert_data ['gateway_status'] = $server_response ['transaction']['status'];
				if ( 'PENDING' === $server_response['transaction']['status'] ) {
					$insert_data['callback_amount'] = '0';
				}
			}

			// Insert the transaction details.
			novalnet()->db()->insert( $insert_data, 'novalnet_transaction_detail' );
		}
	}

	/**
	 * Checks that the renewal subscription is active with collection.
	 *
	 * @since 12.6.1
	 *
	 * @param array $server_response Payment gateway reponse.
	 *
	 * @return boolean
	 */
	public function is_subs_renewal_active_with_collection( $server_response ) {
		if (
			( isset( $server_response['event']['type'] ) && 'RENEWAL' === $server_response['event']['type'] ) &&
			( isset( $server_response['subscription']['status'] ) && 'ACTIVE_WITH_COLLECTION' === $server_response['subscription']['status'] )
		) {
			return true;
		}
		return false;
	}

	/**
	 * Get Novalnet subscription TID for woocommerce subscription
	 *
	 * @since 12.6.1
	 *
	 * @param strint $parent_order_id Subscription parent order ID.
	 * @param strint $subscription_id Subscription order ID.
	 *
	 * @return string
	 */
	public function get_novalnet_subscription_tid( $parent_order_id, $subscription_id ) {
		$tid = novalnet()->db()->get_subs_data_by_order_id( $parent_order_id, $subscription_id, 'tid', false );
		if ( empty( $tid ) ) {
			$tid = novalnet()->db()->get_entry_by_order_id( $parent_order_id, 'tid' );
		}
		return $tid;
	}

	/**
	 * Checks that the Novalnet-based subscription entry exists in the table for the subscription.
	 *
	 * @since 12.6.1
	 *
	 * @param WC_Subscription $subscription The WooCommerce subscription object.
	 *
	 * @return boolean
	 */
	public function is_novalnet_based_subs_exist( $subscription ) {
		$is_subscription = apply_filters( 'novalnet_check_is_subscription', $subscription );
		$tid             = ( $is_subscription ) ? $this->get_novalnet_subscription_tid( $subscription->get_parent_id(), $subscription->get_id() ) : '';
		if ( ! empty( $tid ) ) {
			$is_shop_scheduled = novalnet()->db()->get_subs_data_by_order_id( $subscription->get_parent_id(), $subscription->get_id(), 'shop_based_subs' );
			$nn_subs_id        = novalnet()->db()->get_subs_data_by_order_id( $subscription->get_parent_id(), $subscription->get_id(), 'subs_id' );
			if ( empty( $is_shop_scheduled ) && ! empty( $nn_subs_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks that the Shop-based subscription entry exists in the table for the subscription.
	 *
	 * @since 12.6.1
	 *
	 * @param WC_Subscription $subscription The WooCommerce subscription object.
	 *
	 * @return boolean
	 */
	public function is_shop_based_subs_exist( $subscription ) {
		$is_subscription = apply_filters( 'novalnet_check_is_subscription', $subscription );
		$tid             = ( $is_subscription ) ? $this->get_novalnet_subscription_tid( $subscription->get_parent_id(), $subscription->get_id() ) : '';
		if ( ! empty( $tid ) ) {
			$is_shop_scheduled = novalnet()->db()->get_subs_data_by_order_id( $subscription->get_parent_id(), $subscription->get_id(), 'shop_based_subs' );
			$nn_subs_id        = novalnet()->db()->get_subs_data_by_order_id( $subscription->get_parent_id(), $subscription->get_id(), 'subs_id' );
			if ( ! empty( $is_shop_scheduled ) && empty( $nn_subs_id ) ) {
				return true;
			}
		}
		return false;
	}
}
