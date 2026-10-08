<?php
namespace Nowera\ProductFeeds;

defined( 'ABSPATH' ) || exit;

/**
 * Upgrade from the first version (1.0.0), which generated
 * feeds on request at /produkty-feed/<slug>.<ext> and stored them in the
 * same option with a different shape. Old URLs keep working through a
 * redirect to the static file (Legacy_Redirect).
 */
final class Migration {

	const LABEL_RULES = array(
		'category'     => 'category_top',
		'product_type' => 'category_path',
		'brand'        => 'brand',
		'stock'        => 'stock',
		'onsale'       => 'on_sale',
		'price_band'   => 'price_band',
	);

	public static function run(): void {
		$stored = get_option( Feeds::OPTION, array() );
		if ( ! is_array( $stored ) || ! $stored ) {
			return;
		}

		$legacy  = get_option( Legacy_Redirect::OPTION, array() );
		$legacy  = is_array( $legacy ) ? $legacy : array();
		$changed = 0;
		foreach ( $stored as $id => $old ) {
			if ( ! is_array( $old ) || isset( $old['channel'] ) || ! isset( $old['template'] ) ) {
				continue;
			}
			$feed          = self::convert( (string) $id, $old );
			$stored[ $id ] = $feed;
			if ( ! empty( $old['slug'] ) ) {
				$legacy[ (string) $old['slug'] ] = (string) $id;
			}
			delete_transient( 'nwr_pf_' . md5( (string) $id ) );
			delete_option( 'nwr_pf_stats_' . $id );
			++$changed;
			Log::warning(
				(string) $id,
				'Feed prevzatý zo staršej verzie pluginu. ID položiek sa teraz tvoria zo šablóny ID (predvolene ID produktu), nie zo SKU — pred prepnutím URL v Merchant Center / Commerce Manageri skontroluj náhľad.'
			);
		}

		if ( $changed ) {
			update_option( Feeds::OPTION, $stored, false );
			update_option( Legacy_Redirect::OPTION, $legacy, true );
			// The old rewrite rule goes away; WordPress rebuilds the rules once every plugin has registered its own.
			delete_option( 'rewrite_rules' );
		}
	}

	private static function convert( string $id, array $old ): array {
		$feed = Feeds::defaults();

		$feed['id']       = $id;
		$feed['name']     = (string) ( $old['name'] ?? '' );
		$feed['slug']     = (string) ( $old['slug'] ?? '' );
		$feed['token']    = Storage::new_token();
		$feed['enabled']  = empty( $old['enabled'] ) ? 0 : 1;
		$feed['channel']  = 'meta' === ( $old['template'] ?? '' ) ? 'meta' : 'google';
		$feed['format']   = in_array( $old['format'] ?? '', array( 'xml', 'csv', 'tsv' ), true ) ? $old['format'] : 'xml';
		$feed['types']    = 'variations_strict' === ( $old['items'] ?? '' ) ? array( 'variable' ) : array( 'simple', 'variable' );
		$feed['created']  = (int) ( $old['created'] ?? time() );
		$feed['modified'] = time();

		$feed['include_cats']      = array_map( 'intval', (array) ( $old['include_cats'] ?? array() ) );
		$feed['exclude_cats']      = array_map( 'intval', (array) ( $old['exclude_cats'] ?? array() ) );
		$feed['out_of_stock']      = empty( $old['exclude_outofstock'] ) ? 1 : 0;
		$feed['hidden']            = empty( $old['exclude_hidden'] ) ? 'include' : 'skip';
		$feed['price_tax']         = 'excl' === ( $old['price_tax'] ?? '' ) ? 'excl' : 'incl';
		$feed['description_order'] = 'long' === ( $old['desc_source'] ?? '' ) ? 'long' : 'short';
		$feed['brand_default']     = (string) ( $old['brand_fallback'] ?? '' );
		$feed['condition']         = in_array( $old['condition'] ?? '', array( 'new', 'refurbished', 'used' ), true ) ? $old['condition'] : 'new';
		$feed['utm']               = (string) ( $old['utm'] ?? '' );
		$feed['images_max']        = min( 20, max( 0, (int) ( $old['max_images'] ?? 0 ) ) );
		$feed['shipping_price']    = (string) ( $old['shipping_price'] ?? '' );
		$feed['gpc_default']       = preg_match( '/^\d+$/', (string) ( $old['gpc_default'] ?? '' ) ) ? (string) $old['gpc_default'] : '';

		foreach ( (array) ( $old['gpc_map'] ?? array() ) as $term_id => $value ) {
			if ( preg_match( '/^\s*(\d+)/', (string) $value, $m ) ) {
				$feed['gpc_map'][ (int) $term_id ] = $m[1];
			}
		}

		for ( $i = 0; $i < Feeds::LABEL_SLOTS; $i++ ) {
			$rule = (string) ( $old[ 'label' . $i ] ?? '' );
			$feed['labels'][ $i ] = array(
				'rule'  => self::LABEL_RULES[ $rule ] ?? '',
				'param' => 'price_band' === $rule ? '15,25,50' : ( 'stock' === $rule ? 'skladom|nedostupne' : ( 'onsale' === $rule ? 'v-akcii|bez-akcie' : '' ) ),
			);
		}

		// Old slugs may hold characters the file name does not allow.
		return Feeds::sanitize( array( 'slug' => $feed['slug'] ), Feeds::normalize( $feed ) );
	}
}
