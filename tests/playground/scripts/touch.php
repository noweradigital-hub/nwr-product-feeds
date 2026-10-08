<?php
// Changes a product like an admin or an order would; reports what got scheduled.
require __DIR__ . '/_bootstrap.php';
$ids     = nwr_ids();
$key     = (string) nwr_arg( 'product', 'simple' );
$price   = (string) nwr_arg( 'price', '' );
$product = wc_get_product( $ids[ $key ] );
if ( '' !== $price ) {
	$product->set_regular_price( $price );
	$product->save();
}
// Stock setup: ?manage=1&qty=0&backorders=yes (goods on order) or ?manage=0&status=onbackorder.
if ( '' !== nwr_arg( 'manage' ) ) {
	$product->set_manage_stock( '1' === nwr_arg( 'manage' ) );
	if ( '' !== nwr_arg( 'qty' ) ) {
		$product->set_stock_quantity( (int) nwr_arg( 'qty' ) );
	}
	if ( '' !== nwr_arg( 'backorders' ) ) {
		$product->set_backorders( (string) nwr_arg( 'backorders' ) );
	}
	if ( '' !== nwr_arg( 'status' ) ) {
		$product->set_stock_status( (string) nwr_arg( 'status' ) );
	}
	$product->save();
}
if ( nwr_arg( 'stock' ) ) {
	wc_update_product_stock( $product, (int) nwr_arg( 'stock' ), 'set' );
}
// Change tracking queues on shutdown; run it now so this response can report it.
do_action( 'shutdown' );
$pending = as_get_scheduled_actions( array( 'group' => 'nwr-product-feeds', 'hook' => 'nwr_pf_dirty', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 20 ) );
$dates   = array();
foreach ( $pending as $action ) {
	$dates[] = $action->get_schedule()->get_date()->getTimestamp() - time();
}
$product = wc_get_product( $ids[ $key ] );
nwr_out( array( 'dirty_at' => (int) get_option( 'nwr_pf_dirty_at' ), 'now' => time(), 'pending_in' => $dates, 'stock_status' => $product->get_stock_status() ) );
