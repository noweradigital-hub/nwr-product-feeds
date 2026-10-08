<?php
// GitHub Releases updater against a mocked API.
require __DIR__ . '/_bootstrap.php';
use Nowera\ProductFeeds\Updater;

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( ! str_contains( $url, 'api.github.com/repos/' ) ) {
			return $pre;
		}
		$body = array(
			'tag_name'     => 'v9.9.9',
			'html_url'     => 'https://github.com/noweradigital-hub/nwr-product-feeds/releases/tag/v9.9.9',
			'body'         => "Opravy\n- test",
			'published_at' => '2026-09-17T10:00:00Z',
			'draft'        => false,
			'prerelease'   => false,
			'assets'       => array(
				array( 'name' => 'nieco-ine.zip', 'browser_download_url' => 'https://example.com/zle.zip' ),
				array( 'name' => 'nwr-product-feeds-9.9.9.zip', 'browser_download_url' => 'https://github.com/noweradigital-hub/nwr-product-feeds/releases/download/v9.9.9/nwr-product-feeds-9.9.9.zip' ),
			),
		);
		return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
	},
	10,
	3
);

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$file    = 'nwr-product-feeds/nwr-product-feeds.php';
$data    = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
$release = Updater::release( true );
$update  = apply_filters( 'update_plugins_github.com', false, $data, $file, array() );
$info    = apply_filters( 'plugins_api', false, 'plugin_information', (object) array( 'slug' => 'nwr-product-feeds' ) );
delete_site_transient( Updater::CACHE );
nwr_out(
	array(
		'update_uri' => $data['UpdateURI'],
		'release'    => $release,
		'update'     => $update,
		'info_name'  => is_object( $info ) ? $info->name : null,
		'info_link'  => is_object( $info ) ? $info->download_link : null,
	)
);
