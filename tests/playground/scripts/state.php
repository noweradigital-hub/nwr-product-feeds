<?php
// Run state, report, file and log of every feed, plus our pending actions.
require __DIR__ . '/_bootstrap.php';
use Nowera\ProductFeeds\Feeds;
use Nowera\ProductFeeds\Log;
use Nowera\ProductFeeds\State;

$feeds = array();
foreach ( Feeds::all() as $id => $feed ) {
	$feeds[ $id ] = array(
		'feed'   => $feed,
		'run'    => State::run( $id, true ),
		'report' => State::report( $id ),
		'file'   => nwr_feed_file( $id ),
		'seen'   => count( State::seen( $id ) ),
	);
}
$pending = array();
foreach ( as_get_scheduled_actions( array( 'group' => 'nwr-product-feeds', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 100 ) ) as $action_id => $action ) {
	$date      = $action->get_schedule()->get_date();
	$pending[] = array(
		'id'   => $action_id,
		'hook' => $action->get_hook(),
		'args' => $action->get_args(),
		'in'   => $date ? $date->getTimestamp() - time() : null,
		'recurring' => $action->get_schedule()->is_recurring(),
	);
}
nwr_out(
	array(
		'feeds'     => $feeds,
		'pending'   => $pending,
		'dirty_at'  => (int) get_option( 'nwr_pf_dirty_at', 0 ),
		'now'       => time(),
		'log'       => array_slice( Log::entries(), 0, 60 ),
		'settings'  => \Nowera\ProductFeeds\Settings::all(),
	)
);
