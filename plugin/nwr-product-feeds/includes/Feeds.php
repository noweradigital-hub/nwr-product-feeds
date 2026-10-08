<?php
namespace Nowera\ProductFeeds;

use Nowera\ProductFeeds\Catalog\Attributes;
use Nowera\ProductFeeds\Catalog\Labels;
use Nowera\ProductFeeds\Channels\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Feed definitions. A shop has a handful of feeds, so they share one
 * (non-autoloaded) option keyed by feed ID.
 */
final class Feeds {

	const OPTION      = 'nwr_pf_feeds';
	const LABEL_SLOTS = 5;
	const INTERVALS   = array( 15, 30, 60, 120, 180, 360, 720, 1440 );
	const HIDDEN      = array( 'skip', 'skip_search', 'include', 'archive' );
	const GENDERS     = array( 'male', 'female', 'unisex' );
	const AGE_GROUPS  = array( 'newborn', 'infant', 'toddler', 'kids', 'adult', 'all ages', 'teen' );

	public static function defaults(): array {
		return array(
			'id'                => '',
			'name'              => '',
			'slug'              => '',
			'token'             => '',
			'enabled'           => 1,
			'channel'           => 'google',
			'format'            => 'xml',
			'interval'          => 60,
			'country'           => '',

			// Which products.
			'types'             => array( 'simple', 'variable' ),
			'include_cats'      => array(),
			'exclude_cats'      => array(),
			'include_tags'      => array(),
			'exclude_tags'      => array(),
			'out_of_stock'      => 1,
			'hidden'            => 'skip',
			'price_min'         => '',
			'price_max'         => '',
			'exclude_ids'       => array(),

			// Item content.
			'id_template'       => '',
			'title_attributes'  => 1,
			'description_order' => 'short',
			'brand_default'     => '',
			'condition'         => 'new',
			'utm'               => '',
			'images_max'        => 0,
			'price_tax'         => 'incl',
			'mpn_from_sku'      => 1,
			'gtin_from_parent'  => 0,
			'backorder'         => 'backorder',
			'backorder_days'    => 14,
			'attribute_map'     => array_fill_keys( Attributes::FIELDS, 'auto' ),
			'gender_default'    => '',
			'age_group_default' => '',
			'extra_attributes'  => 1,

			// Categories.
			'gpc_default'       => '',
			'gpc_map'           => array(),
			'fbc_default'       => '',
			'fbc_map'           => array(),

			// custom_label_0–4.
			'labels'            => array_fill( 0, self::LABEL_SLOTS, array( 'rule' => '', 'param' => '' ) ),

			// Meta only.
			'meta_quantity'     => 1,
			'internal_label'    => '',

			// Optional shipping.
			'shipping_price'    => '',
			'shipping_service'  => '',
			'shipping_weight'   => 0,

			// A new file with far fewer items than the last one is held back for review (percent, 0 = off).
			'drop_guard'        => 50,

			'created'           => 0,
			'modified'          => 0,
		);
	}

	/** @return array<string,array> */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$feeds  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $id => $feed ) {
			if ( is_array( $feed ) && ! empty( $feed['id'] ) && ! empty( $feed['channel'] ) ) {
				$feeds[ (string) $id ] = self::normalize( $feed );
			}
		}
		return $feeds;
	}

	public static function get( string $id ): ?array {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/** @return array<string,array> */
	public static function enabled(): array {
		return array_filter( self::all(), static fn( array $feed ): bool => ! empty( $feed['enabled'] ) );
	}

	public static function normalize( array $feed ): array {
		$defaults = self::defaults();
		$feed     = array_merge( $defaults, array_intersect_key( $feed, $defaults ) );

		$feed['attribute_map'] = array_merge( $defaults['attribute_map'], array_intersect_key( (array) $feed['attribute_map'], $defaults['attribute_map'] ) );

		$labels = array_values( (array) $feed['labels'] );
		for ( $i = 0; $i < self::LABEL_SLOTS; $i++ ) {
			$slot                  = isset( $labels[ $i ] ) && is_array( $labels[ $i ] ) ? $labels[ $i ] : array();
			$feed['labels'][ $i ] = array(
				'rule'  => (string) ( $slot['rule'] ?? '' ),
				'param' => (string) ( $slot['param'] ?? '' ),
			);
		}
		$feed['labels'] = array_slice( $feed['labels'], 0, self::LABEL_SLOTS );

		foreach ( array( 'include_cats', 'exclude_cats', 'include_tags', 'exclude_tags', 'exclude_ids' ) as $key ) {
			$feed[ $key ] = array_values( array_filter( array_map( 'intval', (array) $feed[ $key ] ) ) );
		}
		$feed['types']   = array_values( array_map( 'strval', (array) $feed['types'] ) );
		$feed['gpc_map'] = array_map( 'strval', (array) $feed['gpc_map'] );
		$feed['fbc_map'] = array_map( 'strval', (array) $feed['fbc_map'] );
		return $feed;
	}

	/**
	 * Stores a feed built from untrusted input (admin form or import) on top of
	 * the existing definition. Returns the stored feed.
	 */
	public static function save( array $input, string $id = '' ): array {
		$existing = '' !== $id ? self::get( $id ) : null;
		$feed     = self::sanitize( $input, $existing );

		$all               = self::all();
		$all[ $feed['id'] ] = $feed;
		update_option( self::OPTION, $all, false );

		Jobs\Scheduler::ensure();
		return $feed;
	}

	public static function delete( string $id ): void {
		$feed = self::get( $id );
		if ( ! $feed ) {
			return;
		}
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );

		Jobs\Scheduler::unschedule_feed( $id );
		Storage::delete_feed_files( $feed );
		State::forget( $id );
		Log::info( $id, sprintf( 'Feed „%s“ vymazaný.', $feed['name'] ) );
	}

	public static function duplicate( string $id ): ?array {
		$feed = self::get( $id );
		if ( ! $feed ) {
			return null;
		}
		$copy         = $feed;
		$copy['id']   = '';
		$copy['name'] = sprintf( /* translators: %s: feed name */ __( '%s (kópia)', 'nwr-product-feeds' ), $feed['name'] );
		$copy['slug'] = $feed['slug'] . '-kopia';
		$copy['token'] = '';
		$copy['enabled'] = 0;
		return self::save( $copy );
	}

	/** New random part of the URL, e.g. after the old one leaked. */
	public static function rotate_token( string $id ): ?array {
		$feed = self::get( $id );
		if ( ! $feed ) {
			return null;
		}
		$all                      = self::all();
		$all[ $id ]['token']      = Storage::new_token();
		$all[ $id ]['modified']   = time();
		update_option( self::OPTION, $all, false );
		return $all[ $id ];
	}

	public static function sanitize( array $in, ?array $existing = null ): array {
		$feed     = $existing ? $existing : self::defaults();
		$registry = Registry::instance();

		if ( ! $existing ) {
			$channel = isset( $in['channel'] ) ? sanitize_key( (string) $in['channel'] ) : 'google';
			$channel = $registry->get( $channel ) ? $channel : 'google';
			$feed    = array_merge( $feed, $registry->get( $channel )->defaults() );
			$feed['id']      = 'f' . strtolower( wp_generate_password( 10, false ) );
			$feed['token']   = Storage::new_token();
			$feed['created'] = time();
		}

		$text = static fn( string $key, int $max ): string => mb_substr( sanitize_text_field( (string) ( $in[ $key ] ?? '' ) ), 0, $max );
		$flag = static fn( string $key ): int => empty( $in[ $key ] ) ? 0 : 1;
		$pick = static fn( string $key, array $allowed, $fallback ) => in_array( $in[ $key ] ?? null, $allowed, true ) ? $in[ $key ] : $fallback;

		if ( array_key_exists( 'name', $in ) ) {
			$feed['name'] = $text( 'name', 100 );
		}
		if ( '' === $feed['name'] ) {
			$feed['name'] = __( 'Produktový feed', 'nwr-product-feeds' );
		}
		if ( array_key_exists( 'slug', $in ) ) {
			$feed['slug'] = sanitize_title( (string) $in['slug'] );
		}
		if ( '' === $feed['slug'] ) {
			$feed['slug'] = sanitize_title( $feed['name'] );
		}
		// The slug is part of a file name: plain a–z, 0–9 and hyphens only.
		$feed['slug'] = trim( preg_replace( array( '/%[a-f0-9]{2}/i', '/[^a-z0-9-]+/', '/-{2,}/' ), array( '', '', '-' ), strtolower( remove_accents( $feed['slug'] ) ) ) ?? '', '-' );
		$feed['slug'] = self::unique_slug( substr( $feed['slug'], 0, 60 ), $feed['id'] );

		if ( ! empty( $in['token'] ) && ! $existing && preg_match( '/^[a-f0-9]{16,40}$/', (string) $in['token'] ) ) {
			$feed['token'] = (string) $in['token'];
		}
		if ( '' === $feed['token'] ) {
			$feed['token'] = Storage::new_token();
		}

		if ( array_key_exists( 'enabled', $in ) ) {
			$feed['enabled'] = $flag( 'enabled' );
		}
		if ( isset( $in['channel'] ) && $registry->get( sanitize_key( (string) $in['channel'] ) ) ) {
			$feed['channel'] = sanitize_key( (string) $in['channel'] );
		}
		$channel        = $registry->get( $feed['channel'] ) ?? $registry->get( 'google' );
		$feed['format'] = $pick( 'format', $channel->formats(), in_array( $feed['format'], $channel->formats(), true ) ? $feed['format'] : $channel->formats()[0] );
		if ( isset( $in['interval'] ) ) {
			$feed['interval'] = in_array( (int) $in['interval'], self::INTERVALS, true ) ? (int) $in['interval'] : 60;
		}
		if ( array_key_exists( 'country', $in ) ) {
			$country         = strtoupper( sanitize_key( (string) $in['country'] ) );
			$feed['country'] = ( '' !== $country && isset( WC()->countries->get_countries()[ $country ] ) ) ? $country : '';
		}

		if ( array_key_exists( 'types', $in ) ) {
			$feed['types'] = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $in['types'] ) ) ) );
		}
		foreach ( array( 'include_cats', 'exclude_cats', 'include_tags', 'exclude_tags' ) as $key ) {
			if ( array_key_exists( $key, $in ) ) {
				$feed[ $key ] = self::id_list( $in[ $key ] );
			}
		}
		if ( array_key_exists( 'exclude_ids', $in ) ) {
			$feed['exclude_ids'] = self::id_list( $in['exclude_ids'] );
		}
		if ( array_key_exists( 'out_of_stock', $in ) ) {
			$feed['out_of_stock'] = $flag( 'out_of_stock' );
		}
		$feed['hidden'] = $pick( 'hidden', self::HIDDEN, $feed['hidden'] );
		foreach ( array( 'price_min', 'price_max' ) as $key ) {
			if ( array_key_exists( $key, $in ) ) {
				$feed[ $key ] = self::decimal( $in[ $key ] );
			}
		}

		if ( array_key_exists( 'id_template', $in ) ) {
			$feed['id_template'] = Item_Id::sanitize_template( (string) $in['id_template'] );
		}
		foreach ( array( 'title_attributes', 'mpn_from_sku', 'gtin_from_parent', 'extra_attributes', 'meta_quantity', 'shipping_weight' ) as $key ) {
			if ( array_key_exists( $key, $in ) ) {
				$feed[ $key ] = $flag( $key );
			}
		}
		$feed['description_order'] = $pick( 'description_order', array( 'short', 'long' ), $feed['description_order'] );
		if ( array_key_exists( 'brand_default', $in ) ) {
			$feed['brand_default'] = $text( 'brand_default', 70 );
		}
		$feed['condition'] = $pick( 'condition', array( 'new', 'refurbished', 'used' ), $feed['condition'] );
		if ( array_key_exists( 'utm', $in ) ) {
			$feed['utm'] = self::query_string( (string) $in['utm'] );
		}
		if ( isset( $in['images_max'] ) ) {
			$feed['images_max'] = min( 20, max( 0, (int) $in['images_max'] ) );
		}
		$feed['price_tax'] = $pick( 'price_tax', array( 'incl', 'excl' ), $feed['price_tax'] );
		$feed['backorder'] = $pick( 'backorder', array( 'backorder', 'in_stock' ), $feed['backorder'] );
		if ( isset( $in['backorder_days'] ) ) {
			$feed['backorder_days'] = min( 365, max( 1, (int) $in['backorder_days'] ) );
		}

		if ( isset( $in['attribute_map'] ) && is_array( $in['attribute_map'] ) ) {
			foreach ( Attributes::FIELDS as $field ) {
				if ( isset( $in['attribute_map'][ $field ] ) ) {
					$feed['attribute_map'][ $field ] = Attributes::sanitize_source( (string) $in['attribute_map'][ $field ] );
				}
			}
		}
		$feed['gender_default']    = $pick( 'gender_default', array_merge( array( '' ), self::GENDERS ), $feed['gender_default'] );
		$feed['age_group_default'] = $pick( 'age_group_default', array_merge( array( '' ), self::AGE_GROUPS ), $feed['age_group_default'] );

		if ( array_key_exists( 'gpc_default', $in ) ) {
			$feed['gpc_default'] = self::gpc( $in['gpc_default'] );
		}
		if ( isset( $in['gpc_map'] ) && is_array( $in['gpc_map'] ) ) {
			$feed['gpc_map'] = array();
			foreach ( $in['gpc_map'] as $term_id => $value ) {
				$value = self::gpc( $value );
				if ( (int) $term_id > 0 && '' !== $value ) {
					$feed['gpc_map'][ (int) $term_id ] = $value;
				}
			}
		}
		if ( array_key_exists( 'fbc_default', $in ) ) {
			$feed['fbc_default'] = $text( 'fbc_default', 250 );
		}
		if ( isset( $in['fbc_map'] ) && is_array( $in['fbc_map'] ) ) {
			$feed['fbc_map'] = array();
			foreach ( $in['fbc_map'] as $term_id => $value ) {
				$value = mb_substr( sanitize_text_field( (string) $value ), 0, 250 );
				if ( (int) $term_id > 0 && '' !== $value ) {
					$feed['fbc_map'][ (int) $term_id ] = $value;
				}
			}
		}

		if ( isset( $in['labels'] ) && is_array( $in['labels'] ) ) {
			for ( $i = 0; $i < self::LABEL_SLOTS; $i++ ) {
				$slot = isset( $in['labels'][ $i ] ) && is_array( $in['labels'][ $i ] ) ? $in['labels'][ $i ] : array();
				$rule = sanitize_key( (string) ( $slot['rule'] ?? '' ) );
				$feed['labels'][ $i ] = array(
					'rule'  => array_key_exists( $rule, Labels::rules() ) ? $rule : '',
					'param' => mb_substr( sanitize_text_field( (string) ( $slot['param'] ?? '' ) ), 0, 200 ),
				);
			}
		}

		$feed['internal_label'] = $pick( 'internal_label', array( '', 'tags', 'categories', 'both' ), $feed['internal_label'] );
		if ( array_key_exists( 'shipping_price', $in ) ) {
			$feed['shipping_price'] = self::decimal( $in['shipping_price'] );
		}
		if ( array_key_exists( 'shipping_service', $in ) ) {
			$feed['shipping_service'] = $text( 'shipping_service', 60 );
		}
		if ( isset( $in['drop_guard'] ) ) {
			$feed['drop_guard'] = min( 100, max( 0, (int) $in['drop_guard'] ) );
		}

		$feed['modified'] = time();
		return self::normalize( $feed );
	}

	private static function unique_slug( string $slug, string $id ): string {
		$slug  = '' !== $slug ? $slug : 'feed';
		$taken = array();
		foreach ( self::all() as $feed ) {
			if ( $feed['id'] !== $id ) {
				$taken[] = $feed['slug'];
			}
		}
		$base = $slug;
		for ( $i = 2; in_array( $slug, $taken, true ); $i++ ) {
			$slug = $base . '-' . $i;
		}
		return $slug;
	}

	/** Accepts "12, 15 18" or an array. */
	public static function id_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,;]+/', $value ) ?: array();
		}
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $value ) ) ) );
	}

	private static function decimal( $value ): string {
		$value = trim( str_replace( array( ' ', ',' ), array( '', '.' ), (string) $value ) );
		return ( '' !== $value && is_numeric( $value ) && (float) $value >= 0 ) ? (string) (float) $value : '';
	}

	/** Google product category: numeric ID (a pasted "1234 - Path" keeps just the ID). */
	private static function gpc( $value ): string {
		return preg_match( '/^\s*(\d{1,7})\b/', (string) $value, $m ) ? $m[1] : '';
	}

	private static function query_string( string $value ): string {
		$value = trim( wp_strip_all_tags( $value ), "?& \t\n\r" );
		if ( '' === $value ) {
			return '';
		}
		parse_str( $value, $params );
		$clean = array();
		foreach ( $params as $key => $param ) {
			if ( is_scalar( $param ) && '' !== sanitize_key( (string) $key ) ) {
				$clean[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $param );
			}
		}
		return mb_substr( http_build_query( $clean, '', '&', PHP_QUERY_RFC3986 ), 0, 300 );
	}
}
