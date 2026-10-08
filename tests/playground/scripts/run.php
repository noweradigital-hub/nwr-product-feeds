<?php
// Starts generation (?feed=ID or all) and runs the queued batches synchronously.
require __DIR__ . '/_bootstrap.php';
use Nowera\ProductFeeds\Feeds;
use Nowera\ProductFeeds\Jobs\Generator;

$which   = (string) nwr_arg( 'feed', 'all' );
$trigger = (string) nwr_arg( 'trigger', 'manual' );
$results = array();
foreach ( Feeds::all() as $id => $feed ) {
	if ( 'all' === $which || $which === $id ) {
		$results[ $id ] = Generator::request( $id, $trigger );
	}
}
$force = array_filter( explode( ',', (string) nwr_arg( 'force', '' ) ) );
$jobs  = '0' === nwr_arg( 'jobs', '1' ) ? array() : nwr_run_jobs( $force );

$files = array();
foreach ( Feeds::all() as $id => $feed ) {
	$files[ $id ] = nwr_feed_file( $id );
}
nwr_out(
	array(
		'requested' => $results,
		'jobs'      => $jobs,
		'files'     => $files,
		'memory'    => memory_get_peak_usage( true ),
	)
);
