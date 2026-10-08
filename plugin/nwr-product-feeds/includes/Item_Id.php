<?php
namespace Nowera\ProductFeeds;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Catalog item IDs. One place for feeds and tracking, so they cannot diverge.
 *
 * Tokens: {id} product/variation ID, {sku} the product's own SKU (falls back
 * to {id}), {parent_id} and {parent_sku} of a variation's parent (the product
 * itself otherwise). Anything else in the template is kept literally, e.g.
 * "wc_{id}" for a catalog that still uses an old prefix.
 */
final class Item_Id {

	const TOKENS = array( '{id}', '{sku}', '{parent_id}', '{parent_sku}' );

	public static function for_product( WC_Product $product, string $feed_id = '' ): string {
		$feed = '' !== $feed_id ? Feeds::get( $feed_id ) : null;
		// A feed without its own template uses the shared one and must return the same ID as tracking.
		$scope = ( $feed && '' !== $feed['id_template'] ) ? $feed_id : '';
		return self::apply_filter( self::render( $product, self::template( $scope ) ), $product, $scope );
	}

	public static function apply_filter( string $id, WC_Product $product, string $feed_id ): string {
		$filtered = trim( (string) apply_filters( 'nwr_pf_item_id', $id, $product, $feed_id ) );
		return '' !== $filtered ? $filtered : $id;
	}

	/** The feed's own template when it has one, else the site-wide one. */
	public static function template( string $feed_id = '' ): string {
		if ( '' !== $feed_id ) {
			$feed = Feeds::get( $feed_id );
			if ( $feed && '' !== $feed['id_template'] ) {
				return $feed['id_template'];
			}
		}
		$template = (string) Settings::get( 'id_template' );
		return '' !== $template ? $template : '{id}';
	}

	public static function render( WC_Product $product, string $template ): string {
		$id        = (string) $product->get_id();
		$parent_id = $product->get_parent_id() ? (string) $product->get_parent_id() : $id;

		$values = array(
			'{id}'         => $id,
			'{sku}'        => self::own_sku( $product ) ?: $id,
			'{parent_id}'  => $parent_id,
			'{parent_sku}' => $product->get_parent_id() ? ( self::stored_sku( $product->get_parent_id() ) ?: $parent_id ) : ( self::own_sku( $product ) ?: $id ),
		);

		$out = trim( strtr( $template, $values ) );
		return '' !== $out ? $out : $id;
	}

	/**
	 * The SKU stored on the product itself. A variation without its own SKU
	 * reports its parent's through get_sku() — using that would give every
	 * such variation the same ID, and Google and Meta reject the whole file.
	 */
	public static function own_sku( WC_Product $product ): string {
		$sku = trim( (string) $product->get_sku( 'edit' ) );
		if ( '' !== $sku && $product->get_parent_id() && $sku === self::stored_sku( $product->get_parent_id() ) ) {
			return '';
		}
		return $sku;
	}

	private static function stored_sku( int $post_id ): string {
		return trim( (string) get_post_meta( $post_id, '_sku', true ) );
	}

	/** Keeps printable text, drops markup and whitespace runs. */
	public static function sanitize_template( string $template ): string {
		$template = trim( preg_replace( '/\s+/', '', wp_strip_all_tags( $template ) ) ?? '' );
		if ( '' === $template ) {
			return '';
		}
		// Without {id} or {sku} every variation of a product (or every item) would share one ID.
		if ( ! str_contains( $template, '{id}' ) && ! str_contains( $template, '{sku}' ) ) {
			return '';
		}
		return mb_substr( $template, 0, 60 );
	}
}
