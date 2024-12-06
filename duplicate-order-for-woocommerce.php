<?php
/*
  Plugin name: Duplicate Order for WooCommerce
  Version: 1.0
  Author: Misha Rudrastyh
  Author URI: https://rudrastyh.com
  Description: The plugin allows to easily duplicate WooCommerce orders in one click.
  Requires Plugins: woocommerce
  Text domain: duplicate-order-for-woocommerce
  License: GPL v2 or later
  License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */


class DOFW_Duplicate_Order {


	public function __construct() {

		add_action( 'woocommerce_order_actions', array( $this, 'add_order_action' ) );
		add_filter( 'woocommerce_admin_order_actions', array( $this, 'add_order_action_button' ), 25, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'css' ) );
		add_action( 'admin_post_rudr_duplicate', array( $this, 'admin_post_callback' ) );
		add_action( 'woocommerce_order_action_duplicate_order', array( $this, 'order_action_callback' ) );

	}

	public function add_order_action( $actions ) {

		$actions[ 'duplicate_order' ] = __( 'Duplicate order', 'duplicate-order-for-woocommerce' );
		return $actions;

	}

	public function add_order_action_button( $actions, $order ) {

		$actions[ 'duplicate_order' ] = array(
			'url' => wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'rudr_duplicate',
						'order_id' => $order->get_id(),
					),
					'admin-post.php',
				),
				'rudr_duplicate_order_' . $order->get_id()
			),
			'name'   => __( 'Duplicate order', 'duplicate-order-for-woocommerce' ),
			'action' => 'duplicate'
		);
		return $actions;

	}

	public function css() {

		wp_enqueue_style(
			'dofw-duplicate-button',
			plugin_dir_url( __FILE__ ) . 'assets/style.css',
			array(),
			filemtime( plugin_dir_path( __FILE__ ) . 'assets/style.css' ),
		);

	}

	public function admin_post_callback() {

		$order_id = ! empty( $_GET[ 'order_id' ] ) ? absint( $_GET[ 'order_id' ] ) : 0;

		check_admin_referer( "rudr_duplicate_order_{$order_id}" );

		$this->duplicate_order( $order_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'wc-orders',
				),
				admin_url(  'admin.php' )
			)
		);
		exit;

	}

	public function order_action_callback( $order ) {

		$new_order_id = $this->duplicate_order( $order->get_id() );

		$new_order = wc_get_order();
		wp_safe_redirect(	$new_order->get_edit_order_url() );
		exit;

	}

	private function duplicate_order( $order_id ) {

		$order = wc_get_order( $order_id );
		if( ! $order ) {
			return false;
		}

		$order_data = $order->get_data();

		$new_order = new WC_Order();

		$new_order->set_currency( $order_data[ 'currency' ] );
		$new_order->set_prices_include_tax( $order_data[ 'prices_include_tax' ] );

		// status
		$new_order->set_status( 'pending' ); // or we can use $order_data[ 'status' ]
		$new_order->set_created_via( $order_data[ 'created_via' ] ); // admin, checkout, store-api
		// dates
		$new_order->set_date_created( current_time( 'mysql' ) );
		$new_order->set_date_paid( $order_data[ 'date_paid' ] );
		$new_order->set_date_completed( $order_data[ 'date_completed' ] );
		$new_order->set_date_modified( $order_data[ 'date_modified' ] );

		// customer
		$new_order->set_customer_id( $order_data[ 'customer_id' ] );
		$new_order->set_customer_ip_address( $order_data[ 'customer_ip_address' ] );
		$new_order->set_customer_user_agent( $order_data[ 'customer_user_agent' ] );
		//$order->set_customer_note( $order_data[ 'customer_note' ] );

		// billing and shipping addresses
		$new_order->set_address( $order_data[ 'billing' ], 'billing' );
		$new_order->set_address( $order_data[ 'shipping' ], 'shipping' );

		// payment methods
		$new_order->set_payment_method( $order_data[ 'payment_method' ] );
		$new_order->set_payment_method_title( $order_data[ 'payment_method_title' ] );
		$new_order->set_transaction_id( $order_data[ 'transaction_id' ] );

		// other meta data
		foreach( $order->get_meta_data() as $meta ) {
			$new_order->add_meta_data( $meta->key, $meta->value, true );
		}

		// order items – products
		foreach( $order->get_items( 'line_item' ) as $line_item ) {
			$product = $line_item->get_product();
			if( $product ) {
				$new_line_item = new WC_Order_Item_Product();
				$new_line_item->set_product_id( $line_item->get_product_id() );
				$new_line_item->set_variation_id( $line_item->get_variation_id() );
				$new_line_item->set_quantity( $line_item->get_quantity() );
				$new_line_item->set_subtotal( (string) $line_item->get_subtotal() );
				$new_line_item->set_total( (string) $line_item->get_total() );
				foreach( $line_item->get_meta_data() as $meta ) {
					$new_line_item->add_meta_data( $meta->key, $meta->value, true );
				}

				$new_order->add_item( $new_line_item );
			}
		}

		// order items – shipping
		foreach ( $order->get_items( 'shipping' ) as $shipping_line ) {
			$new_shipping_line = new WC_Order_Item_Shipping();
			$new_shipping_line->set_method_title( $shipping_line->get_method_title() );
			$new_shipping_line->set_method_id( $shipping_line->get_method_id() );
			$new_shipping_line->set_total( $shipping_line->get_total() );
			$new_shipping_line->set_taxes( $shipping_line->get_taxes() );

			$new_order->add_item( $new_shipping_line );
		}

		// order items – fees
		foreach( $order->get_items( 'fee' ) as $fee_line ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( $fee_line->get_name() );
			$fee->set_amount( $fee_line->get_amount() );
			$fee->set_total( $fee_line->get_total() );

			$new_order->add_item( $fee );
		}

		$new_order->calculate_totals();
		$new_order->save();

		// applying coupons
		foreach( $order->get_items( 'coupon' ) as $coupon_item ) {
			$new_order->apply_coupon( $coupon_item->get_code() );
		}

		// we can add order notes only after an order has been created
		$new_order->add_order_note( sprintf( 'This order was duplicated from order #%d.', $order->get_id() ) );

		return $new_order->get_id();

	}

}

new DOFW_Duplicate_Order;
