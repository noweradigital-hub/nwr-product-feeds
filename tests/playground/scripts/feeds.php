<?php
// Creates the four test feeds. Idempotent unless ?fresh=1.
require __DIR__ . '/_bootstrap.php';
use Nowera\ProductFeeds\Feeds;

$ids   = nwr_ids();
$saved = get_option( 'nwr_pf_test_feeds' );
if ( $saved && Feeds::get( $saved['google'] ) && ! nwr_arg( 'fresh' ) ) {
	nwr_out( $saved );
	exit;
}
foreach ( array_keys( Feeds::all() ) as $id ) {
	Feeds::delete( (string) $id );
}

$google = Feeds::save(
	array(
		'channel'       => 'google',
		'name'          => 'Google test',
		'slug'          => 'google',
		'brand_default' => 'DEMO',
		'utm'           => 'utm_source=google&utm_medium=product feed',
		'gpc_map'       => array( $ids['cats']['vaky'] => '537' ),
		'labels'        => array(
			array( 'rule' => 'category_top', 'param' => '' ),
			array( 'rule' => 'on_sale', 'param' => 'akcia|bez-akcie' ),
			array( 'rule' => 'price_band', 'param' => '30,60' ),
			array( 'rule' => 'attribute', 'param' => 'pa_nosnost' ),
			array( 'rule' => 'static', 'param' => 'test' ),
		),
	)
);
$meta = Feeds::save(
	array(
		'channel'        => 'meta',
		'name'           => 'Meta test',
		'slug'           => 'meta',
		'brand_default'  => 'DEMO',
		'internal_label' => 'both',
		'fbc_map'        => array( $ids['cats']['vaky'] => '12' ),
	)
);
$csv = Feeds::save(
	array(
		'channel'      => 'google',
		'name'         => 'Google CSV filtre',
		'slug'         => 'google-filtre',
		'format'       => 'csv',
		'types'        => array( 'simple', 'variable', 'composite' ),
		'include_cats' => array( $ids['cats']['deky'] ),
		'exclude_tags' => array( $ids['tags']['bestseller'] ),
		'price_min'    => '20',
		'shipping_price' => '3.90',
		'shipping_service' => 'Kuriér',
	)
);
$tsv = Feeds::save(
	array(
		'channel'      => 'meta',
		'name'         => 'Meta TSV bez DPH',
		'slug'         => 'meta-tsv',
		'format'       => 'tsv',
		'price_tax'    => 'excl',
		'out_of_stock' => 0,
		'types'        => array( 'simple' ),
		'brand_default' => 'DEMO',
	)
);

update_post_meta( $ids['meta_only_excluded'], '_nwr_pf_exclude_feeds', array( $meta['id'] ) );

$out = array(
	'google' => $google['id'],
	'meta'   => $meta['id'],
	'csv'    => $csv['id'],
	'tsv'    => $tsv['id'],
);
update_option( 'nwr_pf_test_feeds', $out, false );
nwr_out( $out );
