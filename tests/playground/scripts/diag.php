<?php
require __DIR__ . '/_bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$dir = function_exists( 'wp_get_upload_dir' ) ? trailingslashit( wp_get_upload_dir()['basedir'] ) . 'nwr-feeds' : '';
$list = static function ( string $path ): array {
	return is_dir( $path ) ? array_values( array_diff( scandir( $path ), array( '.', '..' ) ) ) : array();
};
nwr_out(
	array(
		'active'      => get_option( 'active_plugins' ),
		'woo_class'   => class_exists( 'WooCommerce' ),
		'wc_version'  => defined( 'WC_VERSION' ) ? WC_VERSION : null,
		'pf_loaded'   => function_exists( 'nwr_pf_item_id' ) && class_exists( '\Nowera\ProductFeeds\Plugin' ),
		'pf_version'  => get_option( 'nwr_pf_version' ),
		'composite'   => class_exists( 'WC_Product_Composite' ),
		'brand_tax'   => taxonomy_exists( 'product_brand' ),
		'cron_off'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		'php'         => PHP_VERSION,
		'xmlreader'   => class_exists( 'XMLReader' ),
		'dir'         => $dir,
		'dir_files'   => $list( $dir ),
		'tmp_files'   => $list( $dir . '/.tmp' ),
		'htaccess'    => is_file( $dir . '/.htaccess' ) ? file_get_contents( $dir . '/.htaccess' ) : null,
		'tmp_htaccess' => is_file( $dir . '/.tmp/.htaccess' ) ? file_get_contents( $dir . '/.tmp/.htaccess' ) : null,
		'index'       => is_file( $dir . '/index.php' ),
	)
);
