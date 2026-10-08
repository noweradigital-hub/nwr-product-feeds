<?php
namespace Nowera\ProductFeeds\Catalog;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * GTIN / EAN lookup and check-digit validation.
 */
final class Gtin {

	/** Meta keys other plugins store GTINs under. Filter: nwr_pf_gtin_meta_keys. */
	const META_KEYS = array( '_global_unique_id', '_gtin', '_ean', 'ean', '_wpm_gtin_code', '_alg_ean', '_ts_gtin', 'hwp_product_gtin', 'hwp_var_gtin', '_barcode', 'barcode' );

	/**
	 * @return array{value:string,source:string,invalid:string}
	 *         source: own | parent | '' ; invalid: a value that failed validation
	 */
	public static function find( WC_Product $product, ?WC_Product $parent, bool $use_parent ): array {
		$invalid = '';
		$owners  = array( 'own' => $product );
		if ( $parent && $use_parent ) {
			$owners['parent'] = $parent;
		}

		foreach ( $owners as $source => $owner ) {
			foreach ( self::candidates( $owner ) as $raw ) {
				$value = self::normalize( $raw );
				if ( '' === $value ) {
					continue;
				}
				if ( self::valid( $value ) ) {
					return array(
						'value'   => $value,
						'source'  => $source,
						'invalid' => $invalid,
					);
				}
				$invalid = '' !== $invalid ? $invalid : trim( $raw );
			}
		}

		return array(
			'value'   => '',
			'source'  => '',
			'invalid' => $invalid,
		);
	}

	/** Raw values of one product: feed override, native field, known meta keys. */
	private static function candidates( WC_Product $product ): array {
		$values = array( (string) $product->get_meta( '_nwr_pf_gtin', true, 'edit' ) );
		if ( is_callable( array( $product, 'get_global_unique_id' ) ) ) {
			$values[] = (string) $product->get_global_unique_id( 'edit' );
		}
		foreach ( (array) apply_filters( 'nwr_pf_gtin_meta_keys', self::META_KEYS ) as $key ) {
			$value = get_post_meta( $product->get_id(), (string) $key, true );
			if ( is_scalar( $value ) ) {
				$values[] = (string) $value;
			}
		}
		// Google Product Feed (Ademti) keeps its fields in one array.
		$gpf = get_post_meta( $product->get_id(), '_woocommerce_gpf_data', true );
		if ( is_array( $gpf ) && ! empty( $gpf['gtin'] ) && is_scalar( $gpf['gtin'] ) ) {
			$values[] = (string) $gpf['gtin'];
		}
		return array_values( array_filter( array_map( 'trim', $values ), 'strlen' ) );
	}

	public static function normalize( string $value ): string {
		return preg_replace( '/[\s\-.]/', '', trim( $value ) ) ?? '';
	}

	/** GTIN-8, UPC (12), EAN/ISBN-13 or GTIN-14 with a correct check digit. */
	public static function valid( string $value ): bool {
		if ( ! preg_match( '/^\d+$/', $value ) || ! in_array( strlen( $value ), array( 8, 12, 13, 14 ), true ) ) {
			return false;
		}
		if ( '' === ltrim( $value, '0' ) ) {
			return false;
		}
		$digits = array_map( 'intval', str_split( $value ) );
		$check  = array_pop( $digits );
		$sum    = 0;
		foreach ( array_reverse( $digits ) as $i => $digit ) {
			$sum += $digit * ( 0 === $i % 2 ? 3 : 1 );
		}
		return ( 10 - $sum % 10 ) % 10 === $check;
	}
}
