<?php
namespace Nowera\ProductFeeds\Output;

use Nowera\ProductFeeds\Catalog\Text;

defined( 'ABSPATH' ) || exit;

/**
 * RSS 2.0 with the Google namespace — read by Google Merchant Center and Meta.
 * UTF-8 without BOM, starts with <?xml, characters XML forbids are dropped.
 */
class Rss_Writer extends Writer {

	const NAMESPACE_G = 'http://base.google.com/ns/1.0';

	public function begin( string $path ): void {
		$header = $this->channel->header( $this->feed );
		$handle = $this->open( $path, 'wb' );
		$this->write(
			$handle,
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0" xmlns:g="' . self::NAMESPACE_G . '">' . "\n"
			. "<channel>\n"
			. "\t<title>" . self::escape( (string) $header['title'] ) . "</title>\n"
			. "\t<link>" . self::escape( (string) $header['link'] ) . "</link>\n"
			. "\t<description>" . self::escape( (string) $header['description'] ) . "</description>\n"
		);
		$this->close( $handle );
	}

	public function append( string $path, array $rows ): void {
		if ( ! $rows ) {
			return;
		}
		$handle = $this->open( $path, 'ab' );
		foreach ( $rows as $fields ) {
			$xml = "\t<item>\n";
			foreach ( $fields as $name => $value ) {
				$xml .= $this->field( (string) $name, $value );
			}
			$this->write( $handle, $xml . "\t</item>\n" );
		}
		$this->close( $handle );
	}

	public function finish( string $path ): void {
		$handle = $this->open( $path, 'ab' );
		$this->write( $handle, "</channel>\n</rss>\n" );
		$this->close( $handle );
	}

	/** One element; empty values (and nodes without any value) are left out. */
	private function field( string $name, $value ): string {
		if ( ! self::valid_name( $name ) ) {
			return '';
		}
		if ( $value instanceof Node ) {
			return $this->node( $name, $value );
		}
		if ( is_array( $value ) ) {
			$xml = '';
			foreach ( $value as $entry ) {
				$xml .= $this->field( $name, $entry );
			}
			return $xml;
		}
		$text = self::escape( (string) $value );
		if ( '' === trim( $text ) ) {
			return '';
		}
		return "\t\t<{$name}>{$text}</{$name}>\n";
	}

	private function node( string $name, Node $node ): string {
		$inner = '';
		foreach ( $node->children as $child => $value ) {
			$text = self::escape( (string) $value );
			if ( self::valid_name( (string) $child ) && '' !== trim( $text ) ) {
				$inner .= "\t\t\t<{$child}>{$text}</{$child}>\n";
			}
		}
		return '' === $inner ? '' : "\t\t<{$name}>\n{$inner}\t\t</{$name}>\n";
	}

	private static function valid_name( string $name ): bool {
		return (bool) preg_match( '/^(?:g:)?[a-z][a-z0-9_]*$/', $name );
	}

	public static function escape( string $value ): string {
		return htmlspecialchars( Text::scrub( $value ), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
