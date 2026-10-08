<?php
// Product "Feedy" tab: render, save with the tab present, save without it (BrikPanel-like partial form).
require __DIR__ . '/_bootstrap.php';
require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
require_once WC_ABSPATH . 'includes/admin/class-wc-admin-meta-boxes.php';
use Nowera\ProductFeeds\Admin\Product_Panel;

wp_set_current_user( 1 );
$ids   = nwr_ids();
$panel = new Product_Panel();
$out   = array();

// Render the product tab and a variation block.
global $post, $product_object;
$post           = get_post( $ids['classic'] );
$product_object = wc_get_product( $ids['classic'] );
ob_start();
$panel->panel();
$out['panel_html'] = ob_get_clean();
ob_start();
$panel->variation( 0, array(), get_post( $ids['classic_modra'] ) );
$out['variation_html'] = ob_get_clean();
$out['tabs'] = array_keys( $panel->tab( array() ) );

// Save with the marker.
$product = wc_get_product( $ids['simple'] );
$_POST   = array(
	'nwr_pf_panel'           => '1',
	'_nwr_pf_title'          => 'Osuška pre feed <b>tučná</b>',
	'_nwr_pf_gtin'           => '4006381333931',
	'_nwr_pf_mpn'            => 'MPN-1',
	'_nwr_pf_gender'         => 'unisex',
	'_nwr_pf_age_group'      => 'kids',
	'_nwr_pf_gpc'            => '537 - Baby & Toddler',
	'_nwr_pf_label_2'        => 'leto',
	'_nwr_pf_exclude_feeds'  => array( '', 'neexistujuci' ),
);
$panel->save_product( $product );
$product->save();
$fresh               = wc_get_product( $ids['simple'] );
$out['after_save']   = array(
	'title'  => $fresh->get_meta( '_nwr_pf_title' ),
	'gtin'   => $fresh->get_meta( '_nwr_pf_gtin' ),
	'gpc'    => $fresh->get_meta( '_nwr_pf_gpc' ),
	'label2' => $fresh->get_meta( '_nwr_pf_label_2' ),
	'gender' => $fresh->get_meta( '_nwr_pf_gender' ),
	'feeds'  => $fresh->get_meta( '_nwr_pf_exclude_feeds' ),
);

// Invalid GTIN is refused, the rest saved.
$_POST = array( 'nwr_pf_panel' => '1', '_nwr_pf_title' => 'Osuška pre feed', '_nwr_pf_gtin' => '1234567890123', '_nwr_pf_gender' => 'unisex', '_nwr_pf_age_group' => 'kids', '_nwr_pf_gpc' => '537', '_nwr_pf_label_2' => 'leto' );
$panel->save_product( $fresh );
$fresh->save();
$again                     = wc_get_product( $ids['simple'] );
$out['invalid_gtin_kept']  = $again->get_meta( '_nwr_pf_gtin' );
$out['meta_box_errors']    = WC_Admin_Meta_Boxes::$meta_box_errors;

// Save WITHOUT the marker (editor that did not render the tab): nothing may change.
$_POST = array( '_nwr_pf_title' => '' );
$panel->save_product( $again );
$again->save();
$out['without_marker_title'] = wc_get_product( $ids['simple'] )->get_meta( '_nwr_pf_title' );

// Variation save with its own marker.
$variation = wc_get_product( $ids['classic_ruzova'] );
$_POST     = array( 'nwr_pf_var' => array( 3 => array( 'nwr_pf_panel' => '1', '_nwr_pf_mpn' => 'VAR-MPN', '_nwr_pf_exclude' => 'yes' ) ) );
$panel->save_variation( $variation, 3 );
$variation->save();
$v = wc_get_product( $ids['classic_ruzova'] );
$out['variation_saved'] = array( 'mpn' => $v->get_meta( '_nwr_pf_mpn' ), 'exclude' => $v->get_meta( '_nwr_pf_exclude' ) );
// And undo it (unchecked checkbox = absent key).
$_POST = array( 'nwr_pf_var' => array( 3 => array( 'nwr_pf_panel' => '1', '_nwr_pf_mpn' => '' ) ) );
$panel->save_variation( $v, 3 );
$v->save();
$w = wc_get_product( $ids['classic_ruzova'] );
$out['variation_cleared'] = array( 'mpn' => $w->get_meta( '_nwr_pf_mpn' ), 'exclude' => $w->get_meta( '_nwr_pf_exclude' ) );

// Restore the simple product so later feed assertions stay stable.
foreach ( array( '_nwr_pf_title', '_nwr_pf_gtin', '_nwr_pf_mpn', '_nwr_pf_gender', '_nwr_pf_age_group', '_nwr_pf_gpc', '_nwr_pf_label_2' ) as $key ) {
	delete_post_meta( $ids['simple'], $key );
}
$_POST = array();
nwr_out( $out );
