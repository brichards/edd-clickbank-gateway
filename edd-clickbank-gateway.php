<?php
/*
 * Plugin Name: Easy Digital Downloads - ClickBank Gateway
 * Plugin URI:  https://easydigitaldownloads.com/extension/clickbank-gateway
 * Description: ClickBank gateway extension for Easy Digital Downloads.
 * Version:     1.3.1
 * Author:      Brian Richards
 * Author URI:  http://www.rzen.net
 * Text Domain: edd-clickbank-gateway
 * Domain Path: /languages/
 */

final class EDD_ClickBank_Gateway {

	/**
	 * ClickBank option for binding item numbers to downloads
	 *
	 * @var string
	 */
	public static $clickbank_option = 'clickbank_items';

	/**
	 * Construct
	 */
	public function __construct() {

		if ( class_exists( 'EDD_License' ) ) {
			$license = new EDD_License(
				__FILE__,
				'EDD ClickBank Gateway',
				'1.3.1',
				'Brian Richards'
			);
		}

		add_filter( 'edd_settings_gateways', array( $this, 'clickbank_settings' ) );
		add_action( 'edd_ClickBank_cc_form', '__return_null' );
		add_action( 'add_meta_boxes',        array( $this, 'add_meta_box' ) );
		add_action( 'save_post',             array( $this, 'save_post' ) );
		add_filter( 'edd_purchase_link_defaults', array( $this, 'edd_purchase_link_defaults' ) );
		add_filter( 'edd_straight_to_gateway_purchase_data', array( $this, 'edd_straight_to_gateway_purchase_data' ) );
		add_action( 'edd_gateway_ClickBank', array( $this, 'edd_gateway_ClickBank' ) );
		add_action( 'init',                  array( $this, 'clickbank_process_payment' ) );
		add_action( 'init',                  array( $this, 'process_clickbank_purchase_confirmation_url' ) );
	}

	/**
	 * ClickBank settings
	 *
	 * @param array $settings
	 * @return array merged settings
	 */
	public function clickbank_settings( $settings ) {
		$clickbank_gateway_settings = array(
			array(
				'id'   => 'clickbank_settings',
				'name' => '<strong>' . __( 'ClickBank Settings', 'edd-clickbank-gateway' ) . '</strong>',
				'desc' => __( 'Configure the gateway settings', 'edd-clickbank-gateway' ),
				'type' => 'header'
			),
			array(
				'id'   => 'clickbank_account_nickname',
				'name' => __( 'Account Nickname', 'edd-clickbank-gateway' ),
				'desc' => '',
				'type' => 'text',
				'size' => 'regular',
			),
			array(
				'id'   => 'clickbank_secret_key',
				'name' => __( 'Secret Key', 'edd-clickbank-gateway' ),
				'desc' => '',
				'type' => 'text',
				'size' => 'regular',
			),
		);

		return array_merge( $settings, $clickbank_gateway_settings );

	}

	/**
	 * Register Clickbank metabox.
	 */
	public function add_meta_box() {
		add_meta_box(
			'edd_clickbank',
			__( 'ClickBank Item Number', 'edd-clickbank-gateway' ),
			array( $this, 'render_meta_box' ),
			'download',
			'side'
		);
	}

	/**
	 * Render Clickbank metabox.
	 *
	 * @param object $post
	 */
	public function render_meta_box( $post ) {
		global $edd_options;

		$item = self::get_clickbank_item( $post->ID );
		wp_nonce_field( plugin_basename( __FILE__ ), 'clickbank_nonce' );
		?>
		<div class="tagsdiv">
			<?php if ( empty( $edd_options['clickbank_account_nickname'] ) || empty( $edd_options['clickbank_secret_key'] ) ) : ?>
				<p><a href="<?php echo admin_url( 'edit.php?post_type=download&page=edd-settings&tab=gateways' ); ?>"><?php _e( 'Update your ClickBank payment gateway settings.', 'edd-clickbank-gateway' ); ?></a></p>
			<?php else : ?>
				<p><input type="text" autocomplete="off" class="widefat" name="clickbank_item" id="clickbank_item" value="<?php echo esc_attr( $item ); ?>"></p>
				<p class="howto"><?php _e('Redirect user to the given ClickBank item during checkout.', 'edd-clickbank-gateway'); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save post data.
	 *
	 * @param string $post_id
	 */
	public function save_post( $post_id ) {
		if (
			isset( $_POST['clickbank_nonce'] )
			&& wp_verify_nonce( $_POST['clickbank_nonce'], plugin_basename( __FILE__ ) )
			&& ! defined( 'DOING_AUTOSAVE' )
			&& current_user_can( 'edit_post', $post_id )
		) {
			$item = ! empty( $_POST['clickbank_item'] ) ? esc_html( $_POST['clickbank_item'] ) : null;
			self::update_clickbank_items( $item, $post_id );
		}
	}

	/**
	 * Alter ClickBank product purchase links to post directly to payment gateway.
	 *
	 * @since  1.3.0
	 *
	 * @param  array $args EDD Purchase Link args.
	 * @return array       Updated EDD Purchase Link args.
	 */
	public function edd_purchase_link_defaults( $args ) {

		$item = self::get_clickbank_item( $args['download_id'] );

		if ( ! empty( $item ) ) {
			$args['direct'] = true;
			add_filter( 'edd_shop_supports_buy_now', '__return_true' );
		}

		return $args;
	}

	/**
	 * Alter straight_to_gateway to force ClickBank as gateway for CB products.
	 *
	 * @since  1.3.0
	 *
	 * @param  array $purchase_data EDD Purchase Data.
	 * @return array                Updated EDD Purchase Data.
	 */
	public function edd_straight_to_gateway_purchase_data( $purchase_data ) {
		// edd_send_to_gateway() calls action "edd_gateway_{$gateway}" and sends $payment_data
		// $payment_data is built via edd_build_straight_to_gateway_data( $download_id, $options, $quantity )
		// download post ID is stored in $payment_data['downloads'][0]['id']

		$item = self::get_clickbank_item( $purchase_data['downloads'][0]['id'] );

		if ( ! empty( $item ) ) {
			$purchase_data['gateway'] = 'ClickBank';
			add_filter( 'edd_enabled_payment_gateways', array( $this, 'edd_enabled_payment_gateways' ) );
		}

		return $purchase_data;
	}

	/**
	 * Include ClickBank as an enabled payment gateway.
	 *
	 * @since  1.3.0
	 *
	 * @param  array $gateways EDD Payment Gateways.
	 * @return array           Updated EDD Payment Gateways.
	 */
	function edd_enabled_payment_gateways( $gateways ) {
		$gateways['ClickBank'] = array(
			'admin_label'    => __( 'ClickBank', 'edd-clickbank-gateway' ),
			'checkout_label' => __( 'ClickBank', 'edd-clickbank-gateway' ),
			'supports' => array(
				'buy_now',
			),
		);
		return $gateways;
	}

	/**
	 * Redirect customers to ClickBank during checkout.
	 *
	 * @since  1.3.0
	 *
	 * @param  array $payment_data EDD Purchase Data.
	 */
	public function edd_gateway_ClickBank( $payment_data ) {
		global $edd_options;

		$item = self::get_clickbank_item( $payment_data['downloads'][0]['id'] );

		if ( ! empty( $item ) && ! empty( $edd_options['clickbank_account_nickname'] ) && ! empty( $edd_options['clickbank_secret_key'] ) ) {
			// http://ITEM.VENDOR.pay.clickbank.net
			wp_redirect( sprintf(
				'http://%1$s.%2$s.pay.clickbank.net/',
				$item,
				$edd_options['clickbank_account_nickname']
			) );
			die;
		}
	}

	/**
	 * When customers are redirected back to the EDD site from Clickbank, we need to set up the purchase session to match their URL variables
	 *
	 * @since  1.3.1
	 */
	public function process_clickbank_purchase_confirmation_url() {

		// This is an example of the URL we are trying to "catch" here:
		// https://thisedddmain.com/checkout/purchase-confirmation/?item=1&cbreceipt=SW6VAK6K&time=1523474665&cbpop=7EB02C28&cbaffi=0&cname=Test+Test&cemail=customer@customer.com&ccountry=US&czip=12345
		if ( isset( $_GET['cbreceipt'] ) && isset( $_GET['cbpop'] ) && isset( $_GET['cbaffi'] ) && isset( $_GET['cname'] ) && isset( $_GET['cemail'] ) && isset( $_GET['cemail'] ) ) {

			// Find which EDD Payment corresponds to this clickbank data
			$payment_id = self::get_used_key(  $_GET['cbpop']  );

			// If a payment was found
			if ( $payment_id ) {
				$payment = new EDD_Payment( $payment_id );

				// Set up the EDD purchase session. This makes the edd_receipt shortcode know what to show
				edd_set_purchase_session( $purchase_data = array(
					'purchase_key' => $payment->key
				) );
			}
		}
	}

	/**
	 * Process payment for clickbank gateway.
	 */
	public function clickbank_process_payment() {
		global $edd_options;

		if ( self::clickbank_data_received() && ! empty( $edd_options['clickbank_secret_key'] ) ) {

			$this->log( 'Clickbank data received. GET: ' . print_r( $_GET, true ) );

			$name       = explode( ' ', rawurldecode( $_GET['cname'] ), 2 );
			$email      = sanitize_email( rawurldecode( $_GET['cemail'] ) );
			$key        = $edd_options['clickbank_secret_key'];
			$receipt    = $_GET['cbreceipt'];
			$time       = absint( $_GET['time'] );
			$item       = esc_html( $_GET['item'] );
			$product_id = self::get_edd_product_id( $item );
			$cbpop      = $_GET['cbpop'];
			$xxpop      = strtoupper( substr( sha1( "$key|$receipt|$time|$item" ), 0, 8 ) );

			$this->log( 'cbpop value: ' . $cbpop . '. xxpop value: ' . $xxpop );
			$this->log( 'ClickBank item: ' . $item );
			$this->log( 'ClickBank product ID: ' . $product_id );

			// Confirm cbpop is valid, and unused, and product exists
			if ( $cbpop == $xxpop && ! self::get_used_key( $cbpop ) && false !== $product_id ) {

				$payment = new EDD_Payment;
				$payment->email = $email;
				$payment->first_name = ! empty( $name[0] ) ? $name[0] : '';
				$payment->last_name = ! empty( $name[1] ) ? $name[1] : '';
				$payment->add_download( $product_id );
				$payment->gateway = 'ClickBank';
				$payment->save();

				if ( $payment->ID > 0 ) {
					self::add_used_key( $payment->ID, $cbpop );
					$payment->status = 'complete';
					$payment->save();
					edd_empty_cart();
					wp_redirect( add_query_arg( 'payment_key', $payment->key, edd_get_success_page_uri() ) ); exit;
				}
			}
		}
	}

	private static function clickbank_data_received() {
		return ( ! empty( $_GET['item'] ) && ! empty( $_GET['cbreceipt'] ) && ! empty( $_GET['time'] ) && ! empty( $_GET['cbpop'] ) && ! empty( $_GET['cname'] ) && ! empty( $_GET['cemail'] ) );
	}

	private static function get_used_key( $payment_key = '' ) {
		global $wpdb;
		$payment_id = $wpdb->get_var( $wpdb->prepare(
			"
			SELECT post_id
			FROM   $wpdb->postmeta
			WHERE  meta_key = '_edd_clickbank_cbpop'
			       AND meta_value = %s
			",
			$payment_key
		) );
		return absint( $payment_id );
	}

	private static function add_used_key( $payment_id = 0, $payment_key = '' ) {
		update_post_meta( absint( $payment_id ), '_edd_clickbank_cbpop', $payment_key );
	}

	private static function set_current_session( $product_id = 0 ) {
		EDD()->session->set( 'edd_cart', array(
			array(
				'id' => absint( $product_id ),
				'options' => array(),
			),
		) );
	}

	private static function build_user_info( $name = array(), $email = '' ) {
		return array(
			'id'         => get_current_user_id(),
			'email'      => $email,
			'first_name' => ! empty( $name[0] ) ? $name[0] : '',
			'last_name'  => ! empty( $name[1] ) ? $name[1] : '',
			'discount'   => 'none',
		);
	}

	private static function build_purchase_data( $user_info = array(), $time = 0 ) {
		global $edd_options;
		$_POST['edd-gateway'] = 'ClickBank';
		return array(
			'downloads'    => edd_get_cart_contents(),
			'subtotal'     => edd_get_cart_subtotal(),
			'discount'     => edd_get_cart_discounted_amount(),
			'tax'          => edd_get_cart_tax(),
			'price'        => edd_get_cart_total(),
			'purchase_key' => strtolower( md5( uniqid() ) ),
			'user_email'   => $user_info['email'],
			'date'         => date( 'Y-m-d H:i:s', absint( $time ) ),
			'user_info'    => $user_info,
			'post_data'    => array(),
			'cart_details' => edd_get_cart_content_details(),
			'gateway'      => $_POST['edd-gateway'],
			'card_info'    => array(),
			'currency'     => $edd_options['currency'],
			'status'       => 'pending',
		);
	}

	private static function get_edd_product_id( $item = 0 ) {
		$clickbank_items = self::get_clickbank_items();
		return array_search( $item, $clickbank_items );
	}

	private static function get_clickbank_item( $product_id = 0 ) {
		$clickbank_items = self::get_clickbank_items();
		return isset( $clickbank_items[ $product_id ] ) ? esc_html( $clickbank_items[ $product_id ] ) : null;
	}

	private static function update_clickbank_items( $item = 0, $post_id = 0 ) {
		$clickbank_items = self::get_clickbank_items();

		// Only save the item ID if it's not already set for another post
		if ( ! empty( $item ) && false === self::get_edd_product_id( $item ) ) {
			$clickbank_items[ $post_id ] = $item;
			edd_debug_log( 'ClickBank metabox saved/updated. Item value submitted: ' . $item . '. Array of Clickbank items is now: . ' . json_encode( $clickbank_items ) );
		}else{
			edd_debug_log( 'ClickBank metabox not saved/updated. Item value submitted: ' . $item . '. Array of pre-existing Clickbank items is: . ' . json_encode( $clickbank_items ) );
		}

		// Delete setting for this post if we no longer have an item ID
		if ( empty( $item ) && isset( $clickbank_items[ $post_id ] ) ) {
			unset( $clickbank_items[ $post_id ] );
		}

		self::set_clickbank_items( $clickbank_items );
	}

	private static function get_clickbank_items() {

		// Get the clickbank items that have been saved to the wp_option
		$inaccurate_clickbank_items = get_option( self::$clickbank_option, array() );

		// Set the arrays up that we will use to rebuild an accurate list of clickbank products
		$clickbank_post_ids = array();
		$accurate_clickbank_items = array();

		// Loop through each innacurate clickbank EDD Product to make sure it wasn't deleted
		foreach( $inaccurate_clickbank_items as $post_id => $clickbank_item_number ) {

			$post = new EDD_Download( $post_id );

			// If this product doesn't exist, don't add it to the list of accurate clickbank products
			if ( ! $post->ID ) {
				continue;
			}

			// If this post still exists and it has a valid value saved for clickbank, re-add it
			if ( isset( $inaccurate_clickbank_items[$post_id] ) && ! empty( $inaccurate_clickbank_items[$post_id] ) ) {
				$accurate_clickbank_items[$post_id] = $inaccurate_clickbank_items[$post_id];
			}

		}

		// If something went wrong, don't make any changes
		if ( empty( $accurate_clickbank_items ) ) {
			$accurate_clickbank_items = $inaccurate_clickbank_items;
		}

		return $accurate_clickbank_items;
	}

	private static function set_clickbank_items( $clickbank_items = array() ) {
		return update_option( self::$clickbank_option, $clickbank_items );
	}

	private function log( $message = '' ) {
		if( function_exists( 'edd_debug_log' ) ) {
			edd_debug_log( $message );
		}
	}
}
$edd_clickbank_gateway = new EDD_ClickBank_Gateway;
