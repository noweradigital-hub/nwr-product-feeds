<?php
// Export → import round trip (terms matched by slug, tokens never copied).
require __DIR__ . '/_bootstrap.php';
use Nowera\ProductFeeds\Feeds;
use Nowera\ProductFeeds\Transfer;

$before = Feeds::all();
$export = Transfer::export();
$json   = wp_json_encode( $export );

// A category that does not exist on the "other" site.
$export['feeds'][0]['_terms']['product_cat'][999999] = array( 'slug' => 'neexistuje', 'name' => 'Neexistuje' );
$export['feeds'][0]['include_cats'][]                = 999999;

$result = Transfer::import( $export, false, false );
$after  = Feeds::all();
$new    = array_diff_key( $after, $before );

$bad = null;
try {
	Transfer::import( array( 'format' => 'nieco-ine' ), false, false );
} catch ( InvalidArgumentException $e ) {
	$bad = $e->getMessage();
}

$compare = array();
foreach ( $new as $id => $feed ) {
	$compare[] = array(
		'id'           => $id,
		'name'         => $feed['name'],
		'slug'         => $feed['slug'],
		'token_differs' => ! in_array( $feed['token'], array_column( $before, 'token' ), true ),
		'include_cats' => $feed['include_cats'],
		'gpc_map'      => $feed['gpc_map'],
		'labels'       => $feed['labels'],
	);
}
// Clean up the imported copies.
foreach ( array_keys( $new ) as $id ) {
	Feeds::delete( (string) $id );
}
nwr_out(
	array(
		'export_has_tokens' => str_contains( $json, '"token"' ),
		'export_format'     => $export['format'],
		'result'            => $result,
		'imported'          => $compare,
		'bad_import'        => $bad,
		'original'          => array_map( static fn( $f ) => array( 'include_cats' => $f['include_cats'], 'gpc_map' => $f['gpc_map'], 'name' => $f['name'] ), $before ),
	)
);
