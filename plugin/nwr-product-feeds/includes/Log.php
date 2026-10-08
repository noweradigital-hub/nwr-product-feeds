<?php
namespace Nowera\ProductFeeds;

defined( 'ABSPATH' ) || exit;

/**
 * Short activity log for the admin screen (ring buffer in one option).
 * Errors also go to WooCommerce → Status → Logs (source nwr-product-feeds).
 */
final class Log {

	const OPTION = 'nwr_pf_log';

	public static function info( string $feed_id, string $message ): void {
		self::add( 'info', $feed_id, $message );
	}

	public static function warning( string $feed_id, string $message ): void {
		self::add( 'warning', $feed_id, $message );
		self::to_woocommerce( 'warning', $feed_id, $message );
	}

	public static function error( string $feed_id, string $message ): void {
		self::add( 'error', $feed_id, $message );
		self::to_woocommerce( 'error', $feed_id, $message );
	}

	/** @return list<array{t:int,level:string,feed:string,msg:string}> newest first */
	public static function entries( string $feed_id = '' ): array {
		$entries = get_option( self::OPTION, array() );
		$entries = is_array( $entries ) ? array_reverse( $entries ) : array();
		if ( '' !== $feed_id ) {
			$entries = array_values( array_filter( $entries, static fn( $e ): bool => ( $e['feed'] ?? '' ) === $feed_id ) );
		}
		return $entries;
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}

	private static function add( string $level, string $feed_id, string $message ): void {
		$entries   = get_option( self::OPTION, array() );
		$entries   = is_array( $entries ) ? $entries : array();
		$entries[] = array(
			't'     => time(),
			'level' => $level,
			'feed'  => $feed_id,
			'msg'   => mb_substr( $message, 0, 500 ),
		);
		$max = max( 20, (int) Settings::get( 'log_size' ) );
		if ( count( $entries ) > $max ) {
			$entries = array_slice( $entries, -$max );
		}
		update_option( self::OPTION, $entries, false );
	}

	private static function to_woocommerce( string $level, string $feed_id, string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, ( '' !== $feed_id ? "[{$feed_id}] " : '' ) . $message, array( 'source' => SLUG ) );
		}
	}
}
