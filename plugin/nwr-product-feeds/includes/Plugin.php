<?php
namespace Nowera\ProductFeeds;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	const VERSION_OPTION = 'nwr_pf_version';

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'missing_woocommerce' ) );
			return;
		}

		// Job handlers and change tracking run in every context: cron, checkout, REST, admin.
		( new Jobs\Scheduler() )->register();
		( new Jobs\Change_Tracker() )->register();
		( new Updater() )->register();
		( new Legacy_Redirect() )->register();
		( new Integrations\Multistore() )->register();

		add_action( 'init', array( $this, 'init' ), 20 );

		if ( is_admin() ) {
			( new Admin\Admin() )->register();
			( new Admin\Product_Panel() )->register();
			( new Admin\Taxonomy() )->register();
		}
	}

	public function init(): void {
		load_plugin_textdomain( 'nwr-product-feeds', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
		$this->maybe_upgrade();
	}

	/**
	 * Setup after activation or a plugin update. Runs on `init`, where Action
	 * Scheduler's data store is ready (it is not during every activation path).
	 */
	private function maybe_upgrade(): void {
		$previous = get_option( self::VERSION_OPTION );
		if ( VERSION === $previous ) {
			return;
		}
		Migration::run();
		Storage::ensure();
		Jobs\Scheduler::ensure();
		update_option( self::VERSION_OPTION, VERSION, true );
		// An update may change how fields are written: refresh the files soon, not at the next scheduled run.
		if ( $previous ) {
			Jobs\Scheduler::mark_dirty();
		}
		Log::info( '', sprintf( 'Plugin pripravený (verzia %s).', VERSION ) );
	}

	public static function activate(): void {
		// Forces maybe_upgrade() on the next request.
		delete_option( self::VERSION_OPTION );
	}

	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), Jobs\Scheduler::GROUP );
		}
		delete_option( Jobs\Scheduler::DIRTY_OPTION );
		delete_option( Jobs\Scheduler::SIGNATURE_OPTION );
		delete_option( self::VERSION_OPTION );
	}

	public function missing_woocommerce(): void {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Produktové feedy potrebujú aktívny WooCommerce.', 'nwr-product-feeds' ) . '</p></div>';
		}
	}
}
