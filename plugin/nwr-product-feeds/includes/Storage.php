<?php
namespace Nowera\ProductFeeds;

use RuntimeException;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Feed files in wp-content/uploads/nwr-feeds/. The web server serves them as
 * static files — no PHP, no page cache. Runs build in .tmp/ (same filesystem)
 * and replace the public file with one rename, so a reader never sees a
 * half-written feed and a failed run leaves the last good file in place.
 */
final class Storage {

	const DIR    = 'nwr-feeds';
	const TMP    = '.tmp';
	const MARKER = '# nwr-product-feeds';

	public static function base_dir(): string {
		return trailingslashit( wp_get_upload_dir()['basedir'] ) . self::DIR;
	}

	public static function tmp_dir(): string {
		return self::base_dir() . '/' . self::TMP;
	}

	public static function base_url(): string {
		$url = trailingslashit( wp_get_upload_dir()['baseurl'] ) . self::DIR;
		return self::https( $url );
	}

	/** Upgrades http:// URLs of an https site (cron runs see is_ssl() === false). */
	public static function https( string $url ): string {
		if ( str_starts_with( $url, 'http://' ) && function_exists( 'wp_is_using_https' ) && wp_is_using_https() ) {
			return 'https://' . substr( $url, 7 );
		}
		return $url;
	}

	/**
	 * Creates the folders with their index.php and .htaccess.
	 *
	 * @return true|WP_Error
	 */
	public static function ensure() {
		$dir = self::base_dir();
		if ( ! wp_mkdir_p( self::tmp_dir() ) ) {
			return new WP_Error( 'nwr_pf_dir', sprintf( 'Priečinok %s sa nepodarilo vytvoriť.', $dir ) );
		}
		if ( ! wp_is_writable( $dir ) || ! wp_is_writable( self::tmp_dir() ) ) {
			return new WP_Error( 'nwr_pf_dir', sprintf( 'Do priečinka %s sa nedá zapisovať.', $dir ) );
		}
		self::protect( $dir, self::htaccess_public() );
		self::protect( self::tmp_dir(), self::htaccess_private() );
		return true;
	}

	private static function protect( string $dir, string $htaccess ): void {
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
		$file    = $dir . '/.htaccess';
		$current = file_exists( $file ) ? (string) file_get_contents( $file ) : null;
		// Never overwrite a file someone else put there.
		if ( $current !== $htaccess && ( null === $current || str_starts_with( $current, self::MARKER ) ) ) {
			file_put_contents( $file, $htaccess );
		}
	}

	private static function htaccess_public(): string {
		$rules = self::MARKER . "\n"
			. "Options -Indexes\n"
			. "<IfModule mod_mime.c>\n"
			. "\tAddType application/xml .xml\n"
			. "\tAddType text/csv .csv\n"
			. "\tAddType text/tab-separated-values .tsv\n"
			. "\tAddCharset UTF-8 .xml .csv .tsv\n"
			. "</IfModule>\n"
			. "<IfModule mod_headers.c>\n"
			. "\t<FilesMatch \"\\.(xml|csv|tsv)$\">\n"
			. "\t\tHeader set X-Robots-Tag \"noindex, nofollow\"\n"
			. "\t\tHeader set Cache-Control \"public, max-age=300\"\n"
			. "\t</FilesMatch>\n"
			. "</IfModule>\n";
		return (string) apply_filters( 'nwr_pf_htaccess', $rules );
	}

	private static function htaccess_private(): string {
		return self::MARKER . "\n"
			. "Options -Indexes\n"
			. "<IfModule mod_authz_core.c>\n"
			. "\tRequire all denied\n"
			. "</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n"
			. "\tOrder allow,deny\n"
			. "\tDeny from all\n"
			. "</IfModule>\n";
	}

	public static function extension( array $feed ): string {
		return in_array( $feed['format'], array( 'csv', 'tsv' ), true ) ? $feed['format'] : 'xml';
	}

	public static function file_name( array $feed ): string {
		return $feed['token'] . '-' . $feed['slug'] . '.' . self::extension( $feed );
	}

	public static function path( array $feed ): string {
		return self::base_dir() . '/' . self::file_name( $feed );
	}

	public static function url( array $feed ): string {
		return self::base_url() . '/' . rawurlencode( self::file_name( $feed ) );
	}

	public static function tmp_path( array $feed, string $run_id ): string {
		return self::tmp_dir() . '/' . $feed['id'] . '-' . $run_id . '.part';
	}

	public static function pending_path( array $feed ): string {
		return self::tmp_dir() . '/' . $feed['id'] . '-pending.' . self::extension( $feed );
	}

	/** @return array{size:int,modified:int}|null */
	public static function info( array $feed ): ?array {
		$path = self::path( $feed );
		clearstatcache( true, $path );
		if ( ! is_file( $path ) ) {
			return null;
		}
		return array(
			'size'     => (int) filesize( $path ),
			'modified' => (int) filemtime( $path ),
		);
	}

	/**
	 * Moves a finished file into place in one step (rename is atomic on the
	 * same filesystem). Removes the feed's previous file when its name changed.
	 *
	 * @return string published file name
	 */
	public static function publish( string $source, array $feed, string $previous = '' ): string {
		$target = self::path( $feed );
		@chmod( $source, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! @rename( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			throw new RuntimeException( sprintf( 'Súbor feedu sa nepodarilo presunúť na %s.', $target ) );
		}
		clearstatcache( true, $target );

		$name = self::file_name( $feed );
		if ( '' !== $previous && $previous !== $name ) {
			self::unlink_public( $previous );
		}
		return $name;
	}

	public static function delete_feed_files( array $feed ): void {
		self::unlink_public( self::file_name( $feed ) );
		$report = State::report( $feed['id'] );
		if ( ! empty( $report['file'] ) ) {
			self::unlink_public( (string) $report['file'] );
		}
		self::delete_tmp( $feed['id'] . '-*' );
	}

	public static function delete_tmp( string $pattern ): void {
		foreach ( (array) glob( self::tmp_dir() . '/' . basename( $pattern ) ) as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}

	/** Leftovers of runs that died (fatal error, timeout). */
	public static function cleanup_tmp( int $max_age = DAY_IN_SECONDS ): void {
		foreach ( (array) glob( self::tmp_dir() . '/*.part' ) as $file ) {
			if ( is_file( $file ) && filemtime( $file ) < time() - $max_age ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}

	private static function unlink_public( string $name ): void {
		$name = basename( $name );
		if ( preg_match( '/^[a-f0-9]{16,40}-[a-z0-9-]+\.(xml|csv|tsv)$/', $name ) ) {
			$path = self::base_dir() . '/' . $name;
			if ( is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}

	public static function new_token(): string {
		return bin2hex( random_bytes( 8 ) );
	}
}
