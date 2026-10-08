<?php
namespace Nowera\ProductFeeds\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Which products exist — read straight from the posts table.
 *
 * Themes and plugins rewrite product queries in pre_get_posts (Woodmart's
 * "show single variations" adds variations to `product` queries and drops
 * their variable parents; WPC does the same). WP_Query, wc_get_products()
 * and even suppress_filters therefore return a different catalog than the
 * shop really has. Plain SQL does not go through those hooks.
 */
final class Source {

	/** Statuses read so that unpublished products show up in the report. */
	const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * Next parent products after $after_id (keyset pagination).
	 *
	 * @return list<array{ID:int,post_status:string,locked:bool}>
	 */
	public static function parents( int $after_id, int $limit ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_status, post_password <> '' AS locked
				FROM {$wpdb->posts}
				WHERE post_type = 'product'
					AND post_status IN ('" . implode( "','", self::STATUSES ) . "')
					AND ID > %d
				ORDER BY ID ASC
				LIMIT %d",
				$after_id,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'ID'          => (int) $row['ID'],
				'post_status' => (string) $row['post_status'],
				'locked'      => (bool) (int) $row['locked'],
			),
			(array) $rows
		);
	}

	/**
	 * Variations of the given parents in their shop order. Disabled variations
	 * are stored as `private` and come back too, so they can be reported.
	 *
	 * @return array<int,list<array{ID:int,post_status:string}>> parent ID => variations
	 */
	public static function variations( array $parent_ids ): array {
		global $wpdb;

		$parent_ids = array_values( array_filter( array_map( 'intval', $parent_ids ) ) );
		if ( ! $parent_ids ) {
			return array();
		}

		$rows = $wpdb->get_results(
			"SELECT ID, post_parent, post_status
			FROM {$wpdb->posts}
			WHERE post_type = 'product_variation'
				AND post_status IN ('publish','private')
				AND post_parent IN (" . implode( ',', $parent_ids ) . ')
			ORDER BY post_parent ASC, menu_order ASC, ID ASC',
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['post_parent'] ][] = array(
				'ID'          => (int) $row['ID'],
				'post_status' => (string) $row['post_status'],
			);
		}
		return $out;
	}

	/** Loads posts, terms and meta of a batch in a few queries. */
	public static function prime( array $ids, bool $terms = true ): void {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, $terms, true );
		}
	}

	/**
	 * Catalog size by SQL, for the report and the tests: published parents by
	 * product type, and published variations whose parent is published.
	 */
	public static function census(): array {
		global $wpdb;

		$by_type = $wpdb->get_results(
			"SELECT t.slug AS type, COUNT(DISTINCT p.ID) AS n
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
			INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			WHERE p.post_type = 'product' AND p.post_status = 'publish'
			GROUP BY t.slug",
			ARRAY_A
		);

		$variations = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$wpdb->posts} v
			INNER JOIN {$wpdb->posts} p ON p.ID = v.post_parent AND p.post_type = 'product' AND p.post_status = 'publish'
			WHERE v.post_type = 'product_variation' AND v.post_status = 'publish'"
		);

		$types = array();
		foreach ( (array) $by_type as $row ) {
			$types[ (string) $row['type'] ] = (int) $row['n'];
		}
		ksort( $types );

		return array(
			'products'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" ),
			'types'      => $types,
			'variations' => $variations,
		);
	}
}
