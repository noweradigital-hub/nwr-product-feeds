<?php
// What the catalog looks like to SQL and to a (possibly hijacked) WP_Query.
require __DIR__ . '/_bootstrap.php';
use Nowera\ProductFeeds\Catalog\Source;

$query = new WP_Query(
	array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => 200,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'suppress_filters' => true,
	)
);
$types = array();
foreach ( $query->posts as $id ) {
	$types[ get_post_type( $id ) ] = ( $types[ get_post_type( $id ) ] ?? 0 ) + 1;
}
nwr_out(
	array(
		'census'   => Source::census(),
		'wp_query' => array( 'count' => count( $query->posts ), 'types' => $types, 'ids' => array_map( 'intval', $query->posts ) ),
		'hijack'   => (int) get_option( 'nwr_pf_test_hijack' ),
	)
);
