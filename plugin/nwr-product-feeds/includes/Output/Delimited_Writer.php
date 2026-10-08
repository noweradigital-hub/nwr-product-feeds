<?php
namespace Nowera\ProductFeeds\Output;

use Nowera\ProductFeeds\Catalog\Text;

defined( 'ABSPATH' ) || exit;

/**
 * CSV (RFC 4180) or TSV, UTF-8 without BOM. One header row with the
 * channel's column names; values never contain line breaks.
 */
class Delimited_Writer extends Writer {

	private function tsv(): bool {
		return 'tsv' === $this->feed['format'];
	}

	/** Column keys (with prefix) and header names (without). */
	private function columns(): array {
		return $this->channel->columns( $this->feed );
	}

	public function begin( string $path ): void {
		$names  = array_map( static fn( string $key ): string => preg_replace( '/^g:/', '', $key ) ?? $key, $this->columns() );
		$handle = $this->open( $path, 'wb' );
		$this->write( $handle, $this->line( $names ) );
		$this->close( $handle );
	}

	public function append( string $path, array $rows ): void {
		if ( ! $rows ) {
			return;
		}
		$columns = $this->columns();
		$handle  = $this->open( $path, 'ab' );
		$data    = '';
		foreach ( $rows as $fields ) {
			$cells = array();
			foreach ( $columns as $key ) {
				$cells[] = isset( $fields[ $key ] ) ? $this->channel->flatten( $key, $fields[ $key ] ) : '';
			}
			$data .= $this->line( $cells );
		}
		$this->write( $handle, $data );
		$this->close( $handle );
	}

	public function finish( string $path ): void {
		// Nothing to close: a delimited file has no footer.
	}

	/** One line; count of lines = count of rows (values carry no line breaks). */
	private function line( array $cells ): string {
		$cells = array_map(
			static fn( $cell ): string => trim( preg_replace( '/[\r\n\t]+/', ' ', Text::scrub( (string) $cell ) ) ?? '' ),
			$cells
		);
		if ( $this->tsv() ) {
			return implode( "\t", $cells ) . "\n";
		}
		$out = array();
		foreach ( $cells as $cell ) {
			$out[] = preg_match( '/[",\s]/', $cell ) || '' === $cell ? '"' . str_replace( '"', '""', $cell ) . '"' : $cell;
		}
		return implode( ',', $out ) . "\n";
	}
}
