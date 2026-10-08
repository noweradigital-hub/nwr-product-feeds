<?php
// Test switches: ?test=hijack|fail&value=0|1, ?setting=key&value=…, ?feed=ID&field=key&value=… (JSON allowed).
require __DIR__ . '/_bootstrap.php';
use Nowera\ProductFeeds\Feeds;
use Nowera\ProductFeeds\Settings;

$value = nwr_arg( 'value', '' );
$json  = json_decode( (string) $value, true );
$value = null !== $json && ! is_numeric( $value ) ? $json : $value;
$out   = array();

if ( $test = (string) nwr_arg( 'test' ) ) {
	if ( in_array( $test, array( 'hijack', 'fail' ), true ) ) {
		update_option( 'nwr_pf_test_' . $test, (int) $value, false );
		$out[ $test ] = (int) get_option( 'nwr_pf_test_' . $test );
	}
}
if ( $setting = (string) nwr_arg( 'setting' ) ) {
	$out['settings'] = Settings::save( array( $setting => $value ) );
}
if ( ( $feed_id = (string) nwr_arg( 'feed' ) ) && ( $field = (string) nwr_arg( 'field' ) ) ) {
	$out['feed'] = Feeds::save( array( $field => $value ), $feed_id );
}
nwr_out( $out );
