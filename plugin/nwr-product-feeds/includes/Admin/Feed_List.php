<?php
namespace Nowera\ProductFeeds\Admin;

use Nowera\ProductFeeds\Channels\Registry;
use Nowera\ProductFeeds\Feeds;
use Nowera\ProductFeeds\Jobs\Scheduler;
use Nowera\ProductFeeds\State;
use Nowera\ProductFeeds\Storage;

defined( 'ABSPATH' ) || exit;

final class Feed_List {

	public function render(): void {
		$registry = Registry::instance();
		$actions  = '';
		foreach ( $registry->all() as $key => $channel ) {
			/* translators: %s: channel name */
			$actions .= ' <a class="page-title-action" href="' . esc_url( Admin::url( array( 'view' => 'new', 'channel' => $key ) ) ) . '">' . esc_html( sprintf( __( 'Pridať: %s', 'nwr-product-feeds' ), $channel->label() ) ) . '</a>';
		}
		Admin::header( __( 'Produktové feedy', 'nwr-product-feeds' ), 'list', $actions );

		$feeds = Feeds::all();
		if ( ! $feeds ) {
			echo '<div class="nwr-pf-card nwr-pf-empty"><h2>' . esc_html__( 'Zatiaľ tu nie je žiadny feed', 'nwr-product-feeds' ) . '</h2>';
			echo '<p>' . esc_html__( 'Feed sa generuje na pozadí do statického súboru. Jeho URL vložíš do Google Merchant Center alebo do Commerce Managera (Meta) ako plánovaný zdroj údajov.', 'nwr-product-feeds' ) . '</p><p>';
			foreach ( $registry->all() as $key => $channel ) {
				printf(
					'<a class="button button-primary" href="%1$s">%2$s</a> ',
					esc_url( Admin::url( array( 'view' => 'new', 'channel' => $key ) ) ),
					/* translators: %s: channel name */
					esc_html( sprintf( __( 'Nový feed: %s', 'nwr-product-feeds' ), $channel->label() ) )
				);
			}
			echo '</p></div>';
			return;
		}

		$running = false;
		echo '<table class="widefat striped nwr-pf-feeds"><thead><tr>';
		foreach ( array( __( 'Feed', 'nwr-product-feeds' ), __( 'URL pre Google / Meta', 'nwr-product-feeds' ), __( 'Posledný súbor', 'nwr-product-feeds' ), __( 'Stav', 'nwr-product-feeds' ), __( 'Akcie', 'nwr-product-feeds' ) ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $feeds as $id => $feed ) {
			$channel = $registry->get( $feed['channel'] );
			$run     = State::run( $id );
			$report  = State::report( $id );
			$info    = Storage::info( $feed );
			$running = $running || 'running' === $run['status'];

			echo '<tr data-feed="' . esc_attr( $id ) . '" data-status="' . esc_attr( $run['status'] ) . '">';

			// Feed.
			echo '<td class="nwr-pf-col-name"><strong><a href="' . esc_url( Admin::url( array( 'view' => 'edit', 'feed' => $id ) ) ) . '">' . esc_html( $feed['name'] ) . '</a></strong><br>';
			printf( '<span class="nwr-pf-pill nwr-pf-pill--%1$s">%2$s</span> ', esc_attr( $feed['channel'] ), esc_html( $channel ? $channel->label() : $feed['channel'] ) );
			echo '<span class="nwr-pf-muted">' . esc_html( strtoupper( $feed['format'] ) . ' · ' . Texts::interval( (int) $feed['interval'] ) ) . '</span>';
			if ( empty( $feed['enabled'] ) ) {
				echo ' <span class="nwr-pf-pill nwr-pf-pill--off">' . esc_html__( 'vypnutý', 'nwr-product-feeds' ) . '</span>';
			}
			echo '</td>';

			// URL.
			$url = Storage::url( $feed );
			echo '<td class="nwr-pf-col-url"><div class="nwr-pf-copy"><input type="text" readonly value="' . esc_attr( $url ) . '" onfocus="this.select()"><button type="button" class="button button-small" data-copy="' . esc_attr( $url ) . '">' . esc_html__( 'Kopírovať', 'nwr-product-feeds' ) . '</button></div>';
			if ( ! $info ) {
				echo '<span class="nwr-pf-muted">' . esc_html__( 'Súbor ešte nie je vygenerovaný.', 'nwr-product-feeds' ) . '</span>';
			}
			echo '</td>';

			// Last file.
			echo '<td>';
			if ( $info && ! empty( $report['finished_at'] ) ) {
				echo esc_html( self::ago( (int) $report['finished_at'] ) ) . '<br><span class="nwr-pf-muted">';
				/* translators: 1: items, 2: file size */
				echo esc_html( sprintf( __( '%1$d položiek · %2$s', 'nwr-product-feeds' ), (int) $report['items'], size_format( $info['size'] ) ) );
				$skipped = (int) ( $report['counts']['skipped'] ?? 0 );
				if ( $skipped ) {
					/* translators: %d: count */
					echo '<br>' . esc_html( sprintf( __( 'vynechaných: %d', 'nwr-product-feeds' ), $skipped ) );
				}
				echo '</span>';
			} else {
				echo '—';
			}
			echo '</td>';

			// State.
			echo '<td>' . self::status( $id, $feed, $run, $report ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.

			// Actions.
			echo '<td class="nwr-pf-actions">';
			printf( '<a class="button button-small button-primary" href="%s">%s</a> ', esc_url( Admin::action_url( 'run_feed', array( 'feed' => $id, 'from' => 'list' ) ) ), esc_html__( 'Pregenerovať teraz', 'nwr-product-feeds' ) );
			printf( '<a class="button button-small" href="%s">%s</a> ', esc_url( Admin::url( array( 'view' => 'report', 'feed' => $id ) ) ), esc_html__( 'Náhľad a report', 'nwr-product-feeds' ) );
			printf( '<a class="button button-small" href="%s">%s</a>', esc_url( Admin::url( array( 'view' => 'edit', 'feed' => $id ) ) ), esc_html__( 'Upraviť', 'nwr-product-feeds' ) );
			echo '<div class="nwr-pf-row-links">';
			printf( '<a href="%s">%s</a> · ', esc_url( Admin::action_url( 'duplicate_feed', array( 'feed' => $id ) ) ), esc_html__( 'Duplikovať', 'nwr-product-feeds' ) );
			printf( '<a class="nwr-pf-danger" data-confirm="%1$s" href="%2$s">%3$s</a>', esc_attr__( 'Vymazať feed aj jeho súbor? URL prestane fungovať.', 'nwr-product-feeds' ), esc_url( Admin::action_url( 'delete_feed', array( 'feed' => $id ) ) ), esc_html__( 'Vymazať', 'nwr-product-feeds' ) );
			echo '</div></td></tr>';
		}
		echo '</tbody></table>';

		echo '<p class="nwr-pf-muted">' . esc_html__( 'Súbory sú statické — webserver ich posiela bez PHP a bez page cache. Po zmene produktu, ceny alebo skladu sa feed pregeneruje o pár minút, inak podľa plánu.', 'nwr-product-feeds' ) . '</p>';

		if ( $running ) {
			echo '<p class="nwr-pf-live" data-nwr-pf-poll="1">' . esc_html__( 'Generuje sa… stránka sa obnoví po dokončení.', 'nwr-product-feeds' ) . '</p>';
		}
	}

	public static function status( string $id, array $feed, array $run, array $report ): string {
		$html = '';
		switch ( $run['status'] ) {
			case 'running':
				/* translators: 1: items so far, 2: batches */
				$html .= '<span class="nwr-pf-badge nwr-pf-badge--run">' . esc_html__( 'Generuje sa', 'nwr-product-feeds' ) . '</span><br><span class="nwr-pf-muted">' . esc_html( sprintf( __( '%1$d položiek, dávka %2$d', 'nwr-product-feeds' ), (int) $run['counts']['items'], (int) $run['batches'] + 1 ) ) . '</span>';
				$html .= '<br><a class="nwr-pf-danger" href="' . esc_url( Admin::action_url( 'cancel_run', array( 'feed' => $id ) ) ) . '">' . esc_html__( 'Zrušiť', 'nwr-product-feeds' ) . '</a>';
				break;
			case 'failed':
				$html .= '<span class="nwr-pf-badge nwr-pf-badge--error">' . esc_html__( 'Posledný beh zlyhal', 'nwr-product-feeds' ) . '</span><br><span class="nwr-pf-muted">' . esc_html( mb_substr( (string) $run['error'], 0, 160 ) ) . '</span>';
				break;
			case 'guarded':
				$pending = (array) $run['pending'];
				/* translators: 1: new count, 2: previous count */
				$html .= '<span class="nwr-pf-badge nwr-pf-badge--warn">' . esc_html__( 'Čaká na potvrdenie', 'nwr-product-feeds' ) . '</span><br><span class="nwr-pf-muted">' . esc_html( sprintf( __( 'Nový súbor: %1$d položiek (predtým %2$d).', 'nwr-product-feeds' ), (int) ( $pending['items'] ?? 0 ), (int) ( $pending['previous'] ?? 0 ) ) ) . '</span><br>';
				$html .= '<a class="button button-small" href="' . esc_url( Admin::action_url( 'publish_pending', array( 'feed' => $id ) ) ) . '">' . esc_html__( 'Zverejniť', 'nwr-product-feeds' ) . '</a> ';
				$html .= '<a class="button button-small" href="' . esc_url( Admin::action_url( 'discard_pending', array( 'feed' => $id ) ) ) . '">' . esc_html__( 'Zahodiť', 'nwr-product-feeds' ) . '</a>';
				break;
			default:
				$html .= empty( $feed['enabled'] )
					? '<span class="nwr-pf-badge">' . esc_html__( 'Vypnutý', 'nwr-product-feeds' ) . '</span>'
					: '<span class="nwr-pf-badge nwr-pf-badge--ok">' . esc_html__( 'Aktívny', 'nwr-product-feeds' ) . '</span>';
				if ( ! empty( $report['last_error'] ) && (int) $report['last_error_at'] > (int) ( $report['finished_at'] ?? 0 ) ) {
					$html .= '<br><span class="nwr-pf-error">' . esc_html( mb_substr( (string) $report['last_error'], 0, 160 ) ) . '</span>';
				}
		}

		$next = Scheduler::next_run( $id );
		if ( $next && ! empty( $feed['enabled'] ) ) {
			/* translators: %s: time */
			$html .= '<br><span class="nwr-pf-muted">' . esc_html( sprintf( __( 'ďalšie: %s', 'nwr-product-feeds' ), wp_date( 'j. n. H:i', $next ) ) ) . '</span>';
		}
		return $html;
	}

	public static function ago( int $timestamp ): string {
		/* translators: %s: human time difference */
		return sprintf( __( 'pred %s', 'nwr-product-feeds' ), human_time_diff( $timestamp ) ) . ' (' . wp_date( 'j. n. Y H:i', $timestamp ) . ')';
	}
}
