<?php
/**
 * Gateway class.
 *
 * @package WC_Gateway_Amazon_Pay
 */

/**
 * Implement payment method for Amazon Pay.
 */
class WC_Gateway_Amazon_Payments_Advanced extends WC_Gateway_Amazon_Payments_Advanced_Abstract {

	protected $checkout_session;

	protected $current_refund;

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();

		// Init Handlers
		add_action( 'wp_loaded', array( $this, 'init_handlers' ), 11 );
		add_action( 'woocommerce_create_refund', array( $this, 'current_refund_set' ) );
	}

	/**
	 * Amazon Pay is available if the following conditions are met (on top of
	 * WC_Payment_Gateway::is_available).
	 *
	 * 1) Gateway enabled
	 * 2) Correctly setup
	 * 2) In checkout pay page.
	 *
	 * @return bool
	 */
	public function is_available() {
		$is_available = parent::is_available() && ! empty( $this->settings['merchant_id'] );

		if ( ! WC_Amazon_Payments_Advanced_API::is_region_supports_shop_currency() ) { // TODO: Check with multicurrency implementation
			$is_available = false;
		}

		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) { // TODO: Implement order pay view.
			$is_available = false;
		}

		return apply_filters( 'woocommerce_amazon_pa_is_gateway_available', $is_available );
	}

	/**
	 * Load handlers for cart and orders after WC Cart is loaded.
	 */
	public function init_handlers() {
		$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();

		if ( ! isset( $available_gateways[ $this->id ] ) ) {
			return;
		}

		if ( ! apply_filters( 'woocommerce_amazon_payments_init', true ) ) {
			return;
		}

		add_action( 'template_redirect', array( $this, 'maybe_handle_apa_action' ) );

		// Scripts
		add_action( 'wp_enqueue_scripts', array( $this, 'scripts' ) );

		// Checkout.
		add_action( 'woocommerce_checkout_init', array( $this, 'checkout_init' ) );
		add_filter( 'woocommerce_checkout_posted_data', array( $this, 'use_checkout_session_data' ) );
		add_filter( 'woocommerce_checkout_get_value', array( $this, 'use_checkout_session_data_single' ), 10, 2 );

		// Cart
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'display_amazon_pay_button_separator_html' ), 20 );
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'checkout_button' ), 25 );
		add_action( 'woocommerce_before_cart_totals', array( $this, 'update_js' ) );
	}

	/**
	 * Add scripts
	 */
	public function scripts() {

		$js_suffix = '.min.js';
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			$js_suffix = '.js';
		}

		wp_register_style( 'amazon_payments_advanced', wc_apa()->plugin_url . '/assets/css/style.css', array(), wc_apa()->version );
		wp_register_script( 'amazon_payments_advanced_checkout', $this->get_region_script(), array(), wc_apa()->version, true );
		wp_register_script( 'amazon_payments_advanced', wc_apa()->plugin_url . '/assets/js/amazon-wc-checkout' . $js_suffix, array(), wc_apa()->version, true );

		$params = array(
			'ajax_url'                       => admin_url( 'admin-ajax.php' ),
			'create_checkout_session_config' => WC_Amazon_Payments_Advanced_API::get_create_checkout_session_config(),
			'button_color'                   => $this->settings['button_color'],
			'placement'                      => $this->get_current_placement(),
			'action'                         => $this->get_current_cart_action(),
			'sandbox'                        => 'yes' === $this->settings['sandbox'],
			'merchant_id'                    => $this->settings['merchant_id'],
			'shipping_title'                 => esc_html__( 'Shipping details', 'woocommerce' ),
			'checkout_session_id'            => $this->get_checkout_session_id(),
			'button_language'                => $this->settings['button_language'],
			'ledger_currency'                => $this->get_ledger_currency(), // TODO: Implement multicurrency
		);

		wp_localize_script( 'amazon_payments_advanced', 'amazon_payments_advanced', $params );

		$enqueue_scripts = is_cart() || is_checkout() || is_checkout_pay_page();

		if ( ! apply_filters( 'woocommerce_amazon_pa_enqueue_scripts', $enqueue_scripts ) ) {
			return;
		}

		$this->enqueue_scripts();

	}

	public function enqueue_scripts() {
		wp_enqueue_style( 'amazon_payments_advanced' );
		wp_enqueue_script( 'amazon_payments_advanced_checkout' );
		wp_enqueue_script( 'amazon_payments_advanced' );
	}

	protected function get_current_placement() {
		if ( is_cart() ) {
			return 'Cart';
		}

		if ( is_checkout() || is_checkout_pay_page() ) {
			return 'Checkout';
		}

		return 'Other';
	}

	protected function get_region_script() {
		$region = WC_Amazon_Payments_Advanced_API::get_region();

		$url = false;
		switch ( strtolower( $region ) ) {
			case 'us':
				$url = 'https://static-na.payments-amazon.com/checkout.js';
				break;
			case 'gb':
			case 'eu':
				$url = 'https://static-eu.payments-amazon.com/checkout.js';
				break;
			case 'jp':
				$url = 'https://static-fe.payments-amazon.com/checkout.js';
				break;
		}

		return $url;
	}

	protected function get_ledger_currency() {
		$region = WC_Amazon_Payments_Advanced_API::get_region();

		switch ( strtolower( $region ) ) {
			case 'us':
				return 'USD';
			case 'gb':
				return 'GBP';
			case 'eu':
				return 'EUR';
			case 'jp':
				return 'JPY';
		}

		return false;
	}

	/**
	 * Display payment request button separator.
	 *
	 * @since 2.0.0
	 */
	public function display_amazon_pay_button_separator_html() {
		?>
		<p class="wc-apa-button-separator" style="margin:1.5em 0;text-align:center;display:none;">&mdash; <?php esc_html_e( 'OR', 'woocommerce-gateway-amazon-payments-advanced' ); ?> &mdash;</p>
		<?php
	}

	public function maybe_create_index_table() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;

		$collate = '';

		if ( $wpdb->has_cap( 'collation' ) ) {
			$collate = $wpdb->get_charset_collate();
		}

		$query = "
		CREATE TABLE {$wpdb->prefix}woocommerce_amazon_buyer_index (
			buyer_id varchar(100) NOT NULL,
			customer_id bigint(20) NOT NULL,
			PRIMARY KEY (buyer_id,customer_id),
			UNIQUE KEY customer_id (customer_id)
		  ) $collate;";

		$queries = dbDelta( $query, false );

		if ( ! empty( $queries ) ) {
			dbDelta( $query );
		}
	}

	public function get_customer_id_from_buyer( $buyer_id ) {
		global $wpdb;
		$this->maybe_create_index_table();

		$customer_id = $wpdb->get_var( $wpdb->prepare( "SELECT customer_id FROM {$wpdb->prefix}woocommerce_amazon_buyer_index WHERE buyer_id = %s", $buyer_id ) );

		return ! empty( $customer_id ) ? intval( $customer_id ) : false;
	}

	public function set_customer_id_for_buyer( $buyer_id, $customer_id ) {
		global $wpdb;
		$this->maybe_create_index_table();

		$inserted = $wpdb->insert(
			"{$wpdb->prefix}woocommerce_amazon_buyer_index",
			array(
				'buyer_id'    => $buyer_id,
				'customer_id' => $customer_id,
			)
		);

		if ( ! $inserted ) {
			return false;
		}

		return true;
	}

	public function signal_account_hijack() {
		add_filter( 'woocommerce_checkout_customer_id', array( $this, 'handle_account_registration' ) );
	}

	public function handle_account_registration( $customer_id ) {
		// unhook ourselves, since we only need this after checkout started, not every time
		remove_filter( 'woocommerce_checkout_customer_id', array( $this, 'handle_account_registration' ) );

		$checkout = WC()->checkout();
		$data     = $checkout->get_posted_data();

		if ( $customer_id && ! empty( $data['amazon_link'] ) ) {
			$checkout_session = $this->get_checkout_session();
			$buyer_id = $checkout_session->buyer->buyerId;

			$buyer_user_id = $this->get_customer_id_from_buyer( $buyer_id );
			if ( ! $buyer_user_id ) {
				$this->set_customer_id_for_buyer( $buyer_id, $customer_id );
			}
		}

		if ( $customer_id ) { // Already registered, or logged in. Return normally
			return $customer_id;
		}

		// FROM: WC_Checkout->process_customer
		if ( ! is_user_logged_in() && ( $checkout->is_registration_required() || ! empty( $data['createaccount'] ) ) ) {
			$checkout_session = $this->get_checkout_session();
			$buyer_id = $checkout_session->buyer->buyerId;
			$buyer_email = $checkout_session->buyer->email;
			$buyer_user_id = $this->get_customer_id_from_buyer( $buyer_id );

			if ( isset( $data['amazon_validate'] ) ) {
				if ( $buyer_user_id ) {
					return; // We shouldn't be here anyways
				}
				$user_id = email_exists( $buyer_email );
				if ( ! $user_id ) {
					return; // We shouldn't be here anyways
				}

				$code = get_user_meta( $user_id, 'wc_apa_ownership_verification_code', true );
				if ( empty( $code ) ) {
					throw new Exception( __( 'You have to send yourself a code before attempting to claim an account. If you want, you can continue as guest.', 'woocommerce-gateway-amazon-payments-advanced' ) );
				}

				if ( empty( $data['amazon_validate'] ) ) {
					throw new Exception( __( 'You did not enter the code to validate your account. If you want, you can continue as guest.', 'woocommerce-gateway-amazon-payments-advanced' ) );
				}

				if ( $code !== $data['amazon_validate'] ) { // TODO: Rotate code after 5 failed attempts
					throw new Exception( __( 'The code you entered did not match. Try again, or continue as guest.', 'woocommerce-gateway-amazon-payments-advanced' ) );
				}

				$customer_id = $user_id;

				$this->set_customer_id_for_buyer( $buyer_id, $customer_id );

				delete_user_meta( $user_id, 'wc_apa_ownership_verification_code' );
			}

			if ( ! $customer_id ) {
				$username    = ! empty( $data['account_username'] ) ? $data['account_username'] : '';
				$password    = ! empty( $data['account_password'] ) ? $data['account_password'] : '';

				$customer_id = wc_create_new_customer(
					$data['billing_email'],
					$username,
					$password,
					array(
						'first_name' => ! empty( $data['billing_first_name'] ) ? $data['billing_first_name'] : '',
						'last_name'  => ! empty( $data['billing_last_name'] ) ? $data['billing_last_name'] : '',
					)
				);

				if ( is_wp_error( $customer_id ) ) {
					throw new Exception( $customer_id->get_error_message() );
				}

				$this->set_customer_id_for_buyer( $buyer_id, $customer_id );
			}

			wc_set_customer_auth_cookie( $customer_id );

			// As we are now logged in, checkout will need to refresh to show logged in data.
			WC()->session->set( 'reload_checkout', true );

			// Also, recalculate cart totals to reveal any role-based discounts that were unavailable before registering.
			WC()->cart->calculate_totals();
		}

		return $customer_id;
	}

	public function get_amazon_validate_ownership_url() {
		return add_query_arg(
			array(
				'amazon_payments_advanced'  => 'true',
				'amazon_validate_ownership' => 'true',
			),
			get_permalink( wc_get_page_id( 'checkout' ) )
		);
	}

	public function print_validate_button( $html, $key, $field, $value ) {
		$html  = '<p class="form-row" id="amazon_validate_notice_field" data-priority="">';
		$html .= __( 'An account with your Amazon Pay email address exists already. Is that you?', 'woocommerce-gateway-amazon-payments-advanced' );
		$html .= ' ';
		$html .= sprintf( __( 'Click %shere%s to send a code to your email, which will help you validate the ownership of the account.', 'woocommerce-gateway-amazon-payments-advanced' ), '<a href="' . esc_url( $this->get_amazon_validate_ownership_url() ) . '" class="wc-apa-send-confirm-ownership-code" target="_blank">', '</a>' );
		$html .= '</p>';
		return $html;
	}

	public function checkout_init( $checkout ) {

		/**
		 * Make sure this is checkout initiated from front-end where cart exsits.
		 *
		 * @see https://github.com/woocommerce/woocommerce-gateway-amazon-payments-advanced/issues/238
		 */
		if ( ! WC()->cart ) {
			return;
		}

		add_action( 'woocommerce_before_checkout_form', array( $this, 'checkout_message' ), 5 );
		add_action( 'before_woocommerce_pay', array( $this, 'checkout_message' ), 5 );

		if ( ! $this->is_logged_in() ) {
			add_filter( 'woocommerce_available_payment_gateways', array( $this, 'remove_amazon_gateway' ) );
			return;
		}

		add_action( 'woocommerce_checkout_process', array( $this, 'signal_account_hijack' ) );
		add_filter( 'woocommerce_form_field_amazon_validate_notice', array( $this, 'print_validate_button' ), 10, 4 );

		// If all prerequisites are meet to be an amazon checkout.
		do_action( 'woocommerce_amazon_checkout_init' );

		add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'display_amazon_customer_info' ) );

		// The default checkout form uses the billing email for new account creation
		// Let's hijack that field for the Amazon-based checkout.
		if ( apply_filters( 'woocommerce_pa_hijack_checkout_fields', true ) ) {
			$this->hijack_checkout_fields( $checkout );
		}
	}

	/**
	 * Checkout Message
	 */
	public function checkout_message() {
		echo '<div class="wc-amazon-checkout-message wc-amazon-payments-advanced-populated">';

		if ( ! $this->is_logged_in() ) {
			echo '<div class="woocommerce-info info wc-amazon-payments-advanced-info">' . $this->checkout_button( false ) . ' ' . apply_filters( 'woocommerce_amazon_pa_checkout_message', __( 'Have an Amazon account?', 'woocommerce-gateway-amazon-payments-advanced' ) ) . '</div>';
		} else {
			$this->logout_checkout_message();
		}

		echo '</div>';

	}

	public function logout_checkout_message() {
		$logout_url      = $this->get_amazon_logout_url();
		$logout_msg_html = '<div class="woocommerce-info info">' . apply_filters( 'woocommerce_amazon_pa_checkout_logout_message', __( 'You\'re logged in with your Amazon Account.', 'woocommerce-gateway-amazon-payments-advanced' ) ) . ' <a href="' . esc_url( $logout_url ) . '" id="amazon-logout">' . __( 'Log out &raquo;', 'woocommerce-gateway-amazon-payments-advanced' ) . '</a></div>';
		echo apply_filters( 'woocommerce_amazon_payments_logout_checkout_message_html', $logout_msg_html );
	}

	protected function do_logout() {
		unset( WC()->session->amazon_checkout_session_id );
	}

	protected function do_force_refresh( $reason ) {
		WC()->session->force_refresh_message = $reason;
	}

	protected function get_force_refresh() {
		return WC()->session->force_refresh_message;
	}

	protected function unset_force_refresh() {
		unset( WC()->session->force_refresh_message );
	}

	protected function need_to_force_refresh() {
		return ! is_null( WC()->session->force_refresh_message );
	}

	public function maybe_handle_apa_action() {

		if ( empty( $_GET['amazon_payments_advanced'] ) ) {
			return;
		}

		if ( is_null( WC()->session ) ) {
			return;
		}

		$redirect_url = get_permalink( wc_get_page_id( 'checkout' ) );

		if ( isset( $_GET['amazon_logout'] ) ) {
			$this->do_logout();
			wp_safe_redirect( $redirect_url );
			exit;
		}

		if ( isset( $_GET['amazon_login'] ) && isset( $_GET['amazonCheckoutSessionId'] ) ) {
			WC()->session->set( 'amazon_checkout_session_id', $_GET['amazonCheckoutSessionId'] );
			$this->unset_force_refresh();
			WC()->session->save_data();

			if ( ! is_user_logged_in() ) {
				$checkout_session = $this->get_checkout_session();
				$buyer_id = $checkout_session->buyer->buyerId;
				$buyer_email = $checkout_session->buyer->email;

				$buyer_user_id = $this->get_customer_id_from_buyer( $buyer_id );

				if ( ! empty( $buyer_user_id ) ) {
					wc_set_customer_auth_cookie( $buyer_user_id );
				}
			}

			wp_safe_redirect( $redirect_url );
			exit;
		}

		if ( isset( $_GET['amazon_return'] ) && isset( $_GET['amazonCheckoutSessionId'] ) ) {
			if ( $_GET['amazonCheckoutSessionId'] !== $this->get_checkout_session_id() ) {
				wc_add_notice( __( 'There was an error after returning from Amazon. Please try again.', 'woocommerce-gateway-amazon-payments-advanced' ), 'error' );
				wp_safe_redirect( $redirect_url );
				exit;
			}

			$this->handle_return();
			// If we didn't redirect and quit yet, lets force redirect to checkout.
			wp_safe_redirect( $redirect_url );
			exit;
		}

		if ( isset( $_GET['amazon_validate_ownership'] ) && $this->is_logged_in() ) {
			$checkout_session = $this->get_checkout_session();
			$buyer_id = $checkout_session->buyer->buyerId;
			$buyer_email = $checkout_session->buyer->email;

			$buyer_user_id = $this->get_customer_id_from_buyer( $buyer_id );

			if ( is_user_logged_in() ) {
				return; // We shouldn't be here anyways
			}

			if ( $buyer_user_id ) {
				return; // We shouldn't be here anyways
			}
			$user_id = email_exists( $buyer_email );
			if ( ! $user_id ) {
				return; // We shouldn't be here anyways
			}

			$subject = 'Link your account with Amazon Pay';
			$code    = wp_rand( 1111, 9999 );
			update_user_meta( $user_id, 'wc_apa_ownership_verification_code', $code );

			$mailer  = WC()->mailer();

			// Buffer.
			ob_start();

			do_action( 'woocommerce_email_header', $subject, null );

			?>
			<p><?php esc_html_e( 'It seems that someone is trying to make an order on your behalf. If that is the case, please use the code below to link your Amazon Pay account to your account upon checkout.', 'woocommerce' ); ?></p>
			<p style="vertical-align: top; word-wrap: break-word; -ms-hyphens: none; hyphens: none; border-collapse: collapse; -moz-hyphens: none; -webkit-hyphens: none; color: #222222; font-family: Lato, Arial, sans-serif; font-weight: normal; letter-spacing: 10px; line-height: 2; font-size: 48px; text-align: center;"><?php echo esc_html( $code ); ?></p>
			<p><?php esc_html_e( 'If this is not you, you can ignore this message.', 'woocommerce' ); ?></p>
			<?php

			do_action( 'woocommerce_email_footer', null );

			// Get contents.
			$message = ob_get_clean();

			$mailer->send( $buyer_email, wp_strip_all_tags( $subject ), $message );

			exit;
		}

	}

	protected function get_checkout_session_id() {
		return WC()->session->get( 'amazon_checkout_session_id', false );
	}

	protected function get_checkout_session( $force = false ) {
		if ( ! $force && ! is_null( $this->checkout_session ) ) {
			return $this->checkout_session;
		}

		$this->checkout_session = WC_Amazon_Payments_Advanced_API::get_checkout_session_data( $this->get_checkout_session_id() );
		return $this->checkout_session;
	}

	protected function is_logged_in() {
		if ( is_null( WC()->session ) ) {
			return false;
		}

		$session_id = $this->get_checkout_session_id();

		return ! empty( $session_id ) ? true : false;
	}

	public function hijack_checkout_fields( $checkout ) {
		$this->hijack_checkout_field_account( $checkout );

		// During an Amazon checkout, the standard billing and shipping fields need to be
		// "removed" so that we don't trigger a false negative on form validation -
		// they can be empty since we're using the Amazon widgets.

		// The following fields cannot be optional for WC compatibility reasons.
		$required_fields = array( 'billing_first_name', 'billing_last_name', 'billing_email' );
		// If the order does not require shipping, these fields can be optional.
		$optional_fields = array(
			'billing_company',
			'billing_country',
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_state',
			'billing_postcode',
			'billing_phone',
		);
		$all_fields      = array_merge( $required_fields, $optional_fields );
		$checkout_fields = version_compare( WC_VERSION, '3.0', '>=' )
			? $checkout->get_checkout_fields()
			: $checkout->checkout_fields;

		$session_wc_format = $this->get_woocommerce_data();

		$missing  = array();
		$present  = array();
		$optional = array();
		foreach ( $all_fields as $key ) {
			if ( ! empty( $checkout_fields['billing'][ $key ]['required'] ) ) {
				if ( ! isset( $session_wc_format[ $key ] ) ) {
					$missing[] = $key;
				} else {
					$present[] = $key;
				}
			} else {
				$optional[] = $key;
			}
		}

		if ( ! empty( $present ) ) {
			$this->add_hidden_class_to_fields( $checkout_fields['billing'], array_merge( $present, $optional ) );
		}

		$field_list = array(
			'shipping_first_name',
			'shipping_last_name',
			'shipping_company',
			'shipping_country',
			'shipping_address_1',
			'shipping_address_2',
			'shipping_city',
			'shipping_state',
			'shipping_postcode',
		);

		$missing  = array();
		$present  = array();
		$optional = array();
		foreach ( $field_list as $key ) {
			if ( ! empty( $checkout_fields['shipping'][ $key ]['required'] ) ) {
				if ( ! isset( $session_wc_format[ $key ] ) ) {
					$missing[] = $key;
				} else {
					$present[] = $key;
				}
			} else {
				$optional[] = $key;
			}
		}

		if ( ! empty( $present ) ) {
			$this->add_hidden_class_to_fields( $checkout_fields['shipping'], array_merge( $present, $optional ) );
		}

		$checkout->checkout_fields = $checkout_fields;
	}

	/**
	 * Alter account checkout field.
	 *
	 * @since 1.7.0
	 *
	 * @param WC_Checkout $checkout WC_Checkout instance.
	 */
	protected function hijack_checkout_field_account( $checkout ) {
		if ( is_user_logged_in() ) {
			return; // There's nothing to do here if the user is logged in
		}

		$checkout_session = $this->get_checkout_session();
		$buyer_id = $checkout_session->buyer->buyerId;
		$buyer_email = $checkout_session->buyer->email;

		$buyer_user_id = $this->get_customer_id_from_buyer( $buyer_id );

		if ( $buyer_user_id ) {
			return; // We shouldn't be here anyways
		}

		$user_id = email_exists( $buyer_email );
		if ( ! $user_id ) {
			return; // We shouldn't be here anyways
		}

		/**
		 * WC 3.0 changes a bit a way to retrieve fields.
		 *
		 * @see https://github.com/woocommerce/woocommerce-gateway-amazon-payments-advanced/issues/217
		 */
		$checkout_fields = version_compare( WC_VERSION, '3.0', '>=' )
			? $checkout->get_checkout_fields()
			: $checkout->checkout_fields;

		$checkout_fields['account'] = array();

		$checkout_fields['account']['amazon_validate_notice'] = array(
			'type' => 'amazon_validate_notice',
		);

		$checkout_fields['account']['amazon_validate'] = array(
			'type'        => 'text',
			'label'       => __( 'Verification Code', 'woocommerce-gateway-amazon-payments-advanced' ),
			'required'    => true,
		);

		$checkout->checkout_fields = $checkout_fields;
	}

	/**
	 * Adds hidden class to checkout field
	 *
	 * @param array $field reference to the field to be hidden.
	 */
	protected function add_hidden_class_to_field( &$field ) {
		if ( isset( $field['class'] ) ) {
			array_push( $field['class'], 'hidden' );
		} else {
			$field['class'] = array( 'hidden' );
		}
	}

	/**
	 * Adds hidden class to checkout fields based on a list
	 *
	 * @param array $checkout_fields reference to checkout fields.
	 * @param array $field_list fields to be hidden.
	 */
	protected function add_hidden_class_to_fields( &$checkout_fields, $field_list ) {
		foreach ( $field_list as $field ) {
			$this->add_hidden_class_to_field( $checkout_fields[ $field ] );
		}
	}

	public function display_amazon_customer_info() {

		if ( $this->need_to_force_refresh() ) {
			$this->render_login_button_again( $this->get_force_refresh() );
			return;
		}

		$checkout_session = $this->get_checkout_session();

		if ( $checkout_session->productType !== $this->get_current_cart_action() ) { // phpcs:ignore WordPress.NamingConventions
			$this->render_login_button_again();
			return;
		}

		$checkout = WC_Checkout::instance();
		// phpcs:disable WordPress.NamingConventions
		?>
		<div id="amazon_customer_details" class="wc-amazon-payments-advanced-populated">
			<div class="col2-set">
				<div class="col-1 <?php echo empty( $checkout_session->shippingAddress ) ? 'hidden' : ''; ?>">
					<?php if ( ! empty( $checkout_session->shippingAddress ) ) : ?>
						<div id="shipping_address_widget">
							<h3>
								<a href="#" class="wc-apa-widget-change" id="shipping_address_widget_change">Change</a>
								<?php esc_html_e( 'Shipping Address', 'woocommerce-gateway-amazon-payments-advanced' ); ?>
							</h3>
							<div class="shipping_address_display">
								<?php echo WC()->countries->get_formatted_address( WC_Amazon_Payments_Advanced_API::format_address( $checkout_session->shippingAddress ) ); ?>
							</div>
						</div>
					<?php endif; ?>
				</div>
				<div class="col-2">
					<div id="payment_method_widget">
						<?php
						$payments     = $checkout_session->paymentPreferences;
						$change_label = esc_html__( 'Change', 'woocommerce-gateway-amazon-payments-advanced' );
						if ( empty( $payments ) ) {
							$change_label = esc_html__( 'Select', 'woocommerce-gateway-amazon-payments-advanced' );
						}
						?>
						<h3>
							<a href="#" class="wc-apa-widget-change" id="payment_method_widget_change"><?php echo $change_label; ?></a>
							<?php esc_html_e( 'Payment Method', 'woocommerce-gateway-amazon-payments-advanced' ); ?>
						</h3>
						<div class="payment_method_display">
							<span class="wc-apa-amazon-logo"></span><?php esc_html_e( 'Your selected Amazon payment method', 'woocommerce-gateway-amazon-payments-advanced' ); ?>
						</div>
					</div>
					<?php if ( ! empty( $checkout_session->billingAddress ) ) : ?>
						<div id="billing_address_widget">
							<h3>
								<?php esc_html_e( 'Billing Address', 'woocommerce-gateway-amazon-payments-advanced' ); ?>
							</h3>
							<div class="billing_address_display">
								<?php echo WC()->countries->get_formatted_address( WC_Amazon_Payments_Advanced_API::format_address( $checkout_session->billingAddress ) ); ?>
							</div>
						</div>
					<?php endif; ?>
				</div>

				<?php if ( ! is_user_logged_in() && $checkout->is_registration_enabled() ) : ?>

					<div id="wc-apa-account-fields-anchor"></div>

				<?php endif; ?>

				<?php if ( is_user_logged_in() ) : ?>
					<?php
					$checkout_session = $this->get_checkout_session();
					$buyer_id = $checkout_session->buyer->buyerId;
					$buyer_email = $checkout_session->buyer->email;

					$buyer_user_id = $this->get_customer_id_from_buyer( $buyer_id );
					?>
					<?php if ( ! $buyer_user_id ) : ?>
						<div class="woocommerce-account-fields">
							<div class="link-account">
								<?php
								$key = 'amazon_link';
								woocommerce_form_field(
									$key,
									array(
										'type'        => 'checkbox',
										'label'       => __( 'Link Amazon Pay Account', 'woocommerce-gateway-amazon-payments-advanced' ),
									),
									$checkout->get_value( $key )
								);
								?>
								<div class="clear"></div>
							</div>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>

		<?php
		// phpcs:enable WordPress.NamingConventions
	}

	protected function get_woocommerce_data() {
		// TODO: Store in session for performance, always clear when coming back from AMZ
		$checkout_session_id = $this->get_checkout_session_id();
		if ( empty( $checkout_session_id ) ) {
			return array();
		}

		$checkout_session = $this->get_checkout_session();

		$data = array();

		if ( ! empty( $checkout_session->buyer ) ) {
			// Billing
			$wc_billing_address = array();
			if ( ! empty( $checkout_session->billingAddress ) ) { // phpcs:ignore WordPress.NamingConventions
				$wc_billing_address = WC_Amazon_Payments_Advanced_API::format_address( $checkout_session->billingAddress ); // phpcs:ignore WordPress.NamingConventions
			} else {
				if ( ! empty( $checkout_session->shippingAddress ) ) { // phpcs:ignore WordPress.NamingConventions
					$wc_billing_address = WC_Amazon_Payments_Advanced_API::format_address( $checkout_session->shippingAddress ); // phpcs:ignore WordPress.NamingConventions
				} else {
					$wc_billing_address = WC_Amazon_Payments_Advanced_API::format_name( $checkout_session->buyer->name );
				}
			}
			if ( ! empty( $checkout_session->buyer->email ) ) {
				$wc_billing_address['email'] = $checkout_session->buyer->email;
			}
			foreach ( $wc_billing_address as $field => $val ) {
				$data[ 'billing_' . $field ] = $val;
			}

			$wc_shipping_address = array();
			if ( ! empty( $checkout_session->shippingAddress ) ) { // phpcs:ignore WordPress.NamingConventions
				$wc_shipping_address = WC_Amazon_Payments_Advanced_API::format_address( $checkout_session->shippingAddress ); // phpcs:ignore WordPress.NamingConventions
			}

			// Shipping
			foreach ( $wc_shipping_address as $field => $val ) {
				$data[ 'shipping_' . $field ] = $val;
			}
		}

		return $data;
	}

	public function use_checkout_session_data( $data ) {
		if ( $data['payment_method'] !== $this->id ) {
			return $data;
		}

		$formatted_session_data = $this->get_woocommerce_data();

		$data = array_merge( $data, array_intersect_key( $formatted_session_data, $data ) ); // only set data that exists in data

		if ( isset( $_REQUEST['amazon_link'] ) ) {
			$data['amazon_link'] = $_REQUEST['amazon_link'];
		}

		return $data;
	}

	public function use_checkout_session_data_single( $ret, $input ) {
		if ( ! WC()->cart->needs_payment() ) {
			return $ret;
		}

		$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();
		WC()->payment_gateways()->set_current_gateway( $available_gateways );

		if ( ! isset( $available_gateways[ $this->id ] ) ) {
			return $ret;
		}

		if ( true !== $available_gateways[ $this->id ]->chosen ) {
			return $ret;
		}

		if ( ! $this->is_logged_in() ) {
			return $ret;
		}

		switch ( $input ) {
			case 'amazon_link':
				if ( isset( $_REQUEST[ $input ] ) ) {
					return $_REQUEST[ $input ];
				}
				break;
			default:
				$session = $this->get_woocommerce_data();

				if ( isset( $session[ $input ] ) ) {
					return $session[ $input ];
				}
				break;
		}

		return $ret;
	}

	/**
	 * Process payment.
	 *
	 * @version 2.0.0
	 *
	 * @param int $order_id Order ID.
	 */
	public function process_payment( $order_id ) {
		$process = apply_filters( 'woocommerce_amazon_pa_process_payment', null, $order_id );
		if ( ! is_null( $process ) ) {
			return $process;
		}

		$order = wc_get_order( $order_id );

		$checkout_session_id = $this->get_checkout_session_id();

		$checkout_session = $this->get_checkout_session();

		$payments = $checkout_session->paymentPreferences; // phpcs:ignore WordPress.NamingConventions

		try {
			if ( ! $order ) {
				throw new Exception( __( 'Invalid order.', 'woocommerce-gateway-amazon-payments-advanced' ) );
			}

			if ( empty( $payments ) ) {
				throw new Exception( __( 'An Amazon Pay payment method was not chosen.', 'woocommerce-gateway-amazon-payments-advanced' ) );
			}

			// TODO: Add shipping requirement check

			// TODO: Implement Multicurrency

			$order_total = $order->get_total();
			$currency    = wc_apa_get_order_prop( $order, 'order_currency' );

			wc_apa()->log( __METHOD__, "Info: Beginning processing of payment for order {$order_id} for the amount of {$order_total} {$currency}. Checkout Session ID: {$checkout_session_id}." );

			if ( empty( $checkout_session->paymentDetails ) || empty( $checkout_session->paymentDetails->chargeAmount ) || $checkout_session->paymentDetails->chargeAmount->amount !== $order_total ) { // phpcs:ignore WordPress.NamingConventions
				$order->update_meta_data( 'amazon_payment_advanced_version', WC_AMAZON_PAY_VERSION ); // TODO: ask if WC 2.6 support is still needed (it's a 2017 release)
				$order->update_meta_data( 'woocommerce_version', WC()->version );

				$payment_intent = 'AuthorizeWithCapture';
				switch ( $this->settings['payment_capture'] ) {
					case 'authorize':
						$payment_intent = 'Authorize';
						break;
					case 'manual':
						$payment_intent = 'Confirm';
						break;
				}

				$can_do_async = false;
				if ( 'async' === $this->settings['authorization_mode'] && 'authorize' === $this->settings['payment_capture'] ) {
					$can_do_async = true;
				}

				$payload = array(
					'paymentDetails'                => array(
						'paymentIntent' => $payment_intent, // TODO: Check Authorize, and Confirm flows.
						'canHandlePendingAuthorization' => $can_do_async,
						// "softDescriptor" => "Descriptor", // TODO: Implement setting, if empty, don't set this. ONLY FOR AuthorizeWithCapture
						'chargeAmount'  => array(
							'amount'       => $order_total,
							'currencyCode' => $currency,
						),
					),
					'merchantMetadata'              => WC_Amazon_Payments_Advanced_API::get_merchant_metadata( $order_id ),
				);

				wc_apa()->log( __METHOD__, "Updating checkout session data for #{$order_id}. Checkout Session ID: {$checkout_session_id}.\n" . wp_json_encode( $payload, JSON_PRETTY_PRINT ) );

				$response = WC_Amazon_Payments_Advanced_API::update_checkout_session_data(
					$checkout_session_id,
					$payload
				);

				if ( is_wp_error( $response ) ) {
					wc_apa()->log( __METHOD__, "Error processing payment for order {$order_id}. Checkout Session ID: {$checkout_session_id}.\n" . wp_json_encode( $response, JSON_PRETTY_PRINT ) );
					wc_add_notice( __( 'There was an error while processing your payment. Your payment method was not charged. Please try again. If the error persist, please contact us about your order.', 'woocommerce-gateway-amazon-payments-advanced' ), 'error' );
					return;
				}
			} else {
				$response = $checkout_session;
			}

			if ( ! empty( $response->constraints ) ) {
				wc_apa()->log( __METHOD__, "Error processing payment for order {$order_id}. Checkout Session ID: {$checkout_session_id}.\n" . wp_json_encode( $response->constraints, JSON_PRETTY_PRINT ) );
				wc_add_notice( __( 'There was an error while processing your payment. Your payment method was not charged. Please try again. If the error persist, please contact us about your order.', 'woocommerce-gateway-amazon-payments-advanced' ), 'error' );
				return;
			}

			$order->save();

			// Return thank you page redirect.
			return array(
				'result'   => 'success',
				'redirect' => $response->webCheckoutDetails->amazonPayRedirectUrl, // phpcs:ignore WordPress.NamingConventions
			);

		} catch ( Exception $e ) {
			wc_add_notice( __( 'Error:', 'woocommerce-gateway-amazon-payments-advanced' ) . ' ' . $e->getMessage(), 'error' );
		}
	}

	public function handle_return() {
		$checkout_session_id = $this->get_checkout_session_id();

		$order_id = isset( WC()->session->order_awaiting_payment ) ? absint( WC()->session->order_awaiting_payment ) : 0;

		if ( empty( $order_id ) ) {
			wc_apa()->log( __METHOD__, "Error: Order could not be found. Checkout Session ID: {$checkout_session_id}." );
			wc_add_notice( __( 'There was an error while processing your payment. Please try again. If the error persist, please contact us about your order.', 'woocommerce-gateway-amazon-payments-advanced' ), 'error' );
			return;
		}

		$order = wc_get_order( $order_id );

		$order_total = $order->get_total();
		$currency    = wc_apa_get_order_prop( $order, 'order_currency' );

		$response = WC_Amazon_Payments_Advanced_API::complete_checkout_session(
			$checkout_session_id,
			array(
				'chargeAmount' => array(
					'amount'       => $order_total,
					'currencyCode' => $currency,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_code = $response->get_error_code();
			if ( 'CheckoutSessionCanceled' === $error_code ) {
				$checkout_session = $this->get_checkout_session( true );

				switch ( $checkout_session->statusDetails->reasonCode ) { // phpcs:ignore WordPress.NamingConventions
					case 'Declined':
						wc_add_notice( __( 'There was a problem with previously declined transaction. Please try placing the order again.', 'woocommerce-gateway-amazon-payments-advanced' ), 'error' );
						break;
					case 'BuyerCanceled':
						wc_add_notice( __( 'The transaction was canceled by you. Please try placing the order again.', 'woocommerce-gateway-amazon-payments-advanced' ), 'error' );
						break;
					default:
						wc_apa()->log(
							__METHOD__,
							"Error processing payment for order {$order_id}. Checkout Session ID: {$checkout_session_id}.\n" . wp_json_encode(
								array(
									'error_code'       => $error_code,
									'checkout_session' => $checkout_session,
								),
								JSON_PRETTY_PRINT
							)
						);
						wc_add_notice( __( 'There was an error while processing your payment. Please try again. If the error persist, please contact us about your order.', 'woocommerce-gateway-amazon-payments-advanced' ), 'error' );
						break;
				}

				$this->do_force_refresh( __( 'Click the button below to select another payment method', 'woocommerce-gateway-amazon-payments-advanced' ) );
			} else {
				wc_add_notice( __( 'Error:', 'woocommerce-gateway-amazon-payments-advanced' ) . ' ' . $response->get_error_message(), 'error' );
			}
			return;
		}

		if ( 'Completed' !== $response->statusDetails->state ) { // phpcs:ignore WordPress.NamingConventions
			// TODO: Handle error. Ask for posibilities of status not to be completed at this stage.
			wc_add_notice( __( 'Error:', 'woocommerce-gateway-amazon-payments-advanced' ) . ' <pre>' . wp_json_encode( $response->statusDetails, JSON_PRETTY_PRINT ) . '</pre>', 'error' ); // phpcs:ignore WordPress.NamingConventions
			return;
		}

		$charge_permission_id = $response->chargePermissionId; // phpcs:ignore WordPress.NamingConventions
		$order->update_meta_data( 'amazon_charge_permission_id', $charge_permission_id );
		$order->save();
		$charge_permission_status = $this->log_charge_permission_status_change( $order );
		$charge_id = $response->chargeId; // phpcs:ignore WordPress.NamingConventions
		if ( ! empty( $charge_id ) ) {
			$order->update_meta_data( 'amazon_charge_id', $charge_id );
			$order->save();
			$charge_status = $this->log_charge_status_change( $order );
		} else {
			$order->update_status( 'on-hold' );
			wc_maybe_reduce_stock_levels( $order->get_id() );
		}
		$order->save();

		// Remove cart.
		WC()->cart->empty_cart();

		// TODO: Maybe log out with JS. Ask.
		$this->do_logout();

		wp_safe_redirect( $order->get_checkout_order_received_url() );
		exit;
	}

	public function log_charge_status_change( WC_Order $order, $charge = null ) {
		$charge_id = $order->get_meta( 'amazon_charge_id' );
		// TODO: Maybe support multple charges to be tracked?
		if ( ! is_null( $charge ) && $charge_id !== $charge->chargeId ) { // phpcs:ignore WordPress.NamingConventions
			$order->delete_meta_data( 'amazon_charge_id' );
			$order->delete_meta_data( 'amazon_charge_status' );
			$order->save();
			$charge_id = $charge->chargeId; // phpcs:ignore WordPress.NamingConventions
		}
		if ( is_null( $charge ) ) {
			if ( empty( $charge_id ) ) {
				return null;
			}
			$charge = WC_Amazon_Payments_Advanced_API::get_charge( $charge_id );
		}
		$order->read_meta_data( true ); // Force read from db to avoid concurrent notifications
		$old_status = $this->get_cached_charge_status( $order, true )->status;
		$charge_status = $charge->statusDetails->state; // phpcs:ignore WordPress.NamingConventions
		if ( $charge_status === $old_status ) {
			switch ( $old_status ) {
				case 'AuthorizationInitiated':
				case 'Authorized':
				case 'CaptureInitiated':
					wc_apa()->ipn_handler->schedule_hook( $order->get_id(), 'CHARGE' );
					break;
			}
			return $old_status;
		}
		$this->refresh_cached_charge_status( $order, $charge );
		$order->update_meta_data( 'amazon_charge_id', $charge_id ); // phpcs:ignore WordPress.NamingConventions
		$order->save(); // Save early for less race conditions

		// @codingStandardsIgnoreStart
		$order->add_order_note( sprintf(
			/* translators: 1) Amazon Charge ID 2) Charge status */
			__( 'Charge %1$s with status %2$s.', 'woocommerce-gateway-amazon-payments-advanced' ),
			(string) $charge_id,
			(string) $charge_status
		) );
		// @codingStandardsIgnoreEnd

		switch ( $charge_status ) {
			case 'AuthorizationInitiated':
			case 'Authorized':
			case 'CaptureInitiated':
				// Mark as on-hold.
				$order->update_status( 'on-hold' );
				wc_maybe_reduce_stock_levels( $order->get_id() );
				wc_apa()->ipn_handler->schedule_hook( $order->get_id(), 'CHARGE' );
				break;
			case 'Canceled':
			case 'Declined':
				$order->update_status( 'failed' );
				wc_maybe_increase_stock_levels( $order->get_id() );
				break;
			case 'Captured':
				$order->payment_complete();
				break;
			default:
				// TODO: This is an unknown state, maybe handle?
				break;
		}

		$order->save();

		return $charge_status;
	}

	public function log_charge_permission_status_change( WC_Order $order, $charge_permission = null ) {
		$charge_permission_id = $order->get_meta( 'amazon_charge_permission_id' );
		// TODO: Maybe support multple charges to be tracked?
		if ( ! is_null( $charge_permission ) && $charge_permission_id !== $charge_permission->chargePermissionId ) { // phpcs:ignore WordPress.NamingConventions
			$order->delete_meta_data( 'amazon_charge_permission_id' );
			$order->delete_meta_data( 'amazon_charge_permission_status' );
			$order->save();
			$charge_permission_id = $charge_permission->chargePermissionId; // phpcs:ignore WordPress.NamingConventions
		}
		if ( is_null( $charge_permission ) ) {
			if ( empty( $charge_permission_id ) ) {
				return null;
			}
			$charge_permission = WC_Amazon_Payments_Advanced_API::get_charge_permission( $charge_permission_id );
		}
		$order->read_meta_data( true ); // Force read from db to avoid concurrent notifications
		$old_status = $this->get_cached_charge_permission_status( $order, true )->status;
		$charge_permission_status = $charge_permission->statusDetails->state; // phpcs:ignore WordPress.NamingConventions
		if ( $charge_permission_status === $old_status ) {
			switch ( $charge_permission_status ) {
				case 'Chargeable':
				case 'NonChargeable':
					wc_apa()->ipn_handler->schedule_hook( $order->get_id(), 'CHARGE_PERMISSION' );
					break;
			}
			return $old_status;
		}
		$this->refresh_cached_charge_permission_status( $order, $charge_permission );
		$order->update_meta_data( 'amazon_charge_permission_id', $charge_permission_id ); // phpcs:ignore WordPress.NamingConventions
		$order->save(); // Save early for less race conditions

		// @codingStandardsIgnoreStart
		$order->add_order_note( sprintf(
			/* translators: 1) Amazon Charge ID 2) Charge status */
			__( 'Charge Permission %1$s with status %2$s.', 'woocommerce-gateway-amazon-payments-advanced' ),
			(string) $charge_permission_id,
			(string) $charge_permission_status
		) );
		// @codingStandardsIgnoreEnd

		switch ( $charge_permission_status ) {
			case 'Chargeable':
			case 'NonChargeable':
				wc_apa()->ipn_handler->schedule_hook( $order->get_id(), 'CHARGE_PERMISSION' );
				break;
			case 'Closed':
				if ( is_null( $this->get_cached_charge_status( $order, true )->status ) ) {
					$order->update_status( 'failed' );
					wc_maybe_increase_stock_levels( $order->get_id() );
				}
				break;
			default:
				// TODO: This is an unknown state, maybe handle?
				break;
		}

		$order->save();

		return $charge_permission_status;
	}

	public function update_js() {
		$data = array(
			'action' => $this->get_current_cart_action(),
		);
		?>
		<script type="text/template" id="wc-apa-update-vals" data-value="<?php echo esc_attr( wp_json_encode( $data ) ); ?>"></script>
		<?php
	}

	public function get_current_cart_action() {
		return WC()->cart->needs_shipping() ? 'PayAndShip' : 'PayOnly';
	}

	public function render_login_button_again( $message = null ) {
		?>
		<div id="amazon_customer_details" class="wc-amazon-payments-advanced-populated">
			<div class="col2-set">
				<div class="col-1">
					<div id="shipping_address_widget">
						<h3>
							<?php esc_html_e( 'Confirm payment method', 'woocommerce-gateway-amazon-payments-advanced' ); ?>
						</h3>
						<div class="shipping_address_display">
							<p>
							<?php
							if ( empty( $message ) ) {
								$message = __( 'Your cart changed, and you need to confirm your selected payment method again.', 'woocommerce-gateway-amazon-payments-advanced' );
							}

							echo esc_html( $message );
							?>
							</p>
							<?php $this->checkout_button(); ?>
						</div>
					</div>
				</div>
				<div class="col-2">
				</div>
			</div>
		</div>
		<?php
	}

	public function override_billing_fields( $fields ) {
		$old = ! empty( $fields['billing_state']['required'] );

		$fields = parent::override_billing_fields( $fields );

		$fields['billing_state']['required'] = $old;

		return $fields;
	}

	public function override_shipping_fields( $fields ) {
		$old = ! empty( $fields['shipping_state']['required'] );

		$fields = parent::override_shipping_fields( $fields );

		$fields['shipping_state']['required'] = $old;

		return $fields;
	}

	/**
	 * Unset keys json box.
	 *
	 * @return bool|void
	 */
	public function process_admin_options() {
		if ( check_admin_referer( 'woocommerce-settings' ) ) {
			if ( ! empty( $_POST['woocommerce_amazon_payments_advanced_button_language'] ) ) {
				$region   = $_POST['woocommerce_amazon_payments_advanced_payment_region'];
				$language = $_POST['woocommerce_amazon_payments_advanced_button_language'];
				$regions  = WC_Amazon_Payments_Advanced_API::get_languages_per_region();
				if ( ! isset( $regions[ $region ] ) || ! in_array( $language, $regions[ $region ], true ) ) {
					WC_Admin_Settings::add_error( sprintf( __( '%1$s is not a valid language for the %2$s region.', 'woocommerce-gateway-amazon-payments-advanced' ), $language, WC_Amazon_Payments_Advanced_API::get_region_label( $region ) ) );
					$_POST['woocommerce_amazon_payments_advanced_button_language'] = '';
				}
			}
			parent::process_admin_options();
		}
	}

	private function format_status_details( $status_details ) {
		$charge_status         = $status_details->state; // phpcs:ignore WordPress.NamingConventions
		$charge_status_reasons = $status_details->reasons; // phpcs:ignore WordPress.NamingConventions
		if ( empty( $charge_status_reasons ) ) {
			$charge_status_reasons = array();
		}
		$charge_status_reason = $status_details->reasonCode; // phpcs:ignore WordPress.NamingConventions

		if ( $charge_status_reason ) {
			$charge_status_reasons[] = (object) array(
				'reasonCode'        => $charge_status_reason,
				'reasonDescription' => '',
			);
		}

		return (object) array(
			'status'  => $charge_status,
			'reasons' => $charge_status_reasons,
		);
	}

	public function get_cached_charge_permission_status( WC_Order $order, $read_only = false ) {
		$charge_permission_status = $order->get_meta( 'amazon_charge_permission_status' );
		$charge_permission_status = json_decode( $charge_permission_status );
		if ( ! is_object( $charge_permission_status ) ) {
			if ( ! $read_only ) {
				$charge_permission_status = $this->refresh_cached_charge_permission_status( $order );
			} else {
				$charge_permission_status = (object) array(
					'status'  => null,
					'reasons' => array(),
				);
			}
		}

		return $charge_permission_status;
	}

	public function refresh_cached_charge_permission_status( WC_Order $order, $charge_permission = null ) {
		if ( ! is_object( $charge_permission ) ) {
			$charge_permission_id = $order->get_meta( 'amazon_charge_permission_id' );
			$charge_permission    = WC_Amazon_Payments_Advanced_API::get_charge_permission( $charge_permission_id );
		}

		$charge_permission_status = $this->format_status_details( $charge_permission->statusDetails ); // phpcs:ignore WordPress.NamingConventions

		$order->update_meta_data( 'amazon_charge_permission_status', wp_json_encode( $charge_permission_status ) );
		$order->save();

		return $charge_permission_status;
	}

	public function get_cached_charge_status( WC_Order $order, $read_only = false ) {
		$charge_status = $order->get_meta( 'amazon_charge_status' );
		$charge_status = json_decode( $charge_status );
		if ( ! is_object( $charge_status ) ) {
			if ( ! $read_only ) {
				$charge_status = $this->refresh_cached_charge_status( $order );
			} else {
				$charge_status = (object) array(
					'status'  => null,
					'reasons' => array(),
				);
			}
		}

		return $charge_status;
	}

	public function refresh_cached_charge_status( WC_Order $order, $charge = null ) {
		if ( ! is_object( $charge ) ) {
			$charge_id = $order->get_meta( 'amazon_charge_id' );
			$charge    = WC_Amazon_Payments_Advanced_API::get_charge( $charge_id );
		}

		$charge_status = $this->format_status_details( $charge->statusDetails ); // phpcs:ignore WordPress.NamingConventions

		$order->update_meta_data( 'amazon_charge_status', wp_json_encode( $charge_status ) );
		$order->save();

		return $charge_status;
	}

	public function handle_refund( WC_Order $order, $refund, $wc_refund_id = null ) {
		$wc_refund = null;
		$previous_refunds = wp_list_pluck( $order->get_meta( 'amazon_refund_id', false ), 'value' );
		if ( empty( $wc_refund_id ) ) {
			if ( ! empty( $previous_refunds ) ) {
				$refunds = $order->get_refunds();
				foreach ( $refunds as $this_wc_refund ) {
					$this_refund_id = $this_wc_refund->get_meta( 'amazon_refund_id' );
					if ( $this_refund_id === $refund->refundId ) {
						$wc_refund = $this_wc_refund;
					}
				}
			}
			if ( empty( $wc_refund ) ) {
				$wc_refund = wc_create_refund(
					array(
						'amount'   => $refund->refundAmount->amount, // phpcs:ignore WordPress.NamingConventions
						'order_id' => $order->get_id(),
					)
				);

				if ( is_wp_error( $wc_refund ) ) {
					return false;
				}
			}
			$wc_refund_id = $wc_refund->get_id();
		} else {
			$wc_refund = wc_get_order( $wc_refund_id );
		}

		if ( ! in_array( $refund->refundId, $previous_refunds, true ) ) { // phpcs:ignore WordPress.NamingConventions
			$order->add_meta_data( 'amazon_refund_id', $refund->refundId ); // phpcs:ignore WordPress.NamingConventions
			$order->save();
		}

		$wc_refund->update_meta_data( 'amazon_refund_id', $refund->refundId ); // phpcs:ignore WordPress.NamingConventions
		$wc_refund->set_refunded_payment( true );
		$wc_refund->save();
		return true;
	}

	public function process_refund( $order_id, $amount = null, $reason = '') {
		$order = wc_get_order( $order_id );
		$charge_id = $order->get_meta( 'amazon_charge_id' );
		if ( empty( $charge_id ) ) {
			wc_apa()->log( __METHOD__, 'Order #' . $order_id .' doesnt have a charge' );
			return new WP_Error( 'no_charge', 'No charge to refund on this order' );
		}
		wc_apa()->log( __METHOD__, 'Processing refund from admin for order #' . $order_id );
		wc_apa()->log( __METHOD__, 'Processing refund from admin for order #' . $order_id . '. Temporary refund ID #' . $this->current_refund->get_id() );
		$refund = WC_Amazon_Payments_Advanced_API::refund_charge( $charge_id, $amount );
		$wc_refund_status = wc_apa()->get_gateway()->handle_refund( $order, $refund, $this->current_refund->get_id() );
		return true;
	}

	public function current_refund_set( $wc_refund ) {
		$this->current_refund = $wc_refund; // Cache refund object in a hook before process_refund is called
	}

}
