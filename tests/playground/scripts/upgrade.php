<?php
// Simulates an update from 1.0.0: the next init must refresh the feeds soon.
require __DIR__ . '/_bootstrap.php';
use Nowera\ProductFeeds\Plugin;

as_unschedule_all_actions( 'nwr_pf_dirty', array(), 'nwr-product-feeds' );
delete_option( 'nwr_pf_dirty_at' );
update_option( Plugin::VERSION_OPTION, '1.0.0', true );
Plugin::instance()->init();

$pending = array();
foreach ( as_get_scheduled_actions( array( 'group' => 'nwr-product-feeds', 'hook' => 'nwr_pf_dirty', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 20 ) ) as $action ) {
	$pending[] = $action->get_schedule()->get_date()->getTimestamp() - time();
}
nwr_out(
	array(
		'version'    => get_option( Plugin::VERSION_OPTION ),
		'plugin'     => \Nowera\ProductFeeds\VERSION,
		'pending_in' => $pending,
	)
);
