<?php
namespace Nowera\ProductFeeds\Channels;

use Nowera\ProductFeeds\Catalog\Text;
use Nowera\ProductFeeds\Output\Delimited_Writer;
use Nowera\ProductFeeds\Output\Node;
use Nowera\ProductFeeds\Output\Rss_Writer;

defined( 'ABSPATH' ) || exit;

/**
 * A feed template: turns channel-neutral items into one platform's fields.
 *
 * Field keys carry their XML prefix ("g:price"). Values are strings, lists of
 * strings (repeated element) or Node / list of Node (element with sub-fields).
 * Empty values never become elements: an optional field without a value is
 * left out of the XML.
 *
 * Register more channels (Heureka, Glami…) with the `nwr_pf_channels` filter.
 */
abstract class Channel {

	abstract public function key(): string;

	abstract public function label(): string;

	/**
	 * @return array{fields:array<string,mixed>,issues:list<array{0:string,1:string}>}
	 */
	abstract public function convert( array $item, array $feed ): array;

	/** Field keys in CSV/TSV column order. */
	abstract public function columns( array $feed ): array;

	public function description(): string {
		return '';
	}

	/** @return string[] */
	public function formats(): array {
		return array( 'xml', 'csv', 'tsv' );
	}

	/** Settings a new feed of this channel starts with. */
	public function defaults(): array {
		return array();
	}

	/** Whether hidden products can be sent as archived instead of being left out. */
	public function supports_archive(): bool {
		return false;
	}

	/** Writer class for a format (a channel with its own XML shape can return its own). */
	public function writer_class( string $format ): string {
		return 'xml' === $format ? Rss_Writer::class : Delimited_Writer::class;
	}

	/** Channel title/description written into the XML header (RSS requires both, so never empty). */
	public function header( array $feed ): array {
		$site  = trim( (string) get_bloginfo( 'name' ) );
		$title = '' !== trim( (string) $feed['name'] ) ? (string) $feed['name'] : $site;
		$title = '' !== $title ? $title : (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$about = trim( (string) get_bloginfo( 'description' ) );
		return array(
			'title'       => $title,
			'link'        => home_url( '/' ),
			'description' => '' !== $about ? $about : $title,
		);
	}

	/** One CSV/TSV cell. */
	public function flatten( string $key, $value ): string {
		if ( $value instanceof Node ) {
			return implode( ':', $value->children );
		}
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $part ) {
				$parts[] = $part instanceof Node ? implode( ':', $part->children ) : (string) $part;
			}
			return implode( ',', $parts );
		}
		return (string) $value;
	}

	/* ------------------------------------------------------------ helpers */

	protected function money( float $amount, string $currency ): string {
		return number_format( round( $amount, 2 ), 2, '.', '' ) . ' ' . $currency;
	}

	/** Shortens text and records an issue when it had to. */
	protected function fit( string $value, int $max, string $issue, array &$issues ): string {
		[ $text, $cut ] = Text::limit( $value, $max );
		if ( $cut && '' !== $issue ) {
			$issues[] = array( $issue, (string) $max );
		}
		return $text;
	}

	/** At most $count values joined with "/", each and the whole within limits. */
	protected function multi( string $value, int $count, int $each, int $total ): string {
		$parts = array_slice( array_values( array_filter( array_map( 'trim', explode( '/', $value ) ), 'strlen' ) ), 0, max( 1, $count ) );
		$parts = array_map( static fn( string $part ): string => Text::limit( $part, $each )[0], $parts );
		return Text::limit( implode( '/', $parts ), $total )[0];
	}

	/** "start/end" in the site's timezone; '' unless the sale has an end date. */
	protected function date_range( ?int $from, ?int $to, string $format ): string {
		if ( ! $to ) {
			return '';
		}
		$from = $from ?: (int) strtotime( 'today midnight', time() );
		return wp_date( $format, $from ) . '/' . wp_date( $format, $to );
	}

	protected function shipping( array $feed, string $currency, string $prefix ): ?Node {
		if ( '' === (string) $feed['shipping_price'] ) {
			return null;
		}
		$country  = '' !== (string) $feed['country'] ? (string) $feed['country'] : WC()->countries->get_base_country();
		$children = array( $prefix . 'country' => $country );
		if ( '' !== (string) $feed['shipping_service'] ) {
			$children[ $prefix . 'service' ] = (string) $feed['shipping_service'];
		}
		$children[ $prefix . 'price' ] = $this->money( (float) $feed['shipping_price'], $currency );
		return new Node( $children );
	}

	/** Google/Meta text form of a shipping node: country:region:service:price. */
	protected function shipping_text( Node $node ): string {
		$values = array_values( $node->children );
		$keys   = array_map( static fn( string $k ): string => preg_replace( '/^g:/', '', $k ) ?? $k, array_keys( $node->children ) );
		$map    = array_combine( $keys, $values );
		return ( $map['country'] ?? '' ) . '::' . ( $map['service'] ?? '' ) . ':' . ( $map['price'] ?? '' );
	}

	protected function weight( array $item ): string {
		$weight = (string) $item['weight'];
		if ( '' === $weight || (float) $weight <= 0 ) {
			return '';
		}
		$unit = strtolower( (string) $item['weight_unit'] );
		$unit = in_array( $unit, array( 'kg', 'g', 'lb', 'oz' ), true ) ? $unit : ( 'lbs' === $unit ? 'lb' : 'kg' );
		return wc_format_decimal( $weight ) . ' ' . $unit;
	}
}
