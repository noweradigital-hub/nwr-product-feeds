<?php
namespace Nowera\ProductFeeds\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Multistore (WooMultistore) copies a whitelist of product meta
 * from the master shop to child shops. By default our "_nwr_pf_*" meta is not
 * on it (checked on a live multistore shop, 5.5.0) — and it must never be: exclusions,
 * texts and labels are each shop's own decision. This keeps them out even if
 * someone ticks them under "Sync custom metadata".
 *
 * Keys a site deliberately shares: filter `nwr_pf_multistore_shared_meta`.
 * The hooks do nothing when Multistore is not installed.
 */
final class Multistore {

	const PREFIX = '_nwr_pf_';

	public function register(): void {
		add_filter( 'wc_multistore_master_product_data', array( $this, 'strip' ), 20 );
		add_filter( 'wc_multistore_child_product_data', array( $this, 'strip' ), 5 );
	}

	/**
	 * @param mixed $data product data array (a child may also receive a WC_Product)
	 * @return mixed
	 */
	public function strip( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			$data['meta'] = $this->filter_meta( $data['meta'] );
		}
		if ( isset( $data['_custom_metadata'] ) && is_array( $data['_custom_metadata'] ) ) {
			foreach ( $data['_custom_metadata'] as $site => $meta ) {
				if ( is_array( $meta ) ) {
					$data['_custom_metadata'][ $site ] = $this->filter_meta( $meta );
				}
			}
		}
		if ( isset( $data['variations'] ) && is_array( $data['variations'] ) ) {
			foreach ( $data['variations'] as $key => $variation ) {
				$data['variations'][ $key ] = $this->strip( $variation );
			}
		}
		return $data;
	}

	/** Handles both key => value maps and lists of {key, value} rows. */
	private function filter_meta( array $meta ): array {
		$shared   = (array) apply_filters( 'nwr_pf_multistore_shared_meta', array() );
		$was_list = array_is_list( $meta );
		foreach ( $meta as $key => $value ) {
			$name = is_string( $key ) ? $key : ( is_array( $value ) ? (string) ( $value['key'] ?? '' ) : '' );
			if ( is_object( $value ) && isset( $value->key ) ) {
				$name = (string) $value->key;
			}
			if ( str_starts_with( $name, self::PREFIX ) && ! in_array( $name, $shared, true ) ) {
				unset( $meta[ $key ] );
			}
		}
		return $was_list ? array_values( $meta ) : $meta;
	}
}
