<?php
namespace Nowera\ProductFeeds\Channels;

use Nowera\ProductFeeds\Catalog\Text;
use Nowera\ProductFeeds\Output\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Google Merchant Center product data (RSS 2.0, every attribute with g:).
 * https://support.google.com/merchants/answer/7052112
 */
final class Google extends Channel {

	const AGE_GROUPS = array( 'newborn', 'infant', 'toddler', 'kids', 'adult' );

	public function key(): string {
		return 'google';
	}

	public function label(): string {
		return __( 'Google Merchant Center', 'nwr-product-feeds' );
	}

	public function description(): string {
		return __( 'Google Shopping, Performance Max a bezplatné záznamy.', 'nwr-product-feeds' );
	}

	public function defaults(): array {
		return array(
			'name'            => __( 'Google Merchant Center', 'nwr-product-feeds' ),
			'slug'            => 'google',
			'images_max'      => 10,
			'shipping_weight' => 0,
		);
	}

	public function convert( array $item, array $feed ): array {
		$issues = array();
		$f      = array();

		$f['g:id'] = $item['id'];
		if ( strlen( $item['id'] ) > 50 ) {
			$issues[] = array( 'id_too_long', '50' );
		}
		$f['g:title']       = $this->fit( $item['title'], 150, 'long_title', $issues );
		$f['g:description'] = $this->fit( '' !== $item['description'] ? $item['description'] : $item['title'], 5000, 'long_description', $issues );
		$f['g:link']        = $item['link'];
		$f['g:image_link']  = $item['image_link'];

		$max    = (int) $feed['images_max'] > 0 ? min( 10, (int) $feed['images_max'] ) : 10;
		$extras = array_slice( $item['additional_image_links'], 0, $max );
		if ( $extras ) {
			$f['g:additional_image_link'] = $extras;
		}

		$f['g:availability'] = $item['availability'];
		if ( in_array( $item['availability'], array( 'preorder', 'backorder' ), true ) ) {
			// Required with both values. Without a date on the product: the feed's "available in N days".
			$days = max( 1, (int) ( $feed['backorder_days'] ?? 14 ) );
			$date = $item['availability_date'] ?: time() + $days * DAY_IN_SECONDS;
			$f['g:availability_date'] = wp_date( 'Y-m-d\TH:iO', (int) $date );
			if ( ! $item['availability_date'] ) {
				$issues[] = array( 'availability_date_guessed', $item['availability'] );
			}
		}

		$f['g:price'] = $this->money( $item['price'], $item['currency'] );
		if ( null !== $item['sale_price'] ) {
			$f['g:sale_price'] = $this->money( $item['sale_price'], $item['currency'] );
			$range             = $this->date_range( $item['sale_from'], $item['sale_to'], 'Y-m-d\TH:iO' );
			if ( '' !== $range ) {
				$f['g:sale_price_effective_date'] = $range;
			}
		}

		$f['g:condition'] = $item['condition'];

		$brand = Text::limit( $item['brand'], 70 )[0];
		$mpn   = Text::limit( $item['mpn'], 70 )[0];
		if ( '' !== $brand ) {
			$f['g:brand'] = $brand;
		} else {
			$issues[] = array( 'missing_brand', '' );
		}
		if ( '' !== $item['gtin'] ) {
			$f['g:gtin'] = $item['gtin'];
		} else {
			$issues[] = array( 'missing_gtin', '' );
		}
		if ( '' !== $mpn ) {
			$f['g:mpn'] = $mpn;
		}
		// No GTIN and no brand + MPN pair: tell Google the product has no identifiers.
		if ( '' === $item['gtin'] && ( '' === $mpn || '' === $brand ) ) {
			$f['g:identifier_exists'] = 'no';
		}

		if ( '' !== $item['item_group_id'] ) {
			$f['g:item_group_id'] = $item['item_group_id'];
			if ( strlen( $item['item_group_id'] ) > 50 ) {
				$issues[] = array( 'id_too_long', 'item_group_id 50' );
			}
			if ( '' !== $item['item_group_title'] ) {
				$f['g:item_group_title'] = Text::limit( $item['item_group_title'], 150 )[0];
			}
		}

		if ( '' !== $item['google_product_category'] ) {
			$f['g:google_product_category'] = $item['google_product_category'];
		} else {
			$issues[] = array( 'missing_gpc', '' );
		}
		if ( '' !== $item['product_type'] ) {
			$f['g:product_type'] = Text::limit( $item['product_type'], 750 )[0];
		}

		if ( '' !== $item['color'] ) {
			$f['g:color'] = $this->multi( $item['color'], 3, 40, 100 );
		}
		if ( '' !== $item['size'] ) {
			$f['g:size'] = Text::limit( $item['size'], 100 )[0];
		}
		if ( '' !== $item['material'] ) {
			$f['g:material'] = $this->multi( $item['material'], 3, 200, 200 );
		}
		if ( '' !== $item['pattern'] ) {
			$f['g:pattern'] = Text::limit( $item['pattern'], 100 )[0];
		}
		if ( in_array( $item['gender'], array( 'male', 'female', 'unisex' ), true ) ) {
			$f['g:gender'] = $item['gender'];
		}
		if ( in_array( $item['age_group'], self::AGE_GROUPS, true ) ) {
			$f['g:age_group'] = $item['age_group'];
		}

		// Variation attributes outside Google's standard ones (e.g. "Nosnosť").
		if ( ! empty( $feed['extra_attributes'] ) && $item['extra_attributes'] ) {
			$options = array();
			foreach ( array_slice( $item['extra_attributes'], 0, 30, true ) as $name => $value ) {
				$options[] = new Node(
					array(
						'g:name'  => Text::limit( (string) $name, 250 )[0],
						'g:value' => Text::limit( (string) $value, 250 )[0],
					)
				);
			}
			$f['g:variant_option'] = $options;
		}

		foreach ( $item['custom_labels'] as $slot => $label ) {
			if ( '' !== $label ) {
				$f[ 'g:custom_label_' . $slot ] = $label;
			}
		}

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
		$columns = array( 'g:id', 'g:title', 'g:description', 'g:link', 'g:image_link', 'g:additional_image_link', 'g:availability', 'g:availability_date', 'g:price', 'g:sale_price', 'g:sale_price_effective_date', 'g:condition', 'g:brand', 'g:gtin', 'g:mpn', 'g:identifier_exists', 'g:item_group_id', 'g:item_group_title', 'g:google_product_category', 'g:product_type', 'g:color', 'g:size', 'g:material', 'g:pattern', 'g:gender', 'g:age_group' );
		for ( $i = 0; $i < 5; $i++ ) {
			$columns[] = 'g:custom_label_' . $i;
		}
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
		return parent::flatten( $key, $value );
	}
}
