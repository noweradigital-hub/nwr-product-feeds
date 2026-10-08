<?php
namespace Nowera\ProductFeeds;

defined( 'ABSPATH' ) || exit;

/**
 * Site-wide settings. Everything feed-specific lives in Feeds.
 */
final class Settings {

	const OPTION = 'nwr_pf_settings';

	const DEFAULTS = array(
		// Shared with tracking through nwr_pf_item_id(), so it is not a per-feed value.
		'id_template' => '{id}',
		// Minutes between a product change and the regeneration it triggers.
		'dirty_delay' => 10,
		// Parent products per background batch.
		'batch_size'  => 50,
		'log_size'    => 200,
		'updates'     => 1,
		'github_repo' => 'noweradigital-hub/nwr-product-feeds',
	);

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::DEFAULTS, is_array( $saved ) ? array_intersect_key( $saved, self::DEFAULTS ) : array() );
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function save( array $input ): array {
		$clean = self::sanitize( $input );
		update_option( self::OPTION, $clean, true );
		return $clean;
	}

	public static function sanitize( array $in ): array {
		$out = self::all();

		if ( isset( $in['id_template'] ) ) {
			$out['id_template'] = Item_Id::sanitize_template( (string) $in['id_template'] ) ?: '{id}';
		}
		if ( isset( $in['dirty_delay'] ) ) {
			$out['dirty_delay'] = min( 60, max( 1, (int) $in['dirty_delay'] ) );
		}
		if ( isset( $in['batch_size'] ) ) {
			$out['batch_size'] = min( 500, max( 5, (int) $in['batch_size'] ) );
		}
		if ( isset( $in['log_size'] ) ) {
			$out['log_size'] = min( 2000, max( 20, (int) $in['log_size'] ) );
		}
		if ( array_key_exists( 'updates', $in ) ) {
			$out['updates'] = empty( $in['updates'] ) ? 0 : 1;
		}
		if ( isset( $in['github_repo'] ) ) {
			$repo               = trim( sanitize_text_field( (string) $in['github_repo'] ), " /\t" );
			$out['github_repo'] = preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : self::DEFAULTS['github_repo'];
		}

		return $out;
	}
}
