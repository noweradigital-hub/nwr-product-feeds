<?php
/**
 * Plugin Name:          Produktové feedy — Google & Meta
 * Plugin URI:           https://github.com/noweradigital-hub/nwr-product-feeds
 * Description:          Produktové feedy z WooCommerce pre Google Merchant Center a Meta katalóg. Generujú sa na pozadí do statického súboru, s filtrami, mapovaním kategórií a validačným reportom.
 * Version:              1.0.1
 * Author:               Nowera
 * Author URI:           https://nowera.sk
 * License:              GPL-2.0-or-later
 * Requires at least:    6.2
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * WC tested up to:      11.1
 * Text Domain:          nwr-product-feeds
 * Domain Path:          /languages
 * Update URI:           https://github.com/noweradigital-hub/nwr-product-feeds
 */

namespace Nowera\ProductFeeds;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION     = '1.0.1';
const PLUGIN_FILE = __FILE__;
const SLUG        = 'nwr-product-feeds';

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// Public API (global namespace): nwr_pf_item_id() is shared with the nowera-capi tracking plugin.
require __DIR__ . '/includes/functions.php';

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PLUGIN_FILE, true );
			// The "Feedy" tab is a classic product data panel; the block product editor does not render those.
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'product_block_editor', PLUGIN_FILE, false );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );
