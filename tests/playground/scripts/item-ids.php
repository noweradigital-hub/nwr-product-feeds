<?php
// nwr_pf_item_id() for every seeded product and variation.
require __DIR__ . '/_bootstrap.php';
$out = array();
foreach ( nwr_ids() as $key => $id ) {
	if ( is_int( $id ) && $id > 0 && ( $product = wc_get_product( $id ) ) ) {
		$out[ $key ] = array( 'wc' => $id, 'item' => nwr_pf_item_id( $product ), 'sku' => $product->get_sku(), 'own_sku' => $product->get_sku( 'edit' ) );
	}
}
$feeds = (array) get_option( 'nwr_pf_test_feeds', array() );
if ( ! empty( $feeds['google'] ) ) {
	$out['_google_scoped_simple'] = nwr_pf_item_id( wc_get_product( nwr_ids()['simple'] ), $feeds['google'] );
}
nwr_out( $out );
