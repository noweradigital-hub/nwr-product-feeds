<?php
namespace Nowera\ProductFeeds;

defined( 'ABSPATH' ) || exit;

/**
 * Feeds migrated from version 1.0.0 had dynamic URLs
 * /produkty-feed/<slug>.<ext>. They now answer with a 301 to the static file,
 * so Merchant Center and Commerce Manager keep fetching until the URL is
 * updated there. Active only on sites that had such feeds.
 */
final class Legacy_Redirect {

	const OPTION = 'nwr_pf_legacy_slugs';
	const BASE   = 'produkty-feed';

	public function register(): void {
		$map = get_option( self::OPTION );
		if ( is_array( $map ) && $map ) {
			add_action( 'init', array( $this, 'maybe_redirect' ), 1 );
		}
	}

	public function maybe_redirect(): void {
		$slug = '';
		if ( isset( $_GET['nwr_pf_feed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$slug = sanitize_title( wp_unslash( (string) $_GET['nwr_pf_feed'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} else {
			$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
			$base = trailingslashit( (string) ( wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?: '/' ) ) . self::BASE . '/';
			if ( ! str_starts_with( $path, $base ) ) {
				return;
			}
			if ( preg_match( '#^([a-z0-9%_-]+?)(?:\.(?:xml|csv|tsv))?/?$#i', substr( $path, strlen( $base ) ), $m ) ) {
				$slug = strtolower( $m[1] );
			}
		}
		if ( '' === $slug ) {
			return;
		}

		$map  = (array) get_option( self::OPTION );
		$feed = isset( $map[ $slug ] ) ? Feeds::get( (string) $map[ $slug ] ) : null;
		if ( ! $feed || empty( $feed['enabled'] ) || ! Storage::info( $feed ) ) {
			return;
		}
		wp_redirect( Storage::url( $feed ), 301, 'Produktove feedy' ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}
}
