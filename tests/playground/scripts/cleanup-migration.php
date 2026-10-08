<?php
require __DIR__ . '/_bootstrap.php';
\Nowera\ProductFeeds\Feeds::delete( 'fold123' );
delete_option( 'nwr_pf_legacy_slugs' );
nwr_out( array( 'feeds' => array_keys( \Nowera\ProductFeeds\Feeds::all() ) ) );
