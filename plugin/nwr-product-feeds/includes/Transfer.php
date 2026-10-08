<?php
namespace Nowera\ProductFeeds;

use InvalidArgumentException;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Export and import of all settings as JSON, to copy a setup between shops.
 * Term IDs differ between sites, so categories and tags travel with their
 * slugs and names and are matched again on import. URL tokens never travel.
 */
final class Transfer {

	const FORMAT = 'nwr-product-feeds';

	public static function export(): array {
		$feeds = array();
		foreach ( Feeds::all() as $feed ) {
			$copy = $feed;
			unset( $copy['token'], $copy['created'], $copy['modified'] );
			$copy['_terms'] = self::describe_terms( $feed );
			$feeds[]        = $copy;
		}
		$settings = Settings::all();
		return array(
			'format'      => self::FORMAT,
			'version'     => VERSION,
			'exported_at' => gmdate( 'c' ),
			'site'        => home_url( '/' ),
			'settings'    => $settings,
			'feeds'       => $feeds,
		);
	}

	/**
	 * @return array{feeds:int,unmatched:list<string>}
	 */
	public static function import( array $data, bool $replace, bool $with_settings ): array {
		if ( self::FORMAT !== ( $data['format'] ?? '' ) || ! isset( $data['feeds'] ) || ! is_array( $data['feeds'] ) ) {
			throw new InvalidArgumentException( __( 'Súbor nie je export z pluginu Produktové feedy.', 'nwr-product-feeds' ) );
		}

		if ( $with_settings && isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			Settings::save( $data['settings'] );
		}
		if ( $replace ) {
			foreach ( array_keys( Feeds::all() ) as $id ) {
				Feeds::delete( (string) $id );
			}
		}

		$unmatched = array();
		$count     = 0;
		foreach ( $data['feeds'] as $feed ) {
			if ( ! is_array( $feed ) || empty( $feed['channel'] ) ) {
				continue;
			}
			$terms = isset( $feed['_terms'] ) && is_array( $feed['_terms'] ) ? $feed['_terms'] : array();
			$remap = static function ( array $ids, string $taxonomy ) use ( $terms, &$unmatched ): array {
				$out = array();
				foreach ( $ids as $id ) {
					$local = self::local_term( $terms[ $taxonomy ][ $id ] ?? null, $taxonomy );
					if ( $local ) {
						$out[] = $local;
					} else {
						$unmatched[] = $taxonomy . ':' . ( $terms[ $taxonomy ][ $id ]['slug'] ?? $id );
					}
				}
				return $out;
			};

			foreach ( array( 'include_cats', 'exclude_cats' ) as $key ) {
				$feed[ $key ] = $remap( array_map( 'intval', (array) ( $feed[ $key ] ?? array() ) ), 'product_cat' );
			}
			foreach ( array( 'include_tags', 'exclude_tags' ) as $key ) {
				$feed[ $key ] = $remap( array_map( 'intval', (array) ( $feed[ $key ] ?? array() ) ), 'product_tag' );
			}
			foreach ( array( 'gpc_map', 'fbc_map' ) as $key ) {
				$map = array();
				foreach ( (array) ( $feed[ $key ] ?? array() ) as $term_id => $value ) {
					$local = $remap( array( (int) $term_id ), 'product_cat' );
					if ( $local ) {
						$map[ $local[0] ] = $value;
					}
				}
				$feed[ $key ] = $map;
			}
			// Product IDs differ between shops.
			$feed['exclude_ids'] = array();

			unset( $feed['id'], $feed['token'], $feed['_terms'] );
			Feeds::save( $feed );
			++$count;
		}

		$unmatched = array_values( array_unique( $unmatched ) );
		Log::info( '', sprintf( 'Import nastavení: %1$d feedov%2$s.', $count, $unmatched ? ', nenájdené termíny: ' . implode( ', ', $unmatched ) : '' ) );
		return array(
			'feeds'     => $count,
			'unmatched' => $unmatched,
		);
	}

	private static function describe_terms( array $feed ): array {
		$groups = array(
			'product_cat' => array_merge( $feed['include_cats'], $feed['exclude_cats'], array_keys( $feed['gpc_map'] ), array_keys( $feed['fbc_map'] ) ),
			'product_tag' => array_merge( $feed['include_tags'], $feed['exclude_tags'] ),
		);
		$out = array();
		foreach ( $groups as $taxonomy => $ids ) {
			foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
				$term = get_term( $id, $taxonomy );
				if ( $term instanceof WP_Term ) {
					$out[ $taxonomy ][ $id ] = array(
						'slug' => $term->slug,
						'name' => $term->name,
					);
				}
			}
		}
		return $out;
	}

	private static function local_term( $described, string $taxonomy ): int {
		if ( ! is_array( $described ) ) {
			return 0;
		}
		foreach ( array( 'slug', 'name' ) as $field ) {
			if ( ! empty( $described[ $field ] ) ) {
				$term = get_term_by( $field, (string) $described[ $field ], $taxonomy );
				if ( $term instanceof WP_Term ) {
					return (int) $term->term_id;
				}
			}
		}
		return 0;
	}
}
