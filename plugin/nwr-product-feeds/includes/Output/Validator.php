<?php
namespace Nowera\ProductFeeds\Output;

use XMLReader;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a finished file before it replaces the public one: well-formed,
 * right start, and exactly as many items as the run wrote.
 */
final class Validator {

	/** @return array{ok:bool,items:int,error:string} */
	public static function check( string $path, string $format, int $expected ): array {
		clearstatcache( true, $path );
		if ( ! is_file( $path ) ) {
			return self::fail( 'Dočasný súbor feedu chýba.' );
		}
		$head = (string) file_get_contents( $path, false, null, 0, 16 );
		if ( str_starts_with( $head, "\xEF\xBB\xBF" ) ) {
			return self::fail( 'Súbor začína znakom BOM.' );
		}

		if ( 'xml' === $format ) {
			if ( ! str_starts_with( $head, '<?xml' ) ) {
				return self::fail( 'Súbor nezačína deklaráciou <?xml.' );
			}
			$result = self::count_xml_items( $path );
		} else {
			$result = self::count_lines( $path );
		}

		if ( ! $result['ok'] ) {
			return $result;
		}
		if ( $result['items'] !== $expected ) {
			return self::fail( sprintf( 'Súbor má %1$d položiek, zapísaných bolo %2$d.', $result['items'], $expected ), $result['items'] );
		}
		return $result;
	}

	/** Streams the XML (constant memory) and counts <item> elements. */
	private static function count_xml_items( string $path ): array {
		if ( ! class_exists( XMLReader::class ) ) {
			// No XMLReader on this server: at least check the file is complete.
			$size = (int) filesize( $path );
			$tail = (string) file_get_contents( $path, false, null, max( 0, $size - 32 ) );
			$ok   = str_contains( $tail, '</rss>' );
			return array(
				'ok'    => $ok,
				'items' => $ok ? self::grep_items( $path ) : 0,
				'error' => $ok ? '' : 'Súbor nie je ukončený značkou </rss>.',
			);
		}

		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();
		$reader = new XMLReader();
		if ( ! $reader->open( $path, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT ) ) {
			libxml_use_internal_errors( $previous );
			return self::fail( 'Súbor feedu sa nedá otvoriť ako XML.' );
		}

		$items = 0;
		$root  = '';
		while ( @$reader->read() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( XMLReader::ELEMENT === $reader->nodeType ) {
				if ( '' === $root ) {
					$root = $reader->name;
				}
				if ( 'item' === $reader->name && 2 === $reader->depth ) {
					++$items;
				}
			}
		}
		$errors = libxml_get_errors();
		$reader->close();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( $errors ) {
			$first = $errors[0];
			return self::fail( sprintf( 'Neplatné XML (riadok %1$d): %2$s', $first->line, trim( $first->message ) ), $items );
		}
		if ( 'rss' !== $root ) {
			return self::fail( 'Koreňový element nie je <rss>.', $items );
		}
		return array(
			'ok'    => true,
			'items' => $items,
			'error' => '',
		);
	}

	private static function grep_items( string $path ): int {
		$count  = 0;
		$handle = fopen( $path, 'rb' );
		while ( $handle && false !== ( $line = fgets( $handle ) ) ) {
			if ( "\t<item>\n" === $line ) {
				++$count;
			}
		}
		if ( $handle ) {
			fclose( $handle );
		}
		return $count;
	}

	/** Data rows of a CSV/TSV file (lines minus the header). */
	private static function count_lines( string $path ): array {
		$lines  = 0;
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return self::fail( 'Súbor feedu sa nedá otvoriť.' );
		}
		while ( false !== fgets( $handle ) ) {
			++$lines;
		}
		fclose( $handle );
		if ( $lines < 1 ) {
			return self::fail( 'Súbor nemá hlavičku.' );
		}
		return array(
			'ok'    => true,
			'items' => $lines - 1,
			'error' => '',
		);
	}

	private static function fail( string $error, int $items = 0 ): array {
		return array(
			'ok'    => false,
			'items' => $items,
			'error' => $error,
		);
	}
}
