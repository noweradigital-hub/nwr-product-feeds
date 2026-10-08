<?php
// Drop guard decisions: ?do=publish|discard&feed=ID
require __DIR__ . '/_bootstrap.php';
use Nowera\ProductFeeds\Jobs\Generator;
$feed = (string) nwr_arg( 'feed' );
$ok   = 'publish' === nwr_arg( 'do' ) ? Generator::publish_pending( $feed ) : ( Generator::discard_pending( $feed ) || true );
nwr_out( array( 'ok' => $ok, 'file' => nwr_feed_file( $feed ), 'run' => \Nowera\ProductFeeds\State::run( $feed, true ) ) );
