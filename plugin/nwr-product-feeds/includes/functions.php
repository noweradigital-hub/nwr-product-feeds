<?php
/**
 * Public API of Produktové feedy (global namespace).
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nwr_pf_item_id' ) ) {
	/**
	 * Catalog item ID of a product exactly as the feeds write it.
	 *
	 * Tracking (nowera-capi) sends the same value in content_ids, so a Meta or
	 * Google event always matches the catalog item:
	 * - simple product   → its item ID,
	 * - variation        → the variation's item ID,
	 * - variable product → the ID its variations carry as item_group_id.
	 *
	 * The ID template is a site-wide setting (default "{id}"). A feed may
	 * override it for a legacy catalog; pass that feed's ID to get its value.
	 * Filter: `nwr_pf_item_id` ( string $id, WC_Product $product, string $feed_id ).
	 */
	function nwr_pf_item_id( WC_Product $product, string $feed_id = '' ): string {
		return \Nowera\ProductFeeds\Item_Id::for_product( $product, $feed_id );
	}
}

if ( ! function_exists( 'nwr_pf_feed_url' ) ) {
	/** Public URL of a feed's static file ('' when the feed does not exist). */
	function nwr_pf_feed_url( string $feed_id ): string {
		$feed = \Nowera\ProductFeeds\Feeds::get( $feed_id );
		return $feed ? \Nowera\ProductFeeds\Storage::url( $feed ) : '';
	}
}
