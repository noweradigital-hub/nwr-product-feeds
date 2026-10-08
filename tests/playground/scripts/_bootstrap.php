<?php
// Shared bootstrap for the Playground test scripts. Never deploy these.
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
ini_set( 'display_errors', '1' );
error_reporting( E_ALL );

$GLOBALS['nwr_test_notices'] = array();
set_error_handler(
	static function ( $errno, $errstr, $file, $line ) {
		// Collect PHP notices/warnings from our plugin; the suite asserts there are none.
		if ( str_contains( (string) $file, 'nwr-product-feeds' ) ) {
			$GLOBALS['nwr_test_notices'][] = "{$errstr} @ " . basename( (string) $file ) . ":{$line}";
		}
		return false;
	}
);
register_shutdown_function(
	static function () {
		$e = error_get_last();
		if ( $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			echo "\nNWR_FATAL " . json_encode( $e, JSON_UNESCAPED_SLASHES );
		}
	}
);

require '/wordpress/wp-load.php';
header( 'Content-Type: application/json; charset=utf-8' );

function nwr_out( $data ): void {
	if ( is_array( $data ) ) {
		$data['_notices'] = $GLOBALS['nwr_test_notices'];
	}
	echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

function nwr_ids(): array {
	return (array) get_option( 'nwr_pf_test_ids', array() );
}

function nwr_arg( string $key, $default = '' ) {
	return isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : $default;
}

/** Runs due jobs of our group (and, once each, pending ones of the forced hooks). */
function nwr_run_jobs( array $force = array(), int $limit = 500 ): array {
	$runner    = ActionScheduler::runner();
	$store     = ActionScheduler::store();
	$processed = array();
	$done      = array();

	if ( $force ) {
		foreach ( $force as $hook ) {
			$ids = as_get_scheduled_actions( array( 'group' => 'nwr-product-feeds', 'hook' => $hook, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 50 ), 'ids' );
			foreach ( $ids as $id ) {
				$runner->process_action( $id, 'nwr-test' );
				$done[ $id ]  = true;
				$processed[] = array( 'id' => $id, 'hook' => $hook, 'status' => $store->get_status( $id ) );
			}
		}
	}

	for ( $round = 0; $round < $limit; $round++ ) {
		$ids = as_get_scheduled_actions(
			array(
				'group'        => 'nwr-product-feeds',
				'status'       => ActionScheduler_Store::STATUS_PENDING,
				'date'         => as_get_datetime_object(),
				'date_compare' => '<=',
				'per_page'     => 20,
				'orderby'      => 'date',
				'order'        => 'ASC',
			),
			'ids'
		);
		$ids = array_values( array_filter( $ids, static function ( $id ) use ( $store, $done ) {
			$hook = $store->fetch_action( $id )->get_hook();
			return empty( $done[ $id ] ) && in_array( $hook, array( 'nwr_pf_batch', 'nwr_pf_rerun' ), true );
		} ) );
		if ( ! $ids ) {
			break;
		}
		foreach ( $ids as $id ) {
			$action = $store->fetch_action( $id );
			$runner->process_action( $id, 'nwr-test' );
			$done[ $id ] = true;
			$logs        = ActionScheduler::logger()->get_logs( $id );
			$processed[] = array(
				'id'     => $id,
				'hook'   => $action->get_hook(),
				'args'   => $action->get_args(),
				'status' => $store->get_status( $id ),
				'log'    => $logs ? end( $logs )->get_message() : '',
			);
		}
	}
	return $processed;
}

function nwr_feed_file( string $feed_id ): array {
	$feed = \Nowera\ProductFeeds\Feeds::get( $feed_id );
	if ( ! $feed ) {
		return array();
	}
	$path = \Nowera\ProductFeeds\Storage::path( $feed );
	clearstatcache();
	return array(
		'url'    => \Nowera\ProductFeeds\Storage::url( $feed ),
		'path'   => $path,
		'exists' => is_file( $path ),
		'mtime'  => is_file( $path ) ? filemtime( $path ) : 0,
		'md5'    => is_file( $path ) ? md5_file( $path ) : '',
	);
}
