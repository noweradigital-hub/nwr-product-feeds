<?php
namespace Nowera\ProductFeeds;

defined( 'ABSPATH' ) || exit;

/**
 * Updates from GitHub Releases, so ~30 shops update like any other plugin.
 *
 * The plugin header has "Update URI: https://github.com/…", so WordPress asks
 * the `update_plugins_github.com` filter instead of wordpress.org. A release
 * must be tagged with the version (v1.0.1 or 1.0.1) and carry the built
 * nwr-product-feeds-<version>.zip as an asset (tools/build.sh). The repository
 * (Settings → github_repo) has to be public; NWR_PF_GITHUB_TOKEN in
 * wp-config.php only raises the API rate limit.
 */
final class Updater {

	const CACHE = 'nwr_pf_release';

	public function register(): void {
		if ( ! Settings::get( 'updates' ) ) {
			return;
		}
		add_filter( 'update_plugins_github.com', array( $this, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'forget' ), 10, 2 );
	}

	/** @return array|false */
	public function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( PLUGIN_FILE ) !== $plugin_file ) {
			return $update;
		}
		$release = self::release();
		if ( ! $release ) {
			return $update;
		}
		return array(
			'id'           => (string) ( $plugin_data['UpdateURI'] ?? '' ),
			'slug'         => SLUG,
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires_php' => '8.1',
			'requires'     => '6.2',
			'tested'       => '',
			'icons'        => array(),
			'banners'      => array(),
		);
	}

	public function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = self::release();
		if ( ! $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Produktové feedy — Google & Meta',
			'slug'          => SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://nowera.sk">Nowera</a>',
			'homepage'      => 'https://github.com/' . Settings::get( 'github_repo' ),
			'download_link' => $release['package'],
			'requires'      => '6.2',
			'requires_php'  => '8.1',
			'last_updated'  => $release['published'],
			'sections'      => array(
				'description' => esc_html__( 'Produktové feedy z WooCommerce pre Google Merchant Center a Meta katalóg.', 'nwr-product-feeds' ),
				'changelog'   => wpautop( esc_html( $release['notes'] ) ),
			),
		);
	}

	/**
	 * Latest published release, cached 6 hours (1 hour after a failure).
	 *
	 * @return array{version:string,package:string,url:string,notes:string,published:string}|null
	 */
	public static function release( bool $refresh = false ): ?array {
		$cached = get_site_transient( self::CACHE );
		if ( ! $refresh && is_array( $cached ) ) {
			return '' !== ( $cached['version'] ?? '' ) ? $cached : null;
		}

		$repo    = (string) Settings::get( 'github_repo' );
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => SLUG . '/' . VERSION,
		);
		if ( defined( 'NWR_PF_GITHUB_TOKEN' ) && '' !== (string) NWR_PF_GITHUB_TOKEN ) {
			$headers['Authorization'] = 'Bearer ' . NWR_PF_GITHUB_TOKEN;
		}
		$response = wp_remote_get(
			'https://api.github.com/repos/' . $repo . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => $headers,
			)
		);

		$release = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( is_array( $json ) && ! empty( $json['tag_name'] ) && empty( $json['draft'] ) && empty( $json['prerelease'] ) ) {
				foreach ( (array) ( $json['assets'] ?? array() ) as $asset ) {
					$name = (string) ( $asset['name'] ?? '' );
					if ( preg_match( '/^' . preg_quote( SLUG, '/' ) . '(-[\w.]+)?\.zip$/', $name ) && ! empty( $asset['browser_download_url'] ) ) {
						$release = array(
							'version'   => ltrim( (string) $json['tag_name'], 'vV' ),
							'package'   => (string) $asset['browser_download_url'],
							'url'       => (string) ( $json['html_url'] ?? '' ),
							'notes'     => (string) ( $json['body'] ?? '' ),
							'published' => (string) ( $json['published_at'] ?? '' ),
						);
						break;
					}
				}
			}
		}

		set_site_transient( self::CACHE, $release ?? array( 'version' => '' ), $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $release;
	}

	/** GitHub zips may unpack into "repo-tag/"; WordPress needs "nwr-product-feeds/". */
	public function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( ! is_array( $hook_extra ) || plugin_basename( PLUGIN_FILE ) !== ( $hook_extra['plugin'] ?? '' ) || ! is_string( $source ) ) {
			return $source;
		}
		$wanted = trailingslashit( (string) $remote_source ) . SLUG . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
			return $source;
		}
		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( $source, $wanted, true ) ) {
			return $wanted;
		}
		return $source;
	}

	public function forget( $upgrader, $options ): void {
		if ( is_array( $options ) && 'plugin' === ( $options['type'] ?? '' ) && in_array( plugin_basename( PLUGIN_FILE ), (array) ( $options['plugins'] ?? array() ), true ) ) {
			delete_site_transient( self::CACHE );
		}
	}
}
