<?php
namespace Nowera\ProductFeeds\Catalog;

use Nowera\ProductFeeds\Channels\Channel;
use Nowera\ProductFeeds\Item_Id;
use Nowera\ProductFeeds\Storage;
use WC_Product;
use WC_Product_Factory;
use WC_Product_Variable;
use WC_Product_Variation;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a batch of parent products into feed items.
 *
 * Rules (ZADANIE §1):
 * - simple product → one item, id = its item ID;
 * - variable product → no item of its own, one item per variation with
 *   item_group_id = the parent's item ID;
 * - a hidden parent never hides its variations;
 * - out-of-stock items stay in (availability says so);
 * - every product left out is reported with a reason.
 */
final class Item_Builder {

	/** Meta keys of variation-gallery plugins (Woodmart, WooCommerce, Woo Variation Gallery, rtwpvg). */
	const VARIATION_GALLERY_KEYS = array( 'wd_additional_variation_images_data', '_wc_additional_variation_images', 'woo_variation_gallery_images', 'rtwpvg_images' );

	private array $feed;
	private Channel $channel;
	private string $template;
	private string $filter_feed;
	private bool $keep_items;
	private Batch $out;

	/** @var array<string,int> item ID => product ID, across the whole run */
	private array $seen;

	/** @var array<int,true> */
	private array $exclude_ids;
	private array $include_cats;
	private array $exclude_cats;
	private array $include_tags;
	private array $exclude_tags;
	private array $utm = array();
	private array $tax_location;
	private string $currency;

	/** @var array<int,string> parent ID => item_group_id */
	private array $group_ids = array();

	public function __construct( array $feed, Channel $channel, array $seen = array(), bool $keep_items = false ) {
		$this->feed       = $feed;
		$this->channel    = $channel;
		$this->seen       = $seen;
		$this->keep_items = $keep_items;
		$this->template   = Item_Id::template( (string) $feed['id'] );
		// The filter sees a feed ID only when the feed overrides the site-wide template,
		// so with the shared template the IDs are exactly what nwr_pf_item_id() returns to tracking.
		$this->filter_feed = '' !== (string) $feed['id_template'] ? (string) $feed['id'] : '';
		$this->currency   = get_woocommerce_currency();

		$this->exclude_ids  = array_fill_keys( array_map( 'intval', (array) $feed['exclude_ids'] ), true );
		$this->include_cats = Categories::with_children( (array) $feed['include_cats'], 'product_cat' );
		$this->exclude_cats = Categories::with_children( (array) $feed['exclude_cats'], 'product_cat' );
		$this->include_tags = array_map( 'intval', (array) $feed['include_tags'] );
		$this->exclude_tags = array_map( 'intval', (array) $feed['exclude_tags'] );

		if ( '' !== (string) $feed['utm'] ) {
			parse_str( (string) $feed['utm'], $utm );
			$this->utm = array_map( static fn( $v ): string => rawurlencode( (string) $v ), array_filter( $utm, 'is_scalar' ) );
		}

		$countries          = WC()->countries;
		$country            = (string) $feed['country'];
		$this->tax_location = ( '' === $country || $country === $countries->get_base_country() )
			? array( $countries->get_base_country(), $countries->get_base_state(), $countries->get_base_postcode(), $countries->get_base_city() )
			: array( $country, '', '', '' );
	}

	/** @return array<string,int> */
	public function seen(): array {
		return $this->seen;
	}

	/**
	 * @param list<array{ID:int,post_status:string,locked:bool}> $rows parents from Source::parents()
	 */
	public function build( array $rows ): Batch {
		$this->out = new Batch();
		if ( ! $rows ) {
			return $this->out;
		}

		$ids = array_map( static fn( array $row ): int => (int) $row['ID'], $rows );
		Source::prime( $ids );

		$variable = array();
		foreach ( $rows as $row ) {
			if ( 'publish' === $row['post_status'] && in_array( WC_Product_Factory::get_product_type( (int) $row['ID'] ), array( 'variable', 'variable-subscription' ), true ) ) {
				$variable[] = (int) $row['ID'];
			}
		}
		$variations = Source::variations( $variable );
		$child_ids  = array();
		foreach ( $variations as $list ) {
			foreach ( $list as $variation ) {
				$child_ids[] = $variation['ID'];
			}
		}
		Source::prime( $child_ids, false );

		// Taxes for the feed's country, whoever triggered the run (a queue runner request may carry a geolocated guest).
		add_filter( 'woocommerce_get_tax_location', array( $this, 'tax_location' ), 999 );
		try {
			foreach ( $rows as $row ) {
				$this->parent( $row, $variations[ (int) $row['ID'] ] ?? null );
				$this->out->last_id = (int) $row['ID'];
			}
		} finally {
			remove_filter( 'woocommerce_get_tax_location', array( $this, 'tax_location' ), 999 );
		}

		return $this->out;
	}

	/** @internal filter callback */
	public function tax_location(): array {
		return $this->tax_location;
	}

	private function parent( array $row, ?array $variations ): void {
		$id = (int) $row['ID'];
		if ( 'publish' !== $row['post_status'] ) {
			$this->skip( 'not_published', $id, 0, $row['post_status'] );
			return;
		}
		if ( ! empty( $row['locked'] ) ) {
			$this->skip( 'password', $id );
			return;
		}

		$product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product ) {
			$this->skip( 'load_failed', $id );
			return;
		}
		$this->out->count( 'products' );
		$is_variable = $product instanceof WC_Product_Variable;
		if ( $is_variable ) {
			$this->out->count( 'variable' );
			$variations ??= Source::variations( array( $id ) )[ $id ] ?? array();
		}

		[ $reason, $detail ] = $this->parent_reason( $product );
		if ( '' !== $reason ) {
			if ( $is_variable && $variations ) {
				/* translators: %d: number of variations */
				$detail = trim( $detail . ' ' . sprintf( __( '(variácií: %d)', 'nwr-product-feeds' ), count( $variations ) ) );
			}
			$this->skip( $reason, $id, 0, $detail );
			return;
		}

		if ( ! $is_variable ) {
			$flags  = array();
			$hidden = $this->hidden( $product );
			if ( 'skip' === $hidden ) {
				$this->skip( 'hidden', $id, 0, $product->get_catalog_visibility() );
				return;
			}
			if ( 'archive' === $hidden ) {
				$flags['archived'] = true;
			}
			if ( Product_Types::is_bundle( $product ) && (float) $product->get_price() > 0 && ! Product_Types::has_fixed_price( $product ) ) {
				$this->skip( 'dynamic_price', $id, 0, $product->get_type() );
				return;
			}
			$this->emit( $product, null, $flags );
			return;
		}

		// Variable product: its variations are the items. The parent's own
		// catalog visibility is deliberately ignored — shops often hide the
		// parent and list the variations on their own.
		if ( ! $variations ) {
			$this->skip( 'no_variations', $id );
			return;
		}
		foreach ( $variations as $row_variation ) {
			$vid = (int) $row_variation['ID'];
			if ( 'publish' !== $row_variation['post_status'] ) {
				$this->skip( 'variation_disabled', $vid, $id );
				continue;
			}
			if ( isset( $this->exclude_ids[ $vid ] ) ) {
				$this->skip( 'filter_id', $vid, $id );
				continue;
			}
			$variation = wc_get_product( $vid );
			if ( ! $variation instanceof WC_Product_Variation ) {
				$this->skip( 'load_failed', $vid, $id );
				continue;
			}
			if ( $this->excluded_by_product( $variation ) ) {
				$this->skip( 'excluded', $vid, $id );
				continue;
			}
			$this->emit( $variation, $product );
		}
	}

	/** @return array{0:string,1:string} reason, detail */
	private function parent_reason( WC_Product $product ): array {
		$id = $product->get_id();
		if ( $this->excluded_by_product( $product ) ) {
			return array( 'excluded', '' );
		}
		if ( ! Product_Types::allowed( $this->feed, $product ) ) {
			return array( 'type', Product_Types::key( $product ) );
		}
		if ( isset( $this->exclude_ids[ $id ] ) ) {
			return array( 'filter_id', '' );
		}
		if ( $this->include_cats || $this->exclude_cats ) {
			$cats = Categories::term_ids( $id );
			if ( $this->include_cats && ! array_intersect( $cats, $this->include_cats ) ) {
				return array( 'filter_category', '' );
			}
			if ( $this->exclude_cats && array_intersect( $cats, $this->exclude_cats ) ) {
				return array( 'filter_category', '' );
			}
		}
		if ( $this->include_tags || $this->exclude_tags ) {
			$tags = array_map( 'intval', wc_get_product_term_ids( $id, 'product_tag' ) );
			if ( $this->include_tags && ! array_intersect( $tags, $this->include_tags ) ) {
				return array( 'filter_tag', '' );
			}
			if ( $this->exclude_tags && array_intersect( $tags, $this->exclude_tags ) ) {
				return array( 'filter_tag', '' );
			}
		}
		return array( '', '' );
	}

	private function excluded_by_product( WC_Product $product ): bool {
		if ( 'yes' === $product->get_meta( '_nwr_pf_exclude', true, 'edit' ) ) {
			return true;
		}
		$feeds = $product->get_meta( '_nwr_pf_exclude_feeds', true, 'edit' );
		return is_array( $feeds ) && in_array( (string) $this->feed['id'], array_map( 'strval', $feeds ), true );
	}

	/** '' = keep, 'skip' = leave out, 'archive' = keep as archived (channels that support it). */
	private function hidden( WC_Product $product ): string {
		$visibility = $product->get_catalog_visibility();
		$mode       = (string) $this->feed['hidden'];
		if ( 'include' === $mode || 'visible' === $visibility || 'catalog' === $visibility ) {
			return '';
		}
		if ( 'hidden' === $visibility ) {
			if ( 'archive' === $mode ) {
				return $this->channel->supports_archive() ? 'archive' : 'skip';
			}
			return 'skip';
		}
		// "search" = listed in search results only.
		return 'skip_search' === $mode ? 'skip' : '';
	}

	private function emit( WC_Product $product, ?WC_Product $parent, array $flags = array() ): void {
		$id        = $product->get_id();
		$parent_id = $parent ? $parent->get_id() : 0;

		$item = $this->normalize( $product, $parent, $flags );
		if ( is_string( $item ) ) {
			$this->skip( $item, $id, $parent_id );
			return;
		}

		if ( empty( $this->feed['out_of_stock'] ) && 'out_of_stock' === $item['availability'] ) {
			$this->skip( 'filter_stock', $id, $parent_id );
			return;
		}
		$effective = null !== $item['sale_price'] ? $item['sale_price'] : $item['price'];
		if ( ( '' !== (string) $this->feed['price_min'] && $effective < (float) $this->feed['price_min'] )
			|| ( '' !== (string) $this->feed['price_max'] && $effective > (float) $this->feed['price_max'] ) ) {
			$this->skip( 'filter_price', $id, $parent_id, wc_format_decimal( $effective, 2 ) );
			return;
		}

		$custom = (string) apply_filters( 'nwr_pf_skip_item', '', $item, $product, $parent, $this->feed );
		if ( '' !== $custom ) {
			$this->skip( 'custom', $id, $parent_id, $custom );
			return;
		}
		$item = (array) apply_filters( 'nwr_pf_item', $item, $product, $parent, $this->feed );

		$item_id = (string) $item['id'];
		if ( isset( $this->seen[ $item_id ] ) ) {
			/* translators: 1: item ID, 2: product ID */
			$this->skip( 'duplicate_id', $id, $parent_id, sprintf( __( '%1$s už má produkt #%2$d', 'nwr-product-feeds' ), $item_id, $this->seen[ $item_id ] ) );
			return;
		}
		$this->seen[ $item_id ] = $id;

		$converted = $this->channel->convert( $item, $this->feed );
		foreach ( $converted['issues'] as [ $code, $detail ] ) {
			$this->issue( $code, $id, $parent_id, (string) $detail );
		}

		$this->out->rows[] = $converted['fields'];
		if ( $this->keep_items ) {
			$this->out->items[] = array(
				'item'   => $item,
				'fields' => $converted['fields'],
			);
		}
		$this->out->count( 'items' );
		$this->out->count( $parent ? 'variations' : 'simple' );
		if ( 'out_of_stock' === $item['availability'] ) {
			$this->out->count( 'out_of_stock' );
		}
	}

	/**
	 * The channel-neutral item.
	 *
	 * @return array|string item, or the reason to leave the product out
	 */
	private function normalize( WC_Product $product, ?WC_Product $parent, array $flags ) {
		$main      = $parent ?? $product;
		$id        = $product->get_id();
		$parent_id = $parent ? $parent->get_id() : 0;

		$prices = $this->prices( $product );
		if ( null === $prices ) {
			return 'no_price';
		}

		$images = $this->images( $product, $parent );
		if ( ! $images ) {
			return 'no_image';
		}

		$variation_values = array();
		if ( $parent ) {
			$variation_values = Attributes::variation_values( $product, $parent );
			foreach ( $variation_values as $data ) {
				if ( '' === $data['value'] ) {
					$this->issue( 'any_attribute', $id, $parent_id, $data['label'] );
				}
			}
		}
		$mapped = Attributes::map( $this->feed, $main, $variation_values );

		$gtin = Gtin::find( $product, $parent, ! empty( $this->feed['gtin_from_parent'] ) );
		if ( '' !== $gtin['invalid'] ) {
			$this->issue( 'invalid_gtin', $id, $parent_id, $gtin['invalid'] );
		}
		if ( 'parent' === $gtin['source'] ) {
			$this->issue( 'gtin_from_parent', $id, $parent_id, $gtin['value'] );
		}

		$term        = Categories::main_term( $main->get_id() );
		[ $brand, $brand_source ] = $this->brand( $product, $main, (string) ( $mapped['fields']['brand'] ?? '' ) );
		if ( 'default' === $brand_source ) {
			$this->issue( 'brand_default', $id, $parent_id, $brand );
		}
		$description = $this->description( $product, $parent );
		[ $availability, $available_on ] = $this->availability( $product, $parent );

		$gender = Attributes::gender( $this->own_meta( $product, $main, '_nwr_pf_gender' ) );
		if ( '' === $gender ) {
			$gender = Attributes::gender( (string) ( $mapped['fields']['gender'] ?? '' ) );
		}
		$age_group = Attributes::age_group( $this->own_meta( $product, $main, '_nwr_pf_age_group' ) );
		if ( '' === $age_group ) {
			$age_group = Attributes::age_group( (string) ( $mapped['fields']['age_group'] ?? '' ) );
		}

		$gpc = preg_replace( '/\D/', '', $this->own_meta( $product, $main, '_nwr_pf_gpc' ) ) ?? '';
		if ( '' === $gpc ) {
			$gpc = Categories::mapped( (array) $this->feed['gpc_map'], $main->get_id() );
		}
		if ( '' === $gpc ) {
			$gpc = (string) $this->feed['gpc_default'];
		}
		$fbc = Categories::mapped( (array) $this->feed['fbc_map'], $main->get_id() );
		if ( '' === $fbc ) {
			$fbc = (string) $this->feed['fbc_default'];
		}

		if ( $parent && ! isset( $this->group_ids[ $parent_id ] ) ) {
			$this->group_ids[ $parent_id ] = Item_Id::apply_filter( Item_Id::render( $parent, $this->template ), $parent, $this->filter_feed );
		}

		$item = array(
			'id'                     => Item_Id::apply_filter( Item_Id::render( $product, $this->template ), $product, $this->filter_feed ),
			'item_group_id'          => $parent ? $this->group_ids[ $parent_id ] : '',
			'item_group_title'       => $parent ? $this->title( $parent, null, array() ) : '',
			'product_id'             => $id,
			'parent_id'              => $parent_id,
			'kind'                   => $parent ? 'variation' : Product_Types::key( $product ),
			'title'                  => $this->title( $product, $parent, $variation_values ),
			'description'            => $description,
			'link'                   => $this->link( $product ),
			'image_link'             => $images[0],
			'additional_image_links' => array_slice( $images, 1 ),
			'price'                  => $prices['price'],
			'sale_price'             => $prices['sale'],
			'sale_from'              => $prices['from'],
			'sale_to'                => $prices['to'],
			'currency'               => $this->currency,
			'availability'           => $availability,
			'availability_date'      => $available_on,
			'stock_quantity'         => $product->managing_stock() ? max( 0, (int) $product->get_stock_quantity() ) : null,
			'stock_status'           => (string) $product->get_stock_status(),
			'condition'              => (string) $this->feed['condition'],
			'brand'                  => $brand,
			'gtin'                   => $gtin['value'],
			'mpn'                    => $this->mpn( $product ),
			'google_product_category' => $gpc,
			'fb_product_category'    => $fbc,
			'product_type'           => $term ? Categories::path( $term ) : '',
			'color'                  => (string) ( $mapped['fields']['color'] ?? '' ),
			'size'                   => (string) ( $mapped['fields']['size'] ?? '' ),
			'material'               => (string) ( $mapped['fields']['material'] ?? '' ),
			'pattern'                => (string) ( $mapped['fields']['pattern'] ?? '' ),
			'gender'                 => '' !== $gender ? $gender : (string) $this->feed['gender_default'],
			'age_group'              => '' !== $age_group ? $age_group : (string) $this->feed['age_group_default'],
			'variant_attributes'     => array_column( $variation_values, 'value', 'label' ),
			'extra_attributes'       => $mapped['extra'],
			'custom_labels'          => array(),
			'internal_labels'        => $this->internal_labels( $main ),
			'weight'                 => (string) $product->get_weight(),
			'weight_unit'            => (string) get_option( 'woocommerce_weight_unit', 'kg' ),
			'status'                 => empty( $flags['archived'] ) ? 'active' : 'archived',
			'on_sale'                => null !== $prices['sale'],
		);

		if ( '' === $item['description'] ) {
			$this->issue( 'missing_description', $id, $parent_id, '' );
		}
		if ( $parent && ! $product->get_image_id( 'edit' ) ) {
			$this->issue( 'variation_no_image', $id, $parent_id, '' );
		}

		$item['custom_labels'] = Labels::compute(
			$this->feed,
			array(
				'product'          => $product,
				'main'             => $main,
				'term'             => $term,
				'product_type'     => $item['product_type'],
				'brand'            => $brand,
				'price'            => null !== $prices['sale'] ? $prices['sale'] : $prices['price'],
				'on_sale'          => $item['on_sale'],
				'in_stock'         => 'out_of_stock' !== $availability,
				'kind'             => $item['kind'],
				'variation_values' => $variation_values,
				'extra'            => $mapped['extra'],
			)
		);

		return $item;
	}

	/** @return array{price:float,sale:?float,from:?int,to:?int}|null */
	private function prices( WC_Product $product ): ?array {
		$regular = (string) $product->get_regular_price();
		if ( '' === $regular ) {
			$regular = (string) $product->get_price();
		}
		if ( '' === $regular || (float) $regular <= 0 ) {
			return null;
		}
		$price = $this->with_tax( $product, (float) $regular );
		if ( $price <= 0 ) {
			return null;
		}

		$sale = null;
		$from = null;
		$to   = null;
		if ( $product->is_on_sale() && '' !== (string) $product->get_sale_price() ) {
			$value = $this->with_tax( $product, (float) $product->get_sale_price() );
			if ( $value > 0 && $value < $price ) {
				$sale = $value;
				$from = $product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->getTimestamp() : null;
				$to   = $product->get_date_on_sale_to() ? $product->get_date_on_sale_to()->getTimestamp() : null;
			}
		}

		return array(
			'price' => $price,
			'sale'  => $sale,
			'from'  => $from,
			'to'    => $to,
		);
	}

	private function with_tax( WC_Product $product, float $amount ): float {
		$args  = array(
			'qty'   => 1,
			'price' => $amount,
		);
		$value = 'excl' === $this->feed['price_tax'] ? wc_get_price_excluding_tax( $product, $args ) : wc_get_price_including_tax( $product, $args );
		return round( (float) $value, 2 );
	}

	/**
	 * Main image first: the variation's own, else the parent's. Then the
	 * variation gallery, the parent's image and the parent's gallery.
	 *
	 * @return string[]
	 */
	private function images( WC_Product $product, ?WC_Product $parent ): array {
		$ids = array( (int) $product->get_image_id( 'edit' ) );
		if ( $parent ) {
			// A variation gallery lives in the variation's own gallery field (Woodmart migrates there) or in plugin meta.
			$ids = array_merge(
				$ids,
				array_map( 'intval', $product->get_gallery_image_ids( 'edit' ) ),
				$this->variation_gallery( $product ),
				array( (int) $parent->get_image_id( 'edit' ) ),
				array_map( 'intval', $parent->get_gallery_image_ids( 'edit' ) )
			);
		} else {
			$ids = array_merge( $ids, array_map( 'intval', $product->get_gallery_image_ids( 'edit' ) ) );
		}
		$ids = array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, 25 );
		if ( ! $ids ) {
			return array();
		}
		Source::prime( $ids, false );

		$urls = array();
		foreach ( $ids as $attachment_id ) {
			$url = wp_get_attachment_image_url( $attachment_id, 'full' );
			if ( $url ) {
				$urls[] = self::ascii_url( Storage::https( $url ) );
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/** @return int[] */
	private function variation_gallery( WC_Product $variation ): array {
		$ids = array();
		foreach ( (array) apply_filters( 'nwr_pf_variation_gallery_meta_keys', self::VARIATION_GALLERY_KEYS ) as $key ) {
			$value = get_post_meta( $variation->get_id(), (string) $key, true );
			if ( is_string( $value ) ) {
				$value = explode( ',', $value );
			}
			if ( is_array( $value ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $value ) );
			}
		}
		return array_values( array_filter( $ids ) );
	}

	private function title( WC_Product $product, ?WC_Product $parent, array $variation_values ): string {
		$own = Text::line( (string) $product->get_meta( '_nwr_pf_title', true, 'edit' ) );
		if ( '' !== $own ) {
			return $own;
		}
		if ( ! $parent ) {
			return Text::line( $product->get_name( 'edit' ) );
		}
		$base = Text::line( (string) $parent->get_meta( '_nwr_pf_title', true, 'edit' ) );
		if ( '' === $base ) {
			$base = Text::line( $parent->get_name( 'edit' ) );
		}
		$values = array_values( array_filter( array_column( $variation_values, 'value' ), 'strlen' ) );
		if ( empty( $this->feed['title_attributes'] ) || ! $values ) {
			return $base;
		}
		return $base . ' – ' . implode( ', ', $values );
	}

	/**
	 * Feed text of the product → variation description → short description →
	 * parent description. Builder layouts (Elementor) are not descriptions.
	 */
	private function description( WC_Product $product, ?WC_Product $parent ): string {
		$main    = $parent ?? $product;
		$sources = array( (string) $product->get_meta( '_nwr_pf_description', true, 'edit' ) );
		if ( $parent ) {
			$sources[] = (string) $parent->get_meta( '_nwr_pf_description', true, 'edit' );
			$sources[] = (string) $product->get_description( 'edit' );
		}

		$short = (string) $main->get_short_description( 'edit' );
		$long  = (string) $main->get_description( 'edit' );
		if ( 'builder' === get_post_meta( $main->get_id(), '_elementor_edit_mode', true ) ) {
			if ( '' !== trim( $long ) && '' === Text::plain( $short ) ) {
				$this->issue( 'builder_description', $product->get_id(), $parent ? $parent->get_id() : 0, 'Elementor' );
			}
			$long = '';
		}
		if ( 'long' === $this->feed['description_order'] ) {
			array_push( $sources, $long, $short );
		} else {
			array_push( $sources, $short, $long );
		}

		foreach ( $sources as $html ) {
			$text = Text::plain( $html );
			if ( '' !== $text ) {
				return $text;
			}
		}
		return '';
	}

	private function link( WC_Product $product ): string {
		$url = (string) $product->get_permalink();
		if ( $this->utm ) {
			$url = add_query_arg( $this->utm, $url );
		}
		return self::ascii_url( Storage::https( $url ) );
	}

	/** Feeds want ASCII URLs (RFC 3986): percent-encode anything else. */
	public static function ascii_url( string $url ): string {
		return preg_replace_callback( '/[^\x21-\x7E]/', static fn( array $m ): string => rawurlencode( $m[0] ), $url ) ?? $url;
	}

	/**
	 * Same order for simple products and variations (a variation looks at its parent).
	 *
	 * @return array{0:string,1:string} brand, source (product | taxonomy | attribute | default | '')
	 */
	private function brand( WC_Product $product, WC_Product $main, string $from_attribute ): array {
		$own = Text::line( $this->own_meta( $product, $main, '_nwr_pf_brand' ) );
		if ( '' !== $own ) {
			return array( $own, 'product' );
		}
		foreach ( (array) apply_filters( 'nwr_pf_brand_taxonomies', array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand' ) ) as $taxonomy ) {
			if ( ! taxonomy_exists( (string) $taxonomy ) ) {
				continue;
			}
			$terms = get_the_terms( $main->get_id(), (string) $taxonomy );
			$name  = is_array( $terms ) && $terms ? Text::line( $terms[0]->name ) : '';
			if ( '' !== $name ) {
				return array( $name, 'taxonomy' );
			}
		}
		if ( '' !== $from_attribute ) {
			return array( $from_attribute, 'attribute' );
		}
		$default = Text::line( (string) $this->feed['brand_default'] );
		return array( $default, '' !== $default ? 'default' : '' );
	}

	/** MPN only from what the shop really has: a value set for the feed or the product's own SKU. */
	private function mpn( WC_Product $product ): string {
		$own = Text::line( (string) $product->get_meta( '_nwr_pf_mpn', true, 'edit' ) );
		if ( '' !== $own ) {
			return $own;
		}
		return empty( $this->feed['mpn_from_sku'] ) ? '' : Text::line( Item_Id::own_sku( $product ) );
	}

	/**
	 * @return array{0:string,1:?int} in_stock|out_of_stock|preorder|backorder, availability date
	 */
	private function availability( WC_Product $product, ?WC_Product $parent ): array {
		$main     = $parent ?? $product;
		$override = $this->own_meta( $product, $main, '_nwr_pf_availability' );
		$date_raw = $this->own_meta( $product, $main, '_nwr_pf_availability_date' );
		$date     = null;
		if ( '' !== $date_raw ) {
			$parsed = date_create_immutable( $date_raw . ' 00:00:00', wp_timezone() );
			$date   = $parsed ? $parsed->getTimestamp() : null;
		}

		if ( in_array( $override, array( 'in_stock', 'out_of_stock', 'preorder', 'backorder' ), true ) ) {
			return array( $override, $date );
		}

		// WooCommerce Pre-Orders.
		if ( 'yes' === get_post_meta( $main->get_id(), '_wc_pre_orders_enabled', true ) && 'outofstock' !== $product->get_stock_status() ) {
			$when = (int) get_post_meta( $main->get_id(), '_wc_pre_orders_availability_datetime', true );
			return array( 'preorder', $date ?? ( $when > time() ? $when : null ) );
		}

		switch ( $product->get_stock_status() ) {
			case 'outofstock':
				return array( 'out_of_stock', null );
			case 'onbackorder':
				return array( 'in_stock' === $this->feed['backorder'] ? 'in_stock' : 'backorder', $date );
			default:
				return array( 'in_stock', null );
		}
	}

	/** @return string[] */
	private function internal_labels( WC_Product $main ): array {
		$mode = (string) $this->feed['internal_label'];
		if ( '' === $mode ) {
			return array();
		}
		$labels = array();
		$taxes  = array(
			'tags'       => array( 'product_tag' ),
			'categories' => array( 'product_cat' ),
			'both'       => array( 'product_cat', 'product_tag' ),
		);
		$default = (int) get_option( 'default_product_cat', 0 );
		foreach ( $taxes[ $mode ] ?? array() as $taxonomy ) {
			$terms = get_the_terms( $main->get_id(), $taxonomy );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( 'product_cat' === $taxonomy && (int) $term->term_id === $default ) {
					continue;
				}
				$labels[] = ( 'product_cat' === $taxonomy ? 'cat_' : 'tag_' ) . $term->slug;
			}
		}
		return array_values( array_unique( $labels ) );
	}

	/** A value set in the product's "Feedy" tab (variation first, then its parent). */
	private function own_meta( WC_Product $product, WC_Product $main, string $key ): string {
		foreach ( array( $product, $main ) as $owner ) {
			$value = $owner->get_meta( $key, true, 'edit' );
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	private function skip( string $reason, int $id, int $parent = 0, string $detail = '' ): void {
		$this->out->skipped[] = array( $reason, $id, $parent, $detail );
		$this->out->count( 'skipped' );
	}

	private function issue( string $code, int $id, int $parent, string $detail ): void {
		$this->out->issues[] = array( $code, $id, $parent, $detail );
	}
}
