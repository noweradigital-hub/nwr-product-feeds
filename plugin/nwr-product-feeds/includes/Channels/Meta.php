<?php
namespace Nowera\ProductFeeds\Channels;

use Nowera\ProductFeeds\Catalog\Text;
use Nowera\ProductFeeds\Output\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Meta catalog (Facebook, Instagram) — RSS 2.0 in Google's g: format, which
 * Meta reads as-is. Every item field carries the prefix, as in Meta's sample
 * feed; Meta reads its own fields (status, quantity…) that way too.
 * https://www.facebook.com/business/help/120325381656392
 * https://lookaside.facebook.com/developers/resources/?id=dpa_product_catalog_sample_feed_rss.xml
 */
final class Meta extends Channel {

	const AGE_GROUPS = array( 'adult', 'all ages', 'teen', 'kids', 'toddler', 'infant', 'newborn' );

	const AVAILABILITY = array(
		'in_stock'     => 'in stock',
		'out_of_stock' => 'out of stock',
		'backorder'    => 'available for order',
		'preorder'     => 'preorder',
	);

	public function key(): string {
		return 'meta';
	}

	public function label(): string {
		return __( 'Meta katalóg', 'nwr-product-feeds' );
	}

	public function description(): string {
		return __( 'Facebook a Instagram — dynamické reklamy, Advantage+ katalógové reklamy, obchod.', 'nwr-product-feeds' );
	}

	public function defaults(): array {
		return array(
			'name'           => __( 'Meta katalóg', 'nwr-product-feeds' ),
			'slug'           => 'meta',
			'images_max'     => 20,
			'meta_quantity'  => 1,
			'internal_label' => '',
		);
	}

	public function supports_archive(): bool {
		return true;
	}

	public function convert( array $item, array $feed ): array {
		$issues = array();
		$f      = array();

		$f['g:id'] = $item['id'];
		if ( strlen( $item['id'] ) > 100 ) {
			$issues[] = array( 'id_too_long', '100' );
		}
		$f['g:title'] = $this->fit( $item['title'], 200, 'long_title', $issues );

		$description = '' !== $item['description'] ? $item['description'] : $item['title'];
		if ( mb_strlen( $description, 'UTF-8' ) < 30 ) {
			$issues[] = array( 'short_description', '30' );
		}
		$f['g:description'] = $this->fit( $description, 9999, 'long_description', $issues );

		$f['g:availability'] = self::AVAILABILITY[ $item['availability'] ] ?? 'in stock';
		$f['g:condition']    = $item['condition'];
		$f['g:price']        = $this->money( $item['price'], $item['currency'] );
		// No sale, no element: a scheduled (replace) upload then drops an old sale price.
		if ( null !== $item['sale_price'] ) {
			$f['g:sale_price'] = $this->money( $item['sale_price'], $item['currency'] );
			$range             = $this->date_range( $item['sale_from'], $item['sale_to'], 'Y-m-d\TH:iP' );
			if ( '' !== $range ) {
				$f['g:sale_price_effective_date'] = $range;
			}
		}

		$f['g:link']       = $item['link'];
		$f['g:image_link'] = $item['image_link'];
		if ( ! preg_match( '/\.(jpe?g|png)(\?|$)/i', $item['image_link'] ) ) {
			$issues[] = array( 'image_format', (string) pathinfo( (string) wp_parse_url( $item['image_link'], PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		}
		$max    = (int) $feed['images_max'] > 0 ? min( 20, (int) $feed['images_max'] ) : 20;
		$extras = array_slice( $item['additional_image_links'], 0, $max );
		if ( $extras ) {
			$f['g:additional_image_link'] = $extras;
		}

		$brand = Text::limit( $item['brand'], 100 )[0];
		$mpn   = Text::limit( $item['mpn'], 100 )[0];
		if ( '' !== $brand ) {
			$f['g:brand'] = $brand;
		} else {
			$issues[] = array( 'missing_brand', '' );
		}
		if ( '' !== $item['gtin'] ) {
			$f['g:gtin'] = $item['gtin'];
		}
		if ( '' !== $mpn ) {
			$f['g:mpn'] = $mpn;
		}
		if ( '' === $brand && '' === $item['gtin'] && '' === $mpn ) {
			$issues[] = array( 'missing_identifiers', '' );
		}

		if ( '' !== $item['item_group_id'] ) {
			$f['g:item_group_id'] = $item['item_group_id'];
			if ( strlen( $item['item_group_id'] ) > 100 ) {
				$issues[] = array( 'id_too_long', 'item_group_id 100' );
			}
		}

		if ( '' !== $item['google_product_category'] ) {
			$f['g:google_product_category'] = $item['google_product_category'];
		}
		if ( '' !== $item['fb_product_category'] ) {
			$f['g:fb_product_category'] = $item['fb_product_category'];
		}
		if ( '' !== $item['product_type'] ) {
			$f['g:product_type'] = Text::limit( $item['product_type'], 750 )[0];
		}

		foreach ( array(
			'color'    => 200,
			'size'     => 200,
			'material' => 200,
			'pattern'  => 100,
		) as $key => $limit ) {
			if ( '' !== $item[ $key ] ) {
				$f[ 'g:' . $key ] = Text::limit( $item[ $key ], $limit )[0];
			}
		}
		if ( in_array( $item['gender'], array( 'female', 'male', 'unisex' ), true ) ) {
			$f['g:gender'] = $item['gender'];
		}
		if ( in_array( $item['age_group'], self::AGE_GROUPS, true ) ) {
			$f['g:age_group'] = $item['age_group'];
		}

		if ( ! empty( $feed['extra_attributes'] ) && $item['extra_attributes'] ) {
			$pairs = array();
			foreach ( $item['extra_attributes'] as $label => $value ) {
				$pairs[] = new Node(
					array(
						'g:label' => (string) $label,
						'g:value' => (string) $value,
					)
				);
			}
			$f['g:additional_variant_attribute'] = $pairs;
		}

		foreach ( $item['custom_labels'] as $slot => $label ) {
			if ( '' !== $label ) {
				$f[ 'g:custom_label_' . $slot ] = $label;
			}
		}

		if ( $item['internal_labels'] ) {
			$labels = array();
			foreach ( $item['internal_labels'] as $label ) {
				$label = substr( strtolower( remove_accents( (string) $label ) ), 0, 110 );
				if ( '' !== $label ) {
					$labels[] = $label;
				}
			}
			$f['g:internal_label'] = array_slice( array_values( array_unique( $labels ) ), 0, 5000 );
		}

		// Goods ordered in (backorder, pre-order) have no pieces to count; 0 would read as sold out.
		if ( ! empty( $feed['meta_quantity'] ) && null !== $item['stock_quantity'] && 'onbackorder' !== $item['stock_status'] && in_array( $item['availability'], array( 'in_stock', 'out_of_stock' ), true ) ) {
			$f['g:quantity_to_sell_on_facebook'] = (string) $item['stock_quantity'];
		}

		$f['g:status'] = $item['status'];

		$shipping = $this->shipping( $feed, $item['currency'], 'g:' );
		if ( $shipping ) {
			$f['g:shipping'] = $shipping;
		}
		if ( ! empty( $feed['shipping_weight'] ) ) {
			$weight = $this->weight( $item );
			if ( '' !== $weight ) {
				$f['g:shipping_weight'] = $weight;
			}
		}

		return array(
			'fields' => $f,
			'issues' => $issues,
		);
	}

	public function columns( array $feed ): array {
		$columns = array( 'g:id', 'g:title', 'g:description', 'g:availability', 'g:condition', 'g:price', 'g:sale_price', 'g:sale_price_effective_date', 'g:link', 'g:image_link', 'g:additional_image_link', 'g:brand', 'g:gtin', 'g:mpn', 'g:item_group_id', 'g:google_product_category', 'g:fb_product_category', 'g:product_type', 'g:color', 'g:size', 'g:material', 'g:pattern', 'g:gender', 'g:age_group', 'g:additional_variant_attribute' );
		for ( $i = 0; $i < 5; $i++ ) {
			$columns[] = 'g:custom_label_' . $i;
		}
		if ( '' !== (string) $feed['internal_label'] ) {
			$columns[] = 'g:internal_label';
		}
		if ( ! empty( $feed['meta_quantity'] ) ) {
			$columns[] = 'g:quantity_to_sell_on_facebook';
		}
		$columns[] = 'g:status';
		if ( '' !== (string) $feed['shipping_price'] ) {
			$columns[] = 'g:shipping';
		}
		if ( ! empty( $feed['shipping_weight'] ) ) {
			$columns[] = 'g:shipping_weight';
		}
		return $columns;
	}

	public function flatten( string $key, $value ): string {
		if ( 'g:shipping' === $key && $value instanceof Node ) {
			return $this->shipping_text( $value );
		}
		if ( 'g:additional_variant_attribute' === $key && is_array( $value ) ) {
			$pairs = array();
			foreach ( $value as $node ) {
				if ( $node instanceof Node ) {
					$pairs[] = str_replace( array( ':', ',' ), ' ', (string) ( $node->children['g:label'] ?? '' ) ) . ':' . str_replace( array( ':', ',' ), ' ', (string) ( $node->children['g:value'] ?? '' ) );
				}
			}
			return implode( ', ', $pairs );
		}
		if ( 'g:internal_label' === $key && is_array( $value ) ) {
			return '[' . implode( ',', array_map( static fn( $label ): string => "'" . str_replace( array( "'", ',' ), '', (string) $label ) . "'", $value ) ) . ']';
		}
		return parent::flatten( $key, $value );
	}
}
