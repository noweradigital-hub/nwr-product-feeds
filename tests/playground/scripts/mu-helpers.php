<?php
/**
 * Loaded as a mu-plugin in Playground only. Never deploy.
 *
 * - WP-Cron and Action Scheduler's async runner are off, so jobs run only
 *   when a test calls run-jobs.php (no races).
 * - Product types like WPC Composite and YITH Gift Cards.
 * - Switches set by set.php: a Woodmart-like pre_get_posts hijack and a
 *   forced failure inside generation.
 */

if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );

add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( 'WC_Product_Simple' ) ) {
			require_once __DIR__ . '/product-types.php';
		}
	},
	5
);

add_filter(
	'product_type_selector',
	static function ( $types ) {
		$types['composite'] = 'Composite (test)';
		$types['gift-card'] = 'Gift card (test)';
		return $types;
	}
);

// Woodmart "show single variations" + "hide variation parent": product queries get variations and lose variable parents.
add_action(
	'pre_get_posts',
	static function ( $query ) {
		if ( ! get_option( 'nwr_pf_test_hijack' ) ) {
			return;
		}
		$types = (array) $query->get( 'post_type' );
		if ( ! in_array( 'product', $types, true ) ) {
			return;
		}
		$query->set( 'post_type', array_values( array_unique( array_merge( $types, array( 'product_variation' ) ) ) ) );
		$tax   = (array) $query->get( 'tax_query' );
		$tax[] = array(
			'taxonomy' => 'product_type',
			'field'    => 'slug',
			'terms'    => array( 'variable' ),
			'operator' => 'NOT IN',
		);
		$query->set( 'tax_query', $tax );
	},
	5
);

add_filter(
	'nwr_pf_item',
	static function ( $item ) {
		if ( get_option( 'nwr_pf_test_fail' ) ) {
			throw new RuntimeException( 'Simulovaná chyba pri generovaní' );
		}
		return $item;
	}
);
