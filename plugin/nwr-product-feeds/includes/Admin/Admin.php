<?php
namespace Nowera\ProductFeeds\Admin;

use InvalidArgumentException;
use Nowera\ProductFeeds\Feeds;
use Nowera\ProductFeeds\Jobs\Generator;
use Nowera\ProductFeeds\Jobs\Scheduler;
use Nowera\ProductFeeds\Log;
use Nowera\ProductFeeds\Settings;
use Nowera\ProductFeeds\State;
use Nowera\ProductFeeds\Storage;
use Nowera\ProductFeeds\Transfer;
use Nowera\ProductFeeds\Updater;
use const Nowera\ProductFeeds\PLUGIN_FILE;
use const Nowera\ProductFeeds\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce → Produktové feedy: menu, routing, form handlers, notices.
 */
final class Admin {

	const CAP     = 'manage_woocommerce';
	const PAGE    = 'nwr-product-feeds';
	const ACTIONS = array( 'save_feed', 'delete_feed', 'duplicate_feed', 'run_feed', 'cancel_run', 'publish_pending', 'discard_pending', 'rotate_token', 'save_settings', 'export', 'import', 'clear_log', 'check_updates' );

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_nwr_pf_' . $action, array( $this, 'handle' ) );
		}
		add_action( 'wp_ajax_nwr_pf_status', array( $this, 'ajax_status' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'plugin_links' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Produktové feedy', 'nwr-product-feeds' ),
			__( 'Produktové feedy', 'nwr-product-feeds' ),
			self::CAP,
			self::PAGE,
			array( $this, 'route' )
		);
	}

	public function plugin_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Feedy', 'nwr-product-feeds' ) . '</a>' );
		return $links;
	}

	public function assets( string $hook ): void {
		$screen  = get_current_screen();
		$ours    = str_contains( $hook, self::PAGE );
		$product = $screen && 'product' === $screen->id;
		if ( ! $ours && ! $product ) {
			return;
		}
		$base = plugin_dir_url( PLUGIN_FILE ) . 'assets/';
		wp_enqueue_style( 'nwr-pf-admin', $base . 'admin.css', array(), VERSION );
		wp_enqueue_script( 'nwr-pf-admin', $base . 'admin.js', array(), VERSION, true );
		wp_localize_script(
			'nwr-pf-admin',
			'nwrPf',
			array(
				'ajax'   => admin_url( 'admin-ajax.php' ),
				'nonce'  => wp_create_nonce( 'nwr_pf_ajax' ),
				'i18n'   => array(
					'search'  => __( 'Hľadať kategóriu Google (názov alebo ID)…', 'nwr-product-feeds' ),
					'loading' => __( 'Načítavam taxonómiu Google…', 'nwr-product-feeds' ),
					'none'    => __( 'Nič sa nenašlo.', 'nwr-product-feeds' ),
					'error'   => __( 'Taxonómiu sa nepodarilo načítať.', 'nwr-product-feeds' ),
					'copied'  => __( 'Skopírované', 'nwr-product-feeds' ),
					'confirm' => __( 'Naozaj?', 'nwr-product-feeds' ),
				),
			)
		);
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	public static function action_url( string $action, array $args = array() ): string {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'nwr_pf_' . $action ), $args ), admin_url( 'admin-post.php' ) ), 'nwr_pf_' . $action );
	}

	public function route(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Na túto stránku nemáš oprávnenie.', 'nwr-product-feeds' ), 403 );
		}
		$view    = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification
		$feed_id = isset( $_GET['feed'] ) ? sanitize_key( wp_unslash( $_GET['feed'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		echo '<div class="wrap nwr-pf">';
		switch ( $view ) {
			case 'new':
				$channel = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : 'google'; // phpcs:ignore WordPress.Security.NonceVerification
				( new Feed_Editor() )->render( null, $channel );
				break;
			case 'edit':
				$feed = Feeds::get( $feed_id );
				$feed ? ( new Feed_Editor() )->render( $feed ) : self::missing();
				break;
			case 'report':
				$feed = Feeds::get( $feed_id );
				$feed ? ( new Feed_Report() )->render( $feed ) : self::missing();
				break;
			case 'settings':
				( new Settings_Page() )->render();
				break;
			default:
				( new Feed_List() )->render();
		}
		echo '</div>';
	}

	private static function missing(): void {
		echo '<h1>' . esc_html__( 'Feed neexistuje', 'nwr-product-feeds' ) . '</h1><p><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'Späť na feedy', 'nwr-product-feeds' ) . '</a></p>';
	}

	/** Shared header with tabs and notices. */
	public static function header( string $title, string $active = 'list', string $actions = '' ): void {
		echo '<h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>' . $actions . '<hr class="wp-header-end">'; // phpcs:ignore WordPress.Security.EscapeOutput -- $actions is built from escaped parts.
		echo '<nav class="nav-tab-wrapper nwr-pf-tabs">';
		foreach ( array(
			'list'     => __( 'Feedy', 'nwr-product-feeds' ),
			'settings' => __( 'Nastavenia, export a log', 'nwr-product-feeds' ),
		) as $view => $label ) {
			printf( '<a class="nav-tab%s" href="%s">%s</a>', $view === $active ? ' nav-tab-active' : '', esc_url( self::url( 'list' === $view ? array() : array( 'view' => $view ) ) ), esc_html( $label ) );
		}
		echo '</nav>';
		self::notices();
	}

	private static function notices(): void {
		$messages = array(
			'saved'           => array( 'success', __( 'Feed uložený.', 'nwr-product-feeds' ) ),
			'saved_run'       => array( 'success', __( 'Feed uložený a generovanie naplánované. Beží na pozadí — stav sa obnoví sám.', 'nwr-product-feeds' ) ),
			'deleted'         => array( 'success', __( 'Feed vymazaný aj so súborom.', 'nwr-product-feeds' ) ),
			'duplicated'      => array( 'success', __( 'Kópia vytvorená (vypnutá). Uprav ju a zapni.', 'nwr-product-feeds' ) ),
			'started'         => array( 'success', __( 'Generovanie naplánované. Beží na pozadí — stav sa obnoví sám.', 'nwr-product-feeds' ) ),
			'queued'          => array( 'info', __( 'Feed sa práve generuje. Po dokončení sa spustí znova.', 'nwr-product-feeds' ) ),
			'run_error'       => array( 'error', __( 'Generovanie sa nepodarilo spustiť — pozri log.', 'nwr-product-feeds' ) ),
			'cancelled'       => array( 'success', __( 'Generovanie zrušené.', 'nwr-product-feeds' ) ),
			'published'       => array( 'success', __( 'Podržaný súbor zverejnený.', 'nwr-product-feeds' ) ),
			'publish_failed'  => array( 'error', __( 'Podržaný súbor sa nepodarilo zverejniť — pozri log.', 'nwr-product-feeds' ) ),
			'discarded'       => array( 'success', __( 'Podržaný súbor zahodený.', 'nwr-product-feeds' ) ),
			'token'           => array( 'warning', __( 'URL feedu sa zmenila. Nová URL platí po najbližšom generovaní — aktualizuj ju v Merchant Center / Commerce Manageri.', 'nwr-product-feeds' ) ),
			'settings'        => array( 'success', __( 'Nastavenia uložené.', 'nwr-product-feeds' ) ),
			'imported'        => array( 'success', __( 'Nastavenia importované.', 'nwr-product-feeds' ) ),
			'import_error'    => array( 'error', __( 'Import zlyhal: súbor nie je platný export tohto pluginu.', 'nwr-product-feeds' ) ),
			'log_cleared'     => array( 'success', __( 'Log vymazaný.', 'nwr-product-feeds' ) ),
			'updates_checked' => array( 'success', __( 'Aktualizácie skontrolované.', 'nwr-product-feeds' ) ),
		);
		$code = isset( $_GET['nwr_pf_notice'] ) ? sanitize_key( wp_unslash( $_GET['nwr_pf_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $messages[ $code ] ) ) {
			[ $type, $text ] = $messages[ $code ];
			$extra           = isset( $_GET['nwr_pf_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['nwr_pf_detail'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s%3$s</p></div>', esc_attr( $type ), esc_html( $text ), '' !== $extra ? ' ' . esc_html( $extra ) : '' );
		}

		$health = Scheduler::health();
		if ( ! Scheduler::available() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Action Scheduler nie je dostupný, feedy sa nemôžu generovať.', 'nwr-product-feeds' ) . '</p></div>';
		} elseif ( $health['wp_cron_disabled'] ) {
			$late = $health['late'] ? ' ' . sprintf( /* translators: %d: count */ __( 'Momentálne mešká %d úloh — cron na serveri asi nebeží.', 'nwr-product-feeds' ), $health['late'] ) : '';
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'WP-Cron je vypnutý (DISABLE_WP_CRON).', 'nwr-product-feeds' ) . '</strong> ' . esc_html__( 'Feedy generuje Action Scheduler, ktorý potrebuje cron. Na hostingu musí bežať úloha, ktorá volá wp-cron.php (napr. každých 5 minút).', 'nwr-product-feeds' ) . esc_html( $late ) . '</p></div>';
		} elseif ( $health['late'] ) {
			/* translators: %d: count */
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'Úlohy feedov meškajú (%d). Action Scheduler sa spúšťa pri návštevách webu cez WP-Cron — ak je web bez návštev, nastav cron na serveri.', 'nwr-product-feeds' ), $health['late'] ) ) . '</p></div>';
		}

		$ready = Storage::ensure();
		if ( is_wp_error( $ready ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $ready->get_error_message() ) . '</p></div>';
		}
	}

	private static function back( string $notice, array $args = array(), string $detail = '' ): void {
		$args['nwr_pf_notice'] = $notice;
		if ( '' !== $detail ) {
			$args['nwr_pf_detail'] = rawurlencode( mb_substr( $detail, 0, 300 ) );
		}
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	public function handle(): void {
		$action = substr( current_action(), strlen( 'admin_post_nwr_pf_' ) );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Na túto akciu nemáš oprávnenie.', 'nwr-product-feeds' ), 403 );
		}
		check_admin_referer( 'nwr_pf_' . $action );
		$feed_id = isset( $_REQUEST['feed'] ) ? sanitize_key( wp_unslash( $_REQUEST['feed'] ) ) : '';
		$report  = array(
			'view' => 'report',
			'feed' => $feed_id,
		);

		switch ( $action ) {
			case 'save_feed':
				$input = isset( $_POST['feed_data'] ) && is_array( $_POST['feed_data'] ) ? wp_unslash( $_POST['feed_data'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Feeds::sanitize() cleans every field.
				$exists = '' !== $feed_id && Feeds::get( $feed_id );
				$feed   = Feeds::save( $input, $exists ? $feed_id : '' );
				Log::info( $feed['id'], sprintf( 'Nastavenia feedu „%s“ uložené.', $feed['name'] ) );
				if ( ! empty( $_POST['generate'] ) || ! $exists ) {
					Generator::request( $feed['id'], 'manual' );
					self::back( 'saved_run', array( 'view' => 'edit', 'feed' => $feed['id'] ) );
				}
				self::back( 'saved', array( 'view' => 'edit', 'feed' => $feed['id'] ) );
				break;

			case 'delete_feed':
				Feeds::delete( $feed_id );
				self::back( 'deleted' );
				break;

			case 'duplicate_feed':
				$copy = Feeds::duplicate( $feed_id );
				self::back( 'duplicated', $copy ? array( 'view' => 'edit', 'feed' => $copy['id'] ) : array() );
				break;

			case 'run_feed':
				$result = Generator::request( $feed_id, 'manual' );
				self::back( 'started' === $result ? 'started' : ( 'queued' === $result ? 'queued' : 'run_error' ), isset( $_GET['from'] ) && 'list' === $_GET['from'] ? array() : $report );
				break;

			case 'cancel_run':
				Generator::cancel( $feed_id );
				self::back( 'cancelled', $report );
				break;

			case 'publish_pending':
				self::back( Generator::publish_pending( $feed_id ) ? 'published' : 'publish_failed', $report );
				break;

			case 'discard_pending':
				Generator::discard_pending( $feed_id );
				self::back( 'discarded', $report );
				break;

			case 'rotate_token':
				$feed = Feeds::rotate_token( $feed_id );
				if ( $feed ) {
					Log::warning( $feed_id, 'URL feedu zmenená (nový token).' );
					Generator::request( $feed_id, 'manual' );
				}
				self::back( 'token', array( 'view' => 'edit', 'feed' => $feed_id ) );
				break;

			case 'save_settings':
				$input = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Settings::sanitize() cleans every field.
				$old   = Settings::get( 'id_template' );
				$new   = Settings::save( $input );
				Log::info( '', 'Nastavenia pluginu uložené.' );
				if ( $old !== $new['id_template'] ) {
					Log::warning( '', sprintf( 'Šablóna ID zmenená z %1$s na %2$s — všetky feedy sa pregenerujú.', $old, $new['id_template'] ) );
					foreach ( Feeds::enabled() as $feed ) {
						Generator::request( $feed['id'], 'manual' );
					}
				}
				self::back( 'settings', array( 'view' => 'settings' ) );
				break;

			case 'export':
				$json = wp_json_encode( Transfer::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				nocache_headers();
				header( 'Content-Type: application/json; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="nwr-product-feeds-' . sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . gmdate( 'Ymd' ) . '.json"' );
				echo $json; // phpcs:ignore WordPress.Security.EscapeOutput
				exit;

			case 'import':
				$raw = '';
				if ( ! empty( $_FILES['import_file']['tmp_name'] ) && is_uploaded_file( $_FILES['import_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					$raw = (string) file_get_contents( $_FILES['import_file']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				} elseif ( ! empty( $_POST['import_json'] ) ) {
					$raw = (string) wp_unslash( $_POST['import_json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- decoded as JSON and sanitized field by field.
				}
				$data = json_decode( $raw, true );
				try {
					$result = Transfer::import( is_array( $data ) ? $data : array(), ! empty( $_POST['replace'] ), ! empty( $_POST['with_settings'] ) );
				} catch ( InvalidArgumentException $e ) {
					self::back( 'import_error', array( 'view' => 'settings' ) );
				}
				/* translators: %d: number of feeds */
				$detail = sprintf( __( 'Feedov: %d.', 'nwr-product-feeds' ), $result['feeds'] );
				if ( $result['unmatched'] ) {
					$detail .= ' ' . __( 'Nenájdené kategórie/štítky:', 'nwr-product-feeds' ) . ' ' . implode( ', ', $result['unmatched'] );
				}
				self::back( 'imported', array(), $detail );
				break;

			case 'clear_log':
				Log::clear();
				self::back( 'log_cleared', array( 'view' => 'settings' ) );
				break;

			case 'check_updates':
				Updater::release( true );
				delete_site_transient( 'update_plugins' );
				self::back( 'updates_checked', array( 'view' => 'settings' ) );
				break;
		}

		self::back( '' );
	}

	/** Polled by the list and report screens while a feed is generating. */
	public function ajax_status(): void {
		check_ajax_referer( 'nwr_pf_ajax' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		$out = array();
		foreach ( Feeds::all() as $id => $feed ) {
			$run        = State::run( $id, true );
			$out[ $id ] = array(
				'status' => $run['status'],
				'run'    => $run['run_id'],
				'items'  => (int) $run['counts']['items'],
			);
		}
		wp_send_json_success( $out );
	}
}
