<?php
// Adds N simple products tagged "perf" (once) for the batch/memory test.
require __DIR__ . '/_bootstrap.php';
$n    = (int) nwr_arg( 'n', 300 );
$tag  = term_exists( 'perf', 'product_tag' ) ?: wp_insert_term( 'perf', 'product_tag' );

if ( nwr_arg( 'cleanup' ) ) {
	$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'tax_query' => array( array( 'taxonomy' => 'product_tag', 'terms' => array( (int) $tag['term_id'] ) ) ) ) );
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	\Nowera\ProductFeeds\Feeds::delete( (string) get_option( 'nwr_pf_test_perf_feed' ) );
	delete_option( 'nwr_pf_test_perf' );
	delete_option( 'nwr_pf_test_perf_feed' );
	nwr_out( array( 'deleted' => count( $ids ) ) );
	exit;
}
$have = (int) get_option( 'nwr_pf_test_perf', 0 );
$start = microtime( true );
wp_defer_term_counting( true );
for ( $i = $have; $i < $n; $i++ ) {
	$p = new WC_Product_Simple();
	$p->set_props(
		array(
			'name'              => 'Perf produkt ' . $i,
			'regular_price'     => (string) ( 10 + $i % 50 ),
			'tag_ids'           => array( (int) $tag['term_id'] ),
			'image_id'          => (int) get_post_thumbnail_id( nwr_ids()['simple'] ),
			'short_description' => 'Testovací produkt číslo ' . $i . ' na meranie dávok.',
			'sku'               => 'PERF-' . $i,
		)
	);
	$p->save();
}
wp_defer_term_counting( false );
update_option( 'nwr_pf_test_perf', max( $have, $n ), false );

$feed_id = (string) get_option( 'nwr_pf_test_perf_feed' );
if ( ! \Nowera\ProductFeeds\Feeds::get( $feed_id ) ) {
	$feed    = \Nowera\ProductFeeds\Feeds::save( array( 'channel' => 'google', 'name' => 'Perf', 'slug' => 'perf', 'include_tags' => array( (int) $tag['term_id'] ), 'brand_default' => 'PERF' ) );
	$feed_id = $feed['id'];
	update_option( 'nwr_pf_test_perf_feed', $feed_id, false );
}
nwr_out( array( 'tag' => (int) $tag['term_id'], 'feed' => $feed_id, 'created' => max( 0, $n - $have ), 'seconds' => round( microtime( true ) - $start, 1 ) ) );
