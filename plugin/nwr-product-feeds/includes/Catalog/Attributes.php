<?php
namespace Nowera\ProductFeeds\Catalog;

use WC_Product;
use WC_Product_Attribute;

defined( 'ABSPATH' ) || exit;

/**
 * Product attributes → brand, color, size, material, pattern, gender, age_group.
 *
 * A feed maps each field to "auto", nothing, a global attribute (pa_*) or a
 * product's own attribute ("local:<name>"). "auto" tries common slugs.
 */
final class Attributes {

	const FIELDS = array( 'brand', 'color', 'size', 'material', 'pattern', 'gender', 'age_group' );

	/** Attribute slugs (without "pa_") that "auto" tries, in this order. */
	const AUTO = array(
		'brand'     => array( 'brand', 'znacka', 'vyrobca', 'vyrobce', 'marka', 'manufacturer' ),
		'color'     => array( 'color', 'colour', 'farba', 'barva', 'kolor', 'szin' ),
		'size'      => array( 'size', 'velkost', 'velikost', 'rozmer', 'rozmiar', 'meret' ),
		'material'  => array( 'material', 'materialy', 'material-2', 'anyag' ),
		'pattern'   => array( 'pattern', 'vzor', 'wzor', 'minta' ),
		'gender'    => array( 'gender', 'pohlavie', 'pohlavi', 'plec' ),
		'age_group' => array( 'age_group', 'age-group', 'vek', 'vekova-skupina', 'wiek' ),
	);

	/** Fields where several values of one product are joined with "/" (Google's multi-value separator). */
	const MULTI = array( 'color', 'material', 'pattern' );

	public static function sanitize_source( string $value ): string {
		if ( 'auto' === $value || '' === $value ) {
			return $value;
		}
		if ( str_starts_with( $value, 'pa_' ) ) {
			return 'pa_' . sanitize_title( substr( $value, 3 ) );
		}
		if ( str_starts_with( $value, 'local:' ) ) {
			return 'local:' . sanitize_title( substr( $value, 6 ) );
		}
		return 'auto';
	}

	/**
	 * A variation's own attribute values. An empty value means "any" in
	 * WooCommerce ("Ľubovoľná …").
	 *
	 * @return array<string,array{label:string,value:string}> attribute key => label, value
	 */
	public static function variation_values( WC_Product $variation, WC_Product $parent ): array {
		$out     = array();
		$options = null;
		foreach ( $variation->get_attributes() as $key => $raw ) {
			$key   = (string) $key;
			$raw   = (string) $raw;
			$value = '';
			if ( '' !== $raw ) {
				if ( taxonomy_exists( $key ) ) {
					$term  = get_term_by( 'slug', $raw, $key );
					$value = ( $term && ! is_wp_error( $term ) ) ? $term->name : $raw;
				} else {
					$options ??= self::local_options( $parent );
					$value     = $options[ $key ][ sanitize_title( $raw ) ] ?? $raw;
				}
			}
			$out[ $key ] = array(
				'label' => Text::line( (string) wc_attribute_label( $key, $parent ) ),
				'value' => Text::line( (string) $value ),
			);
		}
		return $out;
	}

	/**
	 * @return array{fields:array<string,string>,extra:array<string,string>}
	 *         mapped fields, and the variation's remaining attributes (label => value)
	 */
	public static function map( array $feed, WC_Product $main, array $variation_values ): array {
		$fields = array();
		$used   = array();

		foreach ( self::FIELDS as $field ) {
			$source = (string) ( $feed['attribute_map'][ $field ] ?? 'auto' );
			if ( '' === $source ) {
				continue;
			}
			foreach ( self::candidates( $source, $field ) as $key ) {
				if ( isset( $variation_values[ $key ] ) ) {
					// An "any" value tells nothing about this variation; the parent's full list would mislead.
					if ( '' !== $variation_values[ $key ]['value'] ) {
						$fields[ $field ] = $variation_values[ $key ]['value'];
					}
					$used[ $key ] = true;
					break;
				}
				$value = self::product_value( $main, $key, in_array( $field, self::MULTI, true ) );
				if ( '' !== $value ) {
					$fields[ $field ] = $value;
					$used[ $key ]     = true;
					break;
				}
			}
		}

		$extra = array();
		foreach ( $variation_values as $key => $data ) {
			if ( empty( $used[ $key ] ) && '' !== $data['value'] && '' !== $data['label'] ) {
				$extra[ $data['label'] ] = $data['value'];
			}
		}

		return array(
			'fields' => $fields,
			'extra'  => $extra,
		);
	}

	/** Value of an attribute on a simple or parent product ('' when absent). */
	public static function product_value( WC_Product $product, string $key, bool $all = false ): string {
		$attributes = $product->get_attributes();
		$attribute  = $attributes[ $key ] ?? null;
		if ( ! $attribute instanceof WC_Product_Attribute ) {
			return '';
		}

		$names = array();
		if ( $attribute->is_taxonomy() ) {
			foreach ( (array) $attribute->get_terms() as $term ) {
				if ( $term instanceof \WP_Term ) {
					$names[] = $term->name;
				}
			}
		} else {
			$names = $attribute->get_options();
		}

		$names = array_values( array_filter( array_map( static fn( $name ): string => Text::line( (string) $name ), $names ) ) );
		if ( ! $names ) {
			return '';
		}
		return $all ? implode( '/', array_unique( $names ) ) : $names[0];
	}

	/** Attribute keys to try for a field. Local attributes are keyed by their sanitized name. */
	private static function candidates( string $source, string $field ): array {
		if ( str_starts_with( $source, 'pa_' ) ) {
			return array( $source );
		}
		if ( str_starts_with( $source, 'local:' ) ) {
			return array( substr( $source, 6 ) );
		}
		$slugs = self::AUTO[ $field ] ?? array();
		return array_merge( array_map( static fn( string $slug ): string => 'pa_' . $slug, $slugs ), $slugs );
	}

	/** Local attribute options of a parent: key => sanitized option => option text. */
	private static function local_options( WC_Product $parent ): array {
		$out = array();
		foreach ( $parent->get_attributes() as $key => $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute && ! $attribute->is_taxonomy() ) {
				foreach ( $attribute->get_options() as $option ) {
					$out[ (string) $key ][ sanitize_title( (string) $option ) ] = (string) $option;
				}
			}
		}
		return $out;
	}

	/** Attribute choices for the feed editor: key => label. */
	public static function choices(): array {
		$choices = array();
		foreach ( wc_get_attribute_taxonomies() as $taxonomy ) {
			$choices[ wc_attribute_taxonomy_name( $taxonomy->attribute_name ) ] = $taxonomy->attribute_label;
		}
		return $choices;
	}

	public static function gender( string $value ): string {
		$v = self::fold( $value );
		if ( in_array( $v, array( 'male', 'female', 'unisex' ), true ) ) {
			return $v;
		}
		$male   = array( 'muz', 'muzi', 'muzske', 'muzsky', 'panske', 'pansky', 'panska', 'chlapec', 'chlapci', 'chlapcenske', 'chlapecke', 'boy', 'boys', 'man', 'men', 'mens', 'meskie', 'chlopiec' );
		$female = array( 'zena', 'zeny', 'zenske', 'zensky', 'damske', 'damsky', 'damska', 'dievca', 'dievcata', 'dievcenske', 'divka', 'divci', 'girl', 'girls', 'woman', 'women', 'womens', 'damskie', 'dziewczynka' );
		if ( in_array( $v, $male, true ) ) {
			return 'male';
		}
		if ( in_array( $v, $female, true ) ) {
			return 'female';
		}
		return in_array( $v, array( 'uni', 'unisex', 'univerzalne', 'univerzalny' ), true ) ? 'unisex' : '';
	}

	public static function age_group( string $value ): string {
		$v   = self::fold( $value );
		$map = array(
			'newborn' => array( 'newborn', 'novorodenec', 'novorodenci', 'novorozenec', 'novorozenci', 'noworodek' ),
			'infant'  => array( 'infant', 'dojca', 'dojcata', 'kojenec', 'kojenci', 'babatko', 'babatka', 'baby', 'niemowle' ),
			'toddler' => array( 'toddler', 'batola', 'batolata', 'batole' ),
			'kids'    => array( 'kids', 'kid', 'deti', 'detske', 'dieta', 'child', 'children', 'dzieci', 'dziecko' ),
			'teen'    => array( 'teen', 'teens', 'tinedzer', 'tinedzeri', 'nastolatek' ),
			'adult'   => array( 'adult', 'adults', 'dospeli', 'dospely', 'dospela', 'dorosly', 'dorosli' ),
		);
		if ( 'all ages' === $v ) {
			return 'all ages';
		}
		foreach ( $map as $group => $words ) {
			if ( in_array( $v, $words, true ) ) {
				return $group;
			}
		}
		return '';
	}

	private static function fold( string $value ): string {
		return trim( strtolower( remove_accents( Text::line( $value ) ) ) );
	}
}
