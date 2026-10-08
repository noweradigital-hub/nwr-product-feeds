<?php
namespace Nowera\ProductFeeds\Catalog;

use WC_Product;
use WC_Product_Variable;

defined( 'ABSPATH' ) || exit;

/**
 * Product types a feed can contain.
 *
 * Simple products and variations go in by default. Composite and bundle
 * products are optional and need a fixed price above zero. Gift cards,
 * external and grouped products stay out unless a feed enables them.
 */
final class Product_Types {

	/** Types priced from the customer's selection unless they have a fixed price. */
	const BUNDLES = array( 'composite', 'bundle', 'woosb', 'yith_bundle', 'mix-and-match', 'wooco' );

	/** Types that never make a catalog item on their own. */
	const NEVER = array( 'grouped' );

	/** Type key used by the feed settings (gift cards flagged on simple products count as gift cards). */
	public static function key( WC_Product $product ): string {
		$type = $product->get_type();
		if ( 'simple' === $type && 'yes' === $product->get_meta( '_gift_card', true, 'edit' ) ) {
			return 'gift-card';
		}
		if ( $product instanceof WC_Product_Variable ) {
			return 'variable';
		}
		return $type;
	}

	public static function allowed( array $feed, WC_Product $product ): bool {
		$key = self::key( $product );
		if ( in_array( $key, self::NEVER, true ) ) {
			return false;
		}
		return in_array( $key, (array) $feed['types'], true );
	}

	public static function is_bundle( WC_Product $product ): bool {
		return in_array( $product->get_type(), self::BUNDLES, true );
	}

	/**
	 * Whether a composite/bundle has one price that does not depend on what
	 * the customer picks. Filter: nwr_pf_has_fixed_price.
	 */
	public static function has_fixed_price( WC_Product $product ): bool {
		$fixed = (float) $product->get_price() > 0;
		$id    = $product->get_id();

		switch ( $product->get_type() ) {
			case 'composite':
				// WPC Composite Products: "exclude" = price is the sum of the chosen components.
				if ( 'exclude' === get_post_meta( $id, 'wooco_pricing', true ) ) {
					$fixed = false;
				}
				break;
			case 'woosb':
				// WPC Product Bundles computes the price from the items unless "fixed price" is on.
				if ( 'on' !== get_post_meta( $id, 'woosb_disable_auto_price', true ) ) {
					$fixed = false;
				}
				break;
		}

		return (bool) apply_filters( 'nwr_pf_has_fixed_price', $fixed, $product );
	}

	/**
	 * Types for the feed editor: key => [label, count of published products].
	 */
	public static function choices(): array {
		global $wpdb;

		$labels = wc_get_product_types();
		unset( $labels['variable'] );
		$choices = array(
			'simple'   => array( $labels['simple'] ?? 'Simple', 0 ),
			'variable' => array( __( 'Variabilný produkt (vo feede jeho variácie)', 'nwr-product-feeds' ), 0 ),
		);
		foreach ( $labels as $key => $label ) {
			if ( ! isset( $choices[ $key ] ) && ! in_array( $key, self::NEVER, true ) ) {
				$choices[ $key ] = array( $label, 0 );
			}
		}

		$rows = $wpdb->get_results(
			"SELECT t.slug AS type, COUNT(DISTINCT p.ID) AS n
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
			INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			WHERE p.post_type = 'product' AND p.post_status = 'publish'
			GROUP BY t.slug",
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$key = 'variable-subscription' === $row['type'] ? 'variable' : (string) $row['type'];
			if ( in_array( $key, self::NEVER, true ) ) {
				continue;
			}
			if ( ! isset( $choices[ $key ] ) ) {
				$choices[ $key ] = array( $key, 0 );
			}
			$choices[ $key ][1] += (int) $row['n'];
		}

		$gift_cards = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} m
			INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'product' AND p.post_status = 'publish'
			WHERE m.meta_key = '_gift_card' AND m.meta_value = 'yes'"
		);
		if ( $gift_cards ) {
			$choices['gift-card'] ??= array( __( 'Darčeková karta', 'nwr-product-feeds' ), 0 );
			$choices['gift-card'][1] += $gift_cards;
		}

		return $choices;
	}
}
