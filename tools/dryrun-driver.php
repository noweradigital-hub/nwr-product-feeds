<?php
// Read-only dry run: builds the items in memory, writes nothing (no options, files, jobs).
// Uses the site's own feed settings when the plugin is installed (bundle with --rename then).
use Nowera\ProductFeeds\Catalog\Item_Builder;
use Nowera\ProductFeeds\Catalog\Source;
use Nowera\ProductFeeds\Channels\Registry;
use Nowera\ProductFeeds\Feeds;
use Nowera\ProductFeeds\Output\Node;
use Nowera\ProductFeeds\State;

global $wpdb;
$started = microtime( true );
$options = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'nwr\\_pf\\_%' ORDER BY option_name" );
$make    = static fn( array $over ): array => Feeds::normalize( array_merge( Feeds::defaults(), $over ) );
$configs = array();
foreach ( (array) get_option( 'nwr_pf_feeds', array() ) as $id => $live ) {
	if ( is_array( $live ) && isset( $live['channel'] ) ) {
		$configs[ 'live_' . $live['slug'] ] = $make( $live );
		if ( '' === (string) $live['brand_default'] ) {
			$configs[ 'live_' . $live['slug'] . '_brand' ] = $make( array_merge( $live, array( 'brand_default' => 'DEMO' ) ) );
		}
	}
}
$configs['google'] = $make( array( 'id' => 'dryrun-google', 'channel' => 'google', 'name' => 'Google', 'slug' => 'google', 'brand_default' => 'DEMO', 'exclude_ids' => array() ) );
if ( ! isset( $configs['live_meta'] ) ) {
	$configs['meta'] = $make( array( 'id' => 'dryrun-meta', 'channel' => 'meta', 'name' => 'Meta', 'slug' => 'meta', 'brand_default' => 'DEMO' ) );
}

$empty = static function ( $value ) use ( &$empty ): bool {
	if ( $value instanceof Node ) {
		return ! array_filter( $value->children, static fn( $child ): bool => '' !== trim( (string) $child ) );
	}
	if ( is_array( $value ) ) {
		return ! $value || count( array_filter( $value, $empty ) ) > 0;
	}
	return '' === trim( (string) $value );
};

$out = array();
foreach ( $configs as $key => $feed ) {
	$channel = Registry::instance()->get( $feed['channel'] );
	$builder = new Item_Builder( $feed, $channel, array(), true );
	$cursor  = 0;
	$counts  = State::zero_counts();
	$skipped = array();
	$issues  = array();
	$ids     = array();
	$checks  = array( 'empty_fields' => array(), 'unprefixed' => array(), 'no_brand' => array(), 'not_in_stock' => array(), 'sale_price' => 0, 'quantity' => 0 );
	$samples = array();
	while ( $rows = Source::parents( $cursor, 50 ) ) {
		$batch = $builder->build( $rows );
		foreach ( $batch->counts as $k => $v ) {
			$counts[ $k ] += $v;
		}
		foreach ( $batch->skipped as [ $code, $id, $parent ] ) {
			$skipped[ $code ][] = $id . ( $parent ? '<' . $parent : '' );
		}
		foreach ( $batch->issues as [ $code, $id, $parent, $detail ] ) {
			$issues[ $code ][] = $id . ( $parent ? '<' . $parent : '' ) . ( 'brand_default' === $code ? ' ' . $detail : '' );
		}
		foreach ( $batch->items as $entry ) {
			$item   = $entry['item'];
			$fields = $entry['fields'];
			$ids[]  = $item['id'];
			foreach ( $fields as $name => $value ) {
				if ( $empty( $value ) ) {
					$checks['empty_fields'][] = $item['id'] . ':' . $name;
				}
				if ( ! str_starts_with( (string) $name, 'g:' ) ) {
					$checks['unprefixed'][ $name ] = true;
				}
			}
			if ( ! isset( $fields['g:brand'] ) ) {
				$checks['no_brand'][] = $item['id'];
			}
			if ( ! in_array( $fields['g:availability'], array( 'in stock', 'in_stock' ), true ) ) {
				$checks['not_in_stock'][ $item['id'] ] = array(
					'availability' => $fields['g:availability'],
					'date'         => $fields['g:availability_date'] ?? null,
					'quantity'     => $fields['g:quantity_to_sell_on_facebook'] ?? null,
					'stock'        => $item['stock_status'] . ( null !== $item['stock_quantity'] ? ' ' . $item['stock_quantity'] : '' ),
				);
			}
			$checks['sale_price'] += isset( $fields['g:sale_price'] ) ? 1 : 0;
			$checks['quantity']   += isset( $fields['g:quantity_to_sell_on_facebook'] ) ? 1 : 0;
			if ( in_array( (int) $item['product_id'], array( 49, 2025001012 ), true ) || ( 'variation' === $item['kind'] && ! $samples ) ) {
				$samples[ $item['id'] ] = array_map( static fn( $v ) => $v instanceof Node ? $v->children : ( is_array( $v ) ? array_map( static fn( $e ) => $e instanceof Node ? $e->children : $e, $v ) : $v ), $fields );
			}
		}
		$cursor = $batch->last_id;
	}
	foreach ( $issues as $code => $list ) {
		$issues[ $code ] = array( 'count' => count( $list ), 'ids' => array_slice( $list, 0, 20 ) );
	}
	$checks['unprefixed'] = array_keys( $checks['unprefixed'] );
	$out[ $key ] = array(
		'feed'       => array_intersect_key( $feed, array_flip( array( 'id', 'channel', 'brand_default', 'backorder', 'exclude_ids', 'exclude_cats', 'meta_quantity', 'extra_attributes' ) ) ),
		'counts'     => $counts,
		'skipped'    => $skipped,
		'issues'     => $issues,
		'unique_ids' => count( $ids ) === count( array_unique( $ids ) ),
		'checks'     => $checks,
		'samples'    => str_contains( $key, 'brand' ) ? array() : $samples,
	);
}
$out['census']        = Source::census();
$out['ms']            = (int) round( ( microtime( true ) - $started ) * 1000 );
$out['memory']        = size_format( memory_get_peak_usage( true ) );
$out['options_same']  = $options === $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'nwr\\_pf\\_%' ORDER BY option_name" );
$out['nwr_pf_options'] = $options;
return $out;
