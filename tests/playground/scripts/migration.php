<?php
// Upgrade from 1.0.0 (pôvodný obchod): old feed shape → new feed, legacy URL map.
require __DIR__ . '/_bootstrap.php';
use Nowera\ProductFeeds\Feeds;
use Nowera\ProductFeeds\Migration;

$ids   = nwr_ids();
$feeds = get_option( 'nwr_pf_feeds', array() );
$feeds['fold123'] = array(
	'id'                 => 'fold123',
	'name'               => 'Starý Meta feed',
	'slug'               => 'meta-katalog',
	'enabled'            => 1,
	'template'           => 'meta',
	'format'             => 'xml',
	'items'              => 'variations',
	'include_cats'       => array(),
	'exclude_cats'       => array(),
	'exclude_outofstock' => 0,
	'exclude_hidden'     => 1,
	'price_tax'          => 'incl',
	'desc_source'        => 'short_then_long',
	'brand_fallback'     => 'JUST TO BE',
	'condition'          => 'new',
	'gpc_default'        => 'Health & Beauty',
	'gpc_map'            => array( $ids['cats']['vaky'] => '537 - Baby & Toddler' ),
	'label0'             => 'category',
	'label1'             => 'stock',
	'label2'             => 'price_band',
	'utm'                => '',
	'max_images'         => 10,
	'cache_ttl'          => 3600,
	'shipping_price'     => '',
	'created'            => 1757400000,
);
update_option( 'nwr_pf_feeds', $feeds, false );
set_transient( 'nwr_pf_' . md5( 'fold123' ), 'old body', 3600 );

Migration::run();

$feed = Feeds::get( 'fold123' );
nwr_out(
	array(
		'feed'      => $feed,
		'legacy'    => get_option( 'nwr_pf_legacy_slugs' ),
		'transient' => get_transient( 'nwr_pf_' . md5( 'fold123' ) ),
	)
);
