<?php
namespace Nowera\ProductFeeds\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Google product taxonomy for the category picker. Downloaded from Google
 * only when someone searches in the admin, then kept for 90 days.
 * The Slovak list has the same IDs as the English one.
 */
final class Taxonomy {

	const OPTION = 'nwr_pf_gpc_';
	const URL    = 'https://www.google.com/basepages/producttype/taxonomy-with-ids.%s.txt';
	const LOCALES = array( 'sk_SK' => 'sk-SK', 'cs_CZ' => 'cs-CZ', 'pl_PL' => 'pl-PL', 'hu_HU' => 'hu-HU', 'de_DE' => 'de-DE', 'de_AT' => 'de-AT', 'en_GB' => 'en-GB' );

	public function register(): void {
		add_action( 'wp_ajax_nwr_pf_gpc_search', array( $this, 'search' ) );
	}

	public static function locale(): string {
		return self::LOCALES[ get_locale() ] ?? 'en-US';
	}

	/**
	 * @return array<int,string>|null ID => path, null when not downloaded yet
	 */
	public static function items( bool $download = false ): ?array {
		$locale = self::locale();
		$stored = get_option( self::OPTION . $locale );
		if ( is_array( $stored ) && ! empty( $stored['items'] ) && ( ! $download || (int) $stored['fetched'] > time() - 90 * DAY_IN_SECONDS ) ) {
			return $stored['items'];
		}
		if ( ! $download ) {
			return null;
		}

		$items = self::fetch( $locale );
		if ( ! $items && 'en-US' !== $locale ) {
			$items = self::fetch( 'en-US' );
		}
		if ( ! $items ) {
			return is_array( $stored ) && ! empty( $stored['items'] ) ? $stored['items'] : null;
		}
		update_option(
			self::OPTION . $locale,
			array(
				'fetched' => time(),
				'items'   => $items,
			),
			false
		);
		return $items;
	}

	/** @return array<int,string> */
	private static function fetch( string $locale ): array {
		$response = wp_remote_get( sprintf( self::URL, $locale ), array( 'timeout' => 20 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		$items = array();
		foreach ( preg_split( '/\r\n|\n|\r/', (string) wp_remote_retrieve_body( $response ) ) ?: array() as $line ) {
			if ( preg_match( '/^(\d+) - (.+)$/u', trim( $line ), $m ) ) {
				$items[ (int) $m[1] ] = $m[2];
			}
		}
		return $items;
	}

	public static function label( string $id ): string {
		if ( '' === $id ) {
			return '';
		}
		$items = self::items();
		return $items && isset( $items[ (int) $id ] ) ? $id . ' – ' . $items[ (int) $id ] : $id;
	}

	/** Search field + hidden value; admin.js does the lookup. */
	public static function picker( string $name, string $value ): void {
		printf(
			'<span class="nwr-pf-gpc"><input type="hidden" name="%1$s" value="%2$s"><input type="search" class="nwr-pf-gpc-q" autocomplete="off" value="%3$s" placeholder="%4$s"><span class="nwr-pf-gpc-list" hidden></span></span>',
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( self::label( $value ) ),
			esc_attr__( 'Hľadať kategóriu Google…', 'nwr-product-feeds' )
		);
	}

	public function search(): void {
		check_ajax_referer( 'nwr_pf_ajax' );
		if ( ! current_user_can( 'edit_products' ) && ! current_user_can( Admin::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		$query = isset( $_GET['q'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ) : '';
		$items = self::items( true );
		if ( null === $items ) {
			wp_send_json_error( array( 'message' => __( 'Taxonómiu Google sa nepodarilo stiahnuť.', 'nwr-product-feeds' ) ), 502 );
		}

		$results = array();
		if ( preg_match( '/^\d+$/', $query ) ) {
			if ( isset( $items[ (int) $query ] ) ) {
				$results[] = array( 'id' => (int) $query, 'path' => $items[ (int) $query ] );
			}
		} elseif ( mb_strlen( $query ) >= 2 ) {
			$words = array_filter( explode( ' ', self::fold( $query ) ) );
			foreach ( $items as $id => $path ) {
				$haystack = self::fold( $path );
				foreach ( $words as $word ) {
					if ( ! str_contains( $haystack, $word ) ) {
						continue 2;
					}
				}
				// Prefer categories whose last level matches.
				$leaf      = self::fold( (string) strrchr( ' > ' . $path, '>' ) );
				$results[] = array(
					'id'    => $id,
					'path'  => $path,
					'score' => ( str_contains( $leaf, $words[ array_key_first( $words ) ] ) ? 0 : 1 ) * 1000 + substr_count( $path, '>' ),
				);
				if ( count( $results ) > 300 ) {
					break;
				}
			}
			usort( $results, static fn( $a, $b ): int => $a['score'] <=> $b['score'] );
			$results = array_slice( $results, 0, 30 );
		}
		wp_send_json_success( array_map( static fn( $r ): array => array( 'id' => $r['id'], 'label' => $r['id'] . ' – ' . $r['path'] ), $results ) );
	}

	private static function fold( string $text ): string {
		return strtolower( remove_accents( $text ) );
	}
}
