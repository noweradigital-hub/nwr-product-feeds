<?php
// WooCommerce Multistore guard: our meta never travels to child shops.
require __DIR__ . '/_bootstrap.php';
$data = array(
	'meta'             => array( '_nwr_pf_title' => 'x', '_nwr_pf_exclude' => 'yes', '_sku' => 'A-1', '_global_unique_id' => '4006381333931' ),
	'_custom_metadata' => array( 'site-2' => array( '_nwr_pf_label_0' => 'l', 'farba' => 'modra' ) ),
	'variations'       => array( array( 'meta' => array( array( 'key' => '_nwr_pf_gtin', 'value' => '1' ), array( 'key' => '_price', 'value' => '9' ) ) ) ),
);
add_filter( 'nwr_pf_multistore_shared_meta', static fn() => array( '_nwr_pf_exclude' ) );
nwr_out(
	array(
		'master' => apply_filters( 'wc_multistore_master_product_data', $data, null ),
		'child'  => apply_filters( 'wc_multistore_child_product_data', new stdClass() ),
	)
);
