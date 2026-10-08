<?php
namespace Nowera\ProductFeeds\Jobs;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Notices product, price and stock changes. It only sets a flag; on shutdown
 * the Scheduler queues one delayed regeneration. Nothing heavy in save_post.
 */
final class Change_Tracker {

	/** Meta whose change alone (imports, sync plugins, quick edit) alters a feed item. */
	const WATCHED_META = array( '_price', '_regular_price', '_sale_price', '_sale_price_dates_from', '_sale_price_dates_to', '_stock', '_stock_status', '_manage_stock', '_backorders', '_thumbnail_id', '_product_image_gallery', '_global_unique_id', '_sku', '_elementor_edit_mode', '_yoast_wpseo_primary_product_cat' );

	const WATCHED_TAXONOMIES = array( 'product_cat', 'product_tag', 'product_brand', 'product_visibility', 'product_type' );

	private bool $marked = false;

	public function register(): void {
		$hooks = array(
			'woocommerce_new_product',
			'woocommerce_update_product',
			'woocommerce_delete_product',
			'woocommerce_trash_product',
			'woocommerce_new_product_variation',
			'woocommerce_update_product_variation',
			'woocommerce_delete_product_variation',
			'woocommerce_trash_product_variation',
			'woocommerce_product_set_stock',
			'woocommerce_variation_set_stock',
			'woocommerce_product_set_stock_status',
			'woocommerce_variation_set_stock_status',
			'woocommerce_product_set_visibility',
		);
		foreach ( $hooks as $hook ) {
			add_action( $hook, array( $this, 'changed' ) );
		}

		add_action( 'transition_post_status', array( $this, 'status' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'deleted' ), 10, 2 );
		add_action( 'added_post_meta', array( $this, 'meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'meta' ), 10, 3 );
		add_action( 'set_object_terms', array( $this, 'terms' ), 10, 4 );
		add_action( 'edited_term', array( $this, 'term' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'term' ), 10, 3 );
	}

	public function changed(): void {
		$this->mark();
	}

	public function status( $new_status, $old_status, $post ): void {
		if ( $new_status !== $old_status && $post instanceof WP_Post && self::is_product_type( $post->post_type ) ) {
			$this->mark();
		}
	}

	public function deleted( $post_id, $post = null ): void {
		if ( $post instanceof WP_Post && self::is_product_type( $post->post_type ) ) {
			$this->mark();
		}
	}

	public function meta( $meta_ids, $object_id, $meta_key ): void {
		if ( $this->marked || ! is_string( $meta_key ) ) {
			return;
		}
		if ( ! in_array( $meta_key, self::WATCHED_META, true ) && ! str_starts_with( $meta_key, '_nwr_pf_' ) ) {
			return;
		}
		if ( self::is_product_type( (string) get_post_type( (int) $object_id ) ) ) {
			$this->mark();
		}
	}

	public function terms( $object_id, $terms, $tt_ids, $taxonomy ): void {
		if ( ! $this->marked && self::watched_taxonomy( (string) $taxonomy ) && self::is_product_type( (string) get_post_type( (int) $object_id ) ) ) {
			$this->mark();
		}
	}

	/** Renamed or deleted categories, tags, brands and attribute terms change item texts. */
	public function term( $term_id, $tt_id, $taxonomy ): void {
		if ( self::watched_taxonomy( (string) $taxonomy ) ) {
			$this->mark();
		}
	}

	private static function watched_taxonomy( string $taxonomy ): bool {
		return in_array( $taxonomy, self::WATCHED_TAXONOMIES, true ) || str_starts_with( $taxonomy, 'pa_' );
	}

	private static function is_product_type( string $post_type ): bool {
		return 'product' === $post_type || 'product_variation' === $post_type;
	}

	private function mark(): void {
		if ( $this->marked ) {
			return;
		}
		$this->marked = true;
		add_action( 'shutdown', array( Scheduler::class, 'mark_dirty' ) );
	}
}
