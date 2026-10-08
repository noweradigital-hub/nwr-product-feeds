<?php
namespace Nowera\ProductFeeds\Catalog;

use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * custom_label_0–4 rules. A value set on the product ("Feedy" tab) wins.
 */
final class Labels {

	const MAX = 100;

	/** Rule => label (the part in brackets explains the parameter). */
	public static function rules(): array {
		return array(
			''                 => __( '— nepoužívať —', 'nwr-product-feeds' ),
			'category_top'     => __( 'Kategória — najvyššia úroveň', 'nwr-product-feeds' ),
			'category_main'    => __( 'Kategória — primárna (alebo najhlbšia)', 'nwr-product-feeds' ),
			'category_path'    => __( 'Kategória — celá cesta', 'nwr-product-feeds' ),
			'tag'              => __( 'Štítok produktu (parameter: slugy štítkov oddelené čiarkou, voliteľné)', 'nwr-product-feeds' ),
			'on_sale'          => __( 'Akcia áno/nie (parameter: text-áno|text-nie)', 'nwr-product-feeds' ),
			'price_band'       => __( 'Cenové pásmo (parameter: hranice, napr. 15,25,50)', 'nwr-product-feeds' ),
			'stock'            => __( 'Skladom/vypredané (parameter: text-áno|text-nie)', 'nwr-product-feeds' ),
			'brand'            => __( 'Značka', 'nwr-product-feeds' ),
			'attribute'        => __( 'Atribút (parameter: napr. pa_nosnost)', 'nwr-product-feeds' ),
			'other_attributes' => __( 'Ostatné atribúty variácie („Nosnosť: 10 kg“)', 'nwr-product-feeds' ),
			'meta'             => __( 'Vlastné pole (parameter: meta kľúč)', 'nwr-product-feeds' ),
			'kind'             => __( 'Typ položky (simple, variation…)', 'nwr-product-feeds' ),
			'static'           => __( 'Pevný text (parameter: text)', 'nwr-product-feeds' ),
		);
	}

	/**
	 * @param array $ctx product, main, term, product_type, brand, price, on_sale, in_stock, kind, variation_values, extra
	 * @return string[] five values (index 0–4)
	 */
	public static function compute( array $feed, array $ctx ): array {
		$out = array();
		foreach ( array_values( $feed['labels'] ) as $slot => $rule ) {
			$override = self::override( $ctx, $slot );
			$value    = '' !== $override ? $override : self::value( (string) ( $rule['rule'] ?? '' ), (string) ( $rule['param'] ?? '' ), $ctx );
			$out[ $slot ] = Text::limit( Text::line( $value ), self::MAX )[0];
		}
		return $out;
	}

	private static function override( array $ctx, int $slot ): string {
		foreach ( array( $ctx['product'], $ctx['main'] ) as $owner ) {
			$value = trim( (string) $owner->get_meta( '_nwr_pf_label_' . $slot, true, 'edit' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	public static function value( string $rule, string $param, array $ctx ): string {
		$term = $ctx['term'] instanceof WP_Term ? $ctx['term'] : null;
		[ $yes, $no ] = array_pad( array_map( 'trim', explode( '|', $param, 2 ) ), 2, '' );

		switch ( $rule ) {
			case 'category_top':
				return $term ? Categories::top( $term ) : '';
			case 'category_main':
				return $term ? Text::line( $term->name ) : '';
			case 'category_path':
				return (string) $ctx['product_type'];
			case 'tag':
				return self::tag( $ctx['main']->get_id(), $param );
			case 'on_sale':
				return $ctx['on_sale'] ? ( '' !== $yes ? $yes : 'akcia' ) : ( '' !== $no ? $no : 'bez-akcie' );
			case 'stock':
				return $ctx['in_stock'] ? ( '' !== $yes ? $yes : 'skladom' ) : ( '' !== $no ? $no : 'vypredane' );
			case 'price_band':
				return self::price_band( (float) $ctx['price'], $param );
			case 'brand':
				return (string) $ctx['brand'];
			case 'attribute':
				$key = sanitize_title( $param );
				if ( '' === $key ) {
					return '';
				}
				if ( isset( $ctx['variation_values'][ $key ] ) && '' !== $ctx['variation_values'][ $key ]['value'] ) {
					return $ctx['variation_values'][ $key ]['value'];
				}
				return Attributes::product_value( $ctx['main'], $key, true );
			case 'other_attributes':
				$parts = array();
				foreach ( (array) $ctx['extra'] as $label => $value ) {
					$parts[] = $label . ': ' . $value;
				}
				return implode( '; ', $parts );
			case 'meta':
				$key = trim( $param );
				if ( '' === $key ) {
					return '';
				}
				foreach ( array( $ctx['product'], $ctx['main'] ) as $owner ) {
					$value = get_post_meta( $owner->get_id(), $key, true );
					if ( is_array( $value ) ) {
						$value = implode( ', ', array_filter( $value, 'is_scalar' ) );
					}
					if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
						return (string) $value;
					}
				}
				return '';
			case 'kind':
				return (string) $ctx['kind'];
			case 'static':
				return $param;
			default:
				return (string) apply_filters( 'nwr_pf_custom_label_rule', '', $rule, $param, $ctx );
		}
	}

	private static function tag( int $product_id, string $param ): string {
		$terms = get_the_terms( $product_id, 'product_tag' );
		if ( ! is_array( $terms ) || ! $terms ) {
			return '';
		}
		$wanted = array_filter( array_map( 'sanitize_title', explode( ',', $param ) ) );
		if ( ! $wanted ) {
			usort( $terms, static fn( $a, $b ): int => strcmp( $a->name, $b->name ) );
			return Text::line( $terms[0]->name );
		}
		foreach ( $wanted as $slug ) {
			foreach ( $terms as $term ) {
				if ( $term->slug === $slug ) {
					return Text::line( $term->name );
				}
			}
		}
		return '';
	}

	/** "0-15", "15-25", "25-50", "50+" for edges "15,25,50". */
	public static function price_band( float $price, string $param ): string {
		$edges = array_values( array_filter( array_map( 'floatval', preg_split( '/[\s,;]+/', $param ) ?: array() ), static fn( $e ): bool => $e > 0 ) );
		if ( ! $edges ) {
			$edges = array( 10, 25, 50, 100 );
		}
		sort( $edges );
		$format = static fn( float $v ): string => rtrim( rtrim( number_format( $v, 2, '.', '' ), '0' ), '.' );
		$lower  = 0.0;
		foreach ( $edges as $edge ) {
			if ( $price < $edge ) {
				return $format( $lower ) . '-' . $format( $edge );
			}
			$lower = $edge;
		}
		return $format( $lower ) . '+';
	}
}
