<?php
namespace Nowera\ProductFeeds\Output;

use Nowera\ProductFeeds\Channels\Channel;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Streams a feed into a file across several background batches:
 * begin() once, append() per batch, finish() once.
 */
abstract class Writer {

	public function __construct( protected array $feed, protected Channel $channel ) {}

	public static function for_feed( array $feed, Channel $channel ): self {
		$class = $channel->writer_class( (string) $feed['format'] );
		if ( ! is_subclass_of( $class, self::class ) ) {
			throw new RuntimeException( 'Neplatný zapisovač feedu: ' . $class );
		}
		return new $class( $feed, $channel );
	}

	abstract public function begin( string $path ): void;

	/** @param list<array<string,mixed>> $rows channel fields per item */
	abstract public function append( string $path, array $rows ): void;

	abstract public function finish( string $path ): void;

	/** @return resource */
	protected function open( string $path, string $mode ) {
		$handle = @fopen( $path, $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $handle ) {
			throw new RuntimeException( sprintf( 'Súbor %s sa nedá otvoriť na zápis.', basename( $path ) ) );
		}
		return $handle;
	}

	/** @param resource $handle */
	protected function write( $handle, string $data ): void {
		if ( '' === $data ) {
			return;
		}
		if ( false === fwrite( $handle, $data ) ) {
			throw new RuntimeException( 'Zápis do súboru feedu zlyhal (plný disk?).' );
		}
	}

	/** @param resource $handle */
	protected function close( $handle ): void {
		if ( ! fclose( $handle ) ) {
			throw new RuntimeException( 'Súbor feedu sa nepodarilo uzavrieť.' );
		}
	}
}
