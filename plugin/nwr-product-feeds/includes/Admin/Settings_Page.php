<?php
namespace Nowera\ProductFeeds\Admin;

use Nowera\ProductFeeds\Feeds;
use Nowera\ProductFeeds\Jobs\Scheduler;
use Nowera\ProductFeeds\Log;
use Nowera\ProductFeeds\Settings;
use Nowera\ProductFeeds\Storage;
use Nowera\ProductFeeds\Updater;
use const Nowera\ProductFeeds\VERSION;

defined( 'ABSPATH' ) || exit;

final class Settings_Page {

	public function render(): void {
		Admin::header( __( 'Produktové feedy — nastavenia', 'nwr-product-feeds' ), 'settings' );
		$settings = Settings::all();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="nwr-pf-form">';
		echo '<input type="hidden" name="action" value="nwr_pf_save_settings">';
		wp_nonce_field( 'nwr_pf_save_settings' );

		echo '<div class="nwr-pf-card"><h2>' . esc_html__( 'ID položiek', 'nwr-product-feeds' ) . '</h2>';
		echo '<p class="nwr-pf-intro">' . esc_html__( 'Rovnaké ID musí posielať meranie (Meta pixel / Conversions API, GA4). Plugin nowera-capi ho berie z funkcie nwr_pf_item_id(), takže sa nerozídu. Jednoduchý produkt má svoje ID, variácia svoje, variabilný produkt je skupina (item_group_id).', 'nwr-product-feeds' ) . '</p>';
		self::field(
			__( 'Šablóna ID', 'nwr-product-feeds' ),
			'<input type="text" class="regular-text code" name="settings[id_template]" value="' . esc_attr( $settings['id_template'] ) . '">',
			__( 'Predvolene {id}. Premenné: {id} (ID produktu alebo variácie), {sku} (vlastné SKU, inak ID), {parent_id}, {parent_sku}. Text okolo sa zachová, napr. wc_{id}. Zmena pregeneruje všetky feedy a Meta/Google uvidia nové produkty — meň len vedome.', 'nwr-product-feeds' )
		);
		echo '</div>';

		echo '<div class="nwr-pf-card"><h2>' . esc_html__( 'Generovanie', 'nwr-product-feeds' ) . '</h2>';
		self::field( __( 'Oneskorenie po zmene produktu', 'nwr-product-feeds' ), '<input type="number" class="small-text" min="1" max="60" name="settings[dirty_delay]" value="' . (int) $settings['dirty_delay'] . '"> ' . esc_html__( 'min', 'nwr-product-feeds' ), __( 'Zmeny počas tejto doby sa spoja do jedného generovania (odporúčané 5–15 min).', 'nwr-product-feeds' ) );
		self::field( __( 'Produktov v jednej dávke', 'nwr-product-feeds' ), '<input type="number" class="small-text" min="5" max="500" name="settings[batch_size]" value="' . (int) $settings['batch_size'] . '">', __( 'Na pomalom serveri sa dávka zmenší sama.', 'nwr-product-feeds' ) );
		self::field( __( 'Záznamov v logu', 'nwr-product-feeds' ), '<input type="number" class="small-text" min="20" max="2000" name="settings[log_size]" value="' . (int) $settings['log_size'] . '">' );
		echo '</div>';

		echo '<div class="nwr-pf-card"><h2>' . esc_html__( 'Aktualizácie', 'nwr-product-feeds' ) . '</h2>';
		self::field(
			'',
			'<input type="hidden" name="settings[updates]" value="0"><label><input type="checkbox" name="settings[updates]" value="1"' . checked( ! empty( $settings['updates'] ), true, false ) . '> ' . esc_html__( 'Hľadať nové verzie v GitHub Releases', 'nwr-product-feeds' ) . '</label>'
		);
		self::field( __( 'Repozitár', 'nwr-product-feeds' ), '<input type="text" class="regular-text code" name="settings[github_repo]" value="' . esc_attr( $settings['github_repo'] ) . '">', __( 'vlastník/repozitár; release musí mať priložený nwr-product-feeds-<verzia>.zip.', 'nwr-product-feeds' ) );
		$release = ! empty( $settings['updates'] ) ? get_site_transient( Updater::CACHE ) : null;
		$latest  = is_array( $release ) && ! empty( $release['version'] ) ? $release['version'] : __( 'nezistená', 'nwr-product-feeds' );
		/* translators: 1: installed version, 2: latest version */
		echo '<p>' . esc_html( sprintf( __( 'Nainštalovaná verzia %1$s, posledná známa %2$s.', 'nwr-product-feeds' ), VERSION, $latest ) ) . ' <a class="button button-small" href="' . esc_url( Admin::action_url( 'check_updates' ) ) . '">' . esc_html__( 'Skontrolovať teraz', 'nwr-product-feeds' ) . '</a></p>';
		echo '</div>';

		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Uložiť nastavenia', 'nwr-product-feeds' ) . '</button></p></form>';

		$this->transfer();
		$this->environment();

		echo '<div class="nwr-pf-card"><h2>' . esc_html__( 'Log', 'nwr-product-feeds' ) . '</h2>';
		self::log_table( Log::entries(), true );
		echo '<p><a class="button" data-confirm="' . esc_attr__( 'Vymazať celý log?', 'nwr-product-feeds' ) . '" href="' . esc_url( Admin::action_url( 'clear_log' ) ) . '">' . esc_html__( 'Vymazať log', 'nwr-product-feeds' ) . '</a> <span class="nwr-pf-muted">' . esc_html__( 'Chyby sa zapisujú aj do WooCommerce → Stav → Logy (zdroj nwr-product-feeds).', 'nwr-product-feeds' ) . '</span></p>';
		echo '</div>';
	}

	private function transfer(): void {
		echo '<div class="nwr-pf-card"><h2>' . esc_html__( 'Export a import nastavení', 'nwr-product-feeds' ) . '</h2>';
		echo '<p class="nwr-pf-intro">' . esc_html__( 'JSON so všetkými feedmi a nastaveniami na prenos do iného obchodu. Kategórie a štítky sa na cieľovom webe spárujú podľa slugu, URL tokeny sa neprenášajú.', 'nwr-product-feeds' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( Admin::action_url( 'export' ) ) . '">' . esc_html__( 'Stiahnuť export (JSON)', 'nwr-product-feeds' ) . '</a></p>';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="nwr_pf_import">';
		wp_nonce_field( 'nwr_pf_import' );
		echo '<p><input type="file" name="import_file" accept="application/json,.json"></p>';
		echo '<p><textarea name="import_json" rows="4" class="large-text code" placeholder="' . esc_attr__( '…alebo sem vlož obsah JSON', 'nwr-product-feeds' ) . '"></textarea></p>';
		echo '<p><label><input type="checkbox" name="with_settings" value="1" checked> ' . esc_html__( 'Importovať aj nastavenia pluginu (šablóna ID, generovanie)', 'nwr-product-feeds' ) . '</label><br>';
		echo '<label><input type="checkbox" name="replace" value="1"> ' . esc_html__( 'Najprv vymazať existujúce feedy (ich URL prestanú fungovať)', 'nwr-product-feeds' ) . '</label></p>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Importovať', 'nwr-product-feeds' ) . '</button></p></form></div>';
	}

	private function environment(): void {
		$health  = Scheduler::health();
		$ready   = Storage::ensure();
		$server  = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		$rows    = array(
			__( 'WooCommerce', 'nwr-product-feeds' )            => defined( 'WC_VERSION' ) ? WC_VERSION : '?',
			__( 'PHP', 'nwr-product-feeds' )                    => PHP_VERSION . ( class_exists( 'XMLReader' ) ? '' : ' — ' . __( 'chýba XMLReader (kontrola súboru je zjednodušená)', 'nwr-product-feeds' ) ),
			__( 'Mena a krajina', 'nwr-product-feeds' )         => get_woocommerce_currency() . ', ' . WC()->countries->get_base_country() . ', ' . ( wc_prices_include_tax() ? __( 'ceny zadané s DPH', 'nwr-product-feeds' ) : __( 'ceny zadané bez DPH', 'nwr-product-feeds' ) ),
			__( 'Action Scheduler', 'nwr-product-feeds' )       => Scheduler::available() ? __( 'dostupný', 'nwr-product-feeds' ) : __( 'NEDOSTUPNÝ', 'nwr-product-feeds' ),
			__( 'WP-Cron', 'nwr-product-feeds' )                => $health['wp_cron_disabled'] ? __( 'vypnutý (DISABLE_WP_CRON) — musí bežať cron na serveri', 'nwr-product-feeds' ) : __( 'zapnutý', 'nwr-product-feeds' ),
			__( 'Meškajúce úlohy feedov', 'nwr-product-feeds' ) => (string) $health['late'],
			__( 'Priečinok feedov', 'nwr-product-feeds' )       => Storage::base_dir() . ' — ' . ( is_wp_error( $ready ) ? $ready->get_error_message() : __( 'zapisovateľný, chránený index.php a .htaccess', 'nwr-product-feeds' ) ),
			__( 'Webserver', 'nwr-product-feeds' )              => $server . ( false !== stripos( $server, 'nginx' ) ? ' — ' . __( '.htaccess sa neuplatní; výpis priečinka vypína nginx predvolene (autoindex off)', 'nwr-product-feeds' ) : '' ),
			__( 'Feedov', 'nwr-product-feeds' )                 => (string) count( Feeds::all() ),
			__( 'WooCommerce Multistore', 'nwr-product-feeds' ) => class_exists( 'WC_Multistore' ) || defined( 'WOO_MSTORE_VERSION' ) || self::plugin_active( 'woocommerce-multistore/woocommerce-multistore.php' ) ? __( 'aktívny — meta _nwr_pf_* sa medzi obchodmi nekopírujú (poistka v plugine)', 'nwr-product-feeds' ) : __( 'nie', 'nwr-product-feeds' ),
		);
		echo '<div class="nwr-pf-card"><h2>' . esc_html__( 'Prostredie', 'nwr-product-feeds' ) . '</h2><table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function plugin_active( string $plugin ): bool {
		return in_array( $plugin, (array) get_option( 'active_plugins', array() ), true );
	}

	public static function field( string $label, string $control, string $help = '' ): void {
		echo '<div class="nwr-pf-field"><span class="nwr-pf-label">' . esc_html( $label ) . '</span><div>' . $control; // phpcs:ignore WordPress.Security.EscapeOutput -- controls are built escaped.
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</div></div>';
	}

	public static function log_table( array $entries, bool $with_feed ): void {
		if ( ! $entries ) {
			echo '<p class="nwr-pf-muted">' . esc_html__( 'Log je prázdny.', 'nwr-product-feeds' ) . '</p>';
			return;
		}
		$feeds = $with_feed ? Feeds::all() : array();
		echo '<table class="widefat striped nwr-pf-log"><tbody>';
		foreach ( $entries as $entry ) {
			$feed = '';
			if ( $with_feed ) {
				$feed = '' === $entry['feed'] ? '—' : ( $feeds[ $entry['feed'] ]['name'] ?? $entry['feed'] );
			}
			printf(
				'<tr class="nwr-pf-log--%1$s"><td class="nwr-pf-nowrap">%2$s</td><td><span class="nwr-pf-level">%3$s</span></td>%4$s<td>%5$s</td></tr>',
				esc_attr( $entry['level'] ),
				esc_html( wp_date( 'j. n. Y H:i:s', (int) $entry['t'] ) ),
				esc_html( $entry['level'] ),
				$with_feed ? '<td>' . esc_html( $feed ) . '</td>' : '',
				esc_html( $entry['msg'] )
			);
		}
		echo '</tbody></table>';
	}
}
