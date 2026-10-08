<?php
namespace Nowera\ProductFeeds\Catalog;

use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Product categories: the product's main category, its path, and category-
 * based mappings (Google / Meta category).
 */
final class Categories {

	/** Primary category meta of SEO plugins, most common first. */
	const PRIMARY_META = array( '_yoast_wpseo_primary_product_cat', 'rank_math_primary_product_cat' );

	/** @var array<int,string> */
	private static array $paths = array();

	/**
	 * @param bool $named leave out WooCommerce's default category ("Uncategorized"),
	 *                    which says nothing about the product
	 * @return int[]
	 */
	public static function term_ids( int $product_id, bool $named = false ): array {
		$ids = array_map( 'intval', wc_get_product_term_ids( $product_id, 'product_cat' ) );
		if ( $named ) {
			$default = (int) get_option( 'default_product_cat', 0 );
			$ids     = array_values( array_filter( $ids, static fn( int $id ): bool => $id !== $default ) );
		}
		return $ids;
	}

	/**
	 * The primary category set in Yoast (or Rank Math) when the product is still
	 * in it, otherwise the deepest of its categories.
	 */
	public static function main_term( int $product_id ): ?WP_Term {
		$ids = self::term_ids( $product_id, true );
		if ( ! $ids ) {
			return null;
		}

		foreach ( self::PRIMARY_META as $key ) {
			$primary = (int) get_post_meta( $product_id, $key, true );
			if ( $primary && in_array( $primary, $ids, true ) ) {
				$term = get_term( $primary, 'product_cat' );
				if ( $term instanceof WP_Term ) {
					return $term;
				}
			}
		}

		$best       = null;
		$best_depth = -1;
		foreach ( $ids as $id ) {
			$term = get_term( $id, 'product_cat' );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$depth = count( get_ancestors( $id, 'product_cat', 'taxonomy' ) );
			if ( $depth > $best_depth ) {
				$best       = $term;
				$best_depth = $depth;
			}
		}
		return $best;
	}

	/** "Parent > Child > Grandchild" */
	public static function path( WP_Term $term ): string {
		if ( isset( self::$paths[ $term->term_id ] ) ) {
			return self::$paths[ $term->term_id ];
		}
		$names = array( Text::line( $term->name ) );
		foreach ( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, 'product_cat' );
			if ( $ancestor instanceof WP_Term ) {
				array_unshift( $names, Text::line( $ancestor->name ) );
			}
		}
		return self::$paths[ $term->term_id ] = implode( ' > ', $names );
	}

	/** Top-level category name of a term. */
	public static function top( WP_Term $term ): string {
		$ancestors = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );
		if ( ! $ancestors ) {
			return Text::line( $term->name );
		}
		$top = get_term( (int) end( $ancestors ), 'product_cat' );
		return $top instanceof WP_Term ? Text::line( $top->name ) : Text::line( $term->name );
	}

	/**
	 * First mapped value for a product: its main category, then that
	 * category's parents, then its other categories (each with parents).
	 *
	 * @param array<int|string,string> $map term ID => value
	 */
	public static function mapped( array $map, int $product_id ): string {
		if ( ! $map ) {
			return '';
		}
		$main  = self::main_term( $product_id );
		$order = $main ? array( $main->term_id ) : array();
		foreach ( self::term_ids( $product_id ) as $id ) {
			if ( ! in_array( $id, $order, true ) ) {
				$order[] = $id;
			}
		}
		foreach ( $order as $id ) {
			foreach ( array_merge( array( $id ), get_ancestors( $id, 'product_cat', 'taxonomy' ) ) as $candidate ) {
				if ( isset( $map[ $candidate ] ) && '' !== (string) $map[ $candidate ] ) {
					return (string) $map[ $candidate ];
				}
			}
		}
		return '';
	}

	/**
	 * Term IDs plus all their descendants (for include/exclude filters).
	 *
	 * @return int[]
	 */
	public static function with_children( array $term_ids, string $taxonomy ): array {
		$out = array();
		foreach ( $term_ids as $id ) {
			$id    = (int) $id;
			$out[] = $id;
			if ( is_taxonomy_hierarchical( $taxonomy ) ) {
				$children = get_term_children( $id, $taxonomy );
				if ( is_array( $children ) ) {
					$out = array_merge( $out, array_map( 'intval', $children ) );
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Categories in tree order for admin screens: list of [term, depth]. */
	public static function tree( string $taxonomy = 'product_cat' ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$children = array();
		foreach ( $terms as $term ) {
			$children[ (int) $term->parent ][] = $term;
		}
		$out  = array();
		$walk = static function ( int $parent, int $depth ) use ( &$walk, &$out, $children ): void {
			foreach ( $children[ $parent ] ?? array() as $term ) {
				$out[] = array( $term, $depth );
				$walk( (int) $term->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return $out;
	}
}
