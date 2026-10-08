<?php
// Live sample from current settings (admin preview), nothing stored.
require __DIR__ . '/_bootstrap.php';
use Nowera\ProductFeeds\Feeds;
use Nowera\ProductFeeds\Jobs\Generator;
use Nowera\ProductFeeds\State;
$feeds  = (array) get_option( 'nwr_pf_test_feeds' );
$feed   = Feeds::get( $feeds[ nwr_arg( 'feed', 'google' ) ] );
$before = State::run( $feed['id'], true );
$sample = Generator::sample( $feed, (int) nwr_arg( 'n', 3 ) );
$after  = State::run( $feed['id'], true );
nwr_out(
	array(
		'count'     => count( $sample['items'] ),
		'scanned'   => $sample['scanned'],
		'body'      => $sample['body'],
		'unchanged' => $before === $after,
	)
);
