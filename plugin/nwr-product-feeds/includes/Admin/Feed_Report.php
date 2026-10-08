<?php
namespace Nowera\ProductFeeds\Admin;

use Nowera\ProductFeeds\Catalog\Source;
use Nowera\ProductFeeds\Channels\Registry;
use Nowera\ProductFeeds\Jobs\Generator;
use Nowera\ProductFeeds\Log;
use Nowera\ProductFeeds\State;
use Nowera\ProductFeeds\Storage;
use XMLReader;

defined( 'ABSPATH' ) || exit;

/**
 * Preview, counts, skipped products, validation report and log of one feed.
 * Everything shown comes from the last background run and the published
 * file — opening the page does not generate anything. Only the optional
 * "sample from current settings" builds a few items on demand.
 */
final class Feed_Report {

	public function render( array $feed ): void {
		$id      = $feed['id'];
		$run     = State::run( $id );
		$report  = State::report( $id );
		$info    = Storage::info( $feed );
		$channel = Registry::instance()->get( $feed['channel'] );

		$actions  = ' <a class="page-title-action" href="' . esc_url( Admin::action_url( 'run_feed', array( 'feed' => $id ) ) ) . '">' . esc_html__( 'Pregenerovať teraz', 'nwr-product-feeds' ) . '</a>';
		$actions .= ' <a class="page-title-action" href="' . esc_url( Admin::url( array( 'view' => 'edit', 'feed' => $id ) ) ) . '">' . esc_html__( 'Upraviť nastavenia', 'nwr-product-feeds' ) . '</a>';
		/* translators: %s: feed name */
		Admin::header( sprintf( __( 'Report: %s', 'nwr-product-feeds' ), $feed['name'] ), 'list', $actions );

		// Status and file.
		echo '<div class="nwr-pf-card"><h2>' . esc_html__( 'Stav', 'nwr-product-feeds' ) . '</h2><div class="nwr-pf-status">';
		echo Feed_List::status( $id, $feed, $run, $report ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		echo '</div>';
		$url = Storage::url( $feed );
		echo '<div class="nwr-pf-copy"><input type="text" readonly value="' . esc_attr( $url ) . '" onfocus="this.select()"><button type="button" class="button" data-copy="' . esc_attr( $url ) . '">' . esc_html__( 'Kopírovať URL', 'nwr-product-feeds' ) . '</button>';
		if ( $info ) {
			echo ' <a class="button" target="_blank" rel="noopener" href="' . esc_url( $url ) . '">' . esc_html__( 'Otvoriť súbor', 'nwr-product-feeds' ) . '</a>';
		}
		echo '</div>';

		if ( $info && $report ) {
			$counts = (array) ( $report['counts'] ?? array() );
			echo '<ul class="nwr-pf-stats">';
			$stats = array(
				__( 'Položiek vo feede', 'nwr-product-feeds' )      => (int) ( $report['items'] ?? 0 ),
				__( 'z toho jednoduchých', 'nwr-product-feeds' )     => (int) ( $counts['simple'] ?? 0 ),
				__( 'z toho variácií', 'nwr-product-feeds' )         => (int) ( $counts['variations'] ?? 0 ),
				__( 'vypredaných (ostávajú)', 'nwr-product-feeds' )  => (int) ( $counts['out_of_stock'] ?? 0 ),
				__( 'Vynechaných', 'nwr-product-feeds' )             => (int) ( $counts['skipped'] ?? 0 ),
				__( 'Produktov prejdených', 'nwr-product-feeds' )    => (int) ( $counts['products'] ?? 0 ),
				__( 'z toho variabilných', 'nwr-product-feeds' )     => (int) ( $counts['variable'] ?? 0 ),
			);
			foreach ( $stats as $label => $value ) {
				echo '<li><strong>' . esc_html( number_format_i18n( $value ) ) . '</strong><span>' . esc_html( $label ) . '</span></li>';
			}
			echo '</ul><p class="nwr-pf-muted">';
			printf(
				/* translators: 1: time, 2: trigger, 3: duration, 4: size, 5: batches */
				esc_html__( 'Zverejnené %1$s (%2$s), trvanie %3$d s, veľkosť %4$s, dávok %5$d.', 'nwr-product-feeds' ),
				esc_html( Feed_List::ago( (int) $report['finished_at'] ) ),
				esc_html( Texts::trigger( (string) $report['trigger'] ) ),
				(int) $report['duration'],
				esc_html( size_format( $info['size'] ) ),
				(int) ( $report['batches'] ?? 0 )
			);
			echo '</p>';
			$this->census( (array) ( $report['census'] ?? array() ) );
		} else {
			echo '<p>' . esc_html__( 'Súbor ešte nebol vygenerovaný. Generovanie beží na pozadí cez Action Scheduler — zvyčajne do minúty.', 'nwr-product-feeds' ) . '</p>';
		}
		if ( ! empty( $report['last_error'] ) ) {
			/* translators: 1: time, 2: error */
			echo '<p class="nwr-pf-error">' . esc_html( sprintf( __( 'Posledná chyba (%1$s): %2$s', 'nwr-product-feeds' ), wp_date( 'j. n. Y H:i', (int) $report['last_error_at'] ), $report['last_error'] ) ) . '</p>';
		}
		echo '</div>';

		if ( 'running' === $run['status'] ) {
			echo '<p class="nwr-pf-live" data-nwr-pf-poll="1">' . esc_html__( 'Generuje sa… stránka sa obnoví po dokončení.', 'nwr-product-feeds' ) . '</p>';
		}

		if ( $report ) {
			$this->skipped( (array) ( $report['skipped'] ?? array() ) );
			$this->issues( $feed, (array) ( $report['issues'] ?? array() ) );
		}

		$this->preview( $feed, $info );
		$this->log( $id );
	}

	/** SQL counts next to the feed counts: they must add up. */
	private function census( array $census ): void {
		if ( ! $census ) {
			return;
		}
		$types = array();
		foreach ( (array) ( $census['types'] ?? array() ) as $type => $count ) {
			$types[] = $type . ': ' . (int) $count;
		}
		echo '<p class="nwr-pf-muted">';
		printf(
			/* translators: 1: products, 2: types, 3: variations */
			esc_html__( 'Podľa databázy: %1$d publikovaných produktov (%2$s) a %3$d publikovaných variácií. Položky + vynechané = jednoduché produkty + variácie.', 'nwr-product-feeds' ),
			(int) ( $census['products'] ?? 0 ),
			esc_html( implode( ', ', $types ) ),
			(int) ( $census['variations'] ?? 0 )
		);
		echo '</p>';
	}

	private function skipped( array $skipped ): void {
		echo '<div class="nwr-pf-card"><h2>' . esc_html__( 'Vynechané produkty', 'nwr-product-feeds' ) . '</h2>';
		if ( ! $skipped ) {
			echo '<p>' . esc_html__( 'Nič nebolo vynechané.', 'nwr-product-feeds' ) . '</p></div>';
			return;
		}
		uasort( $skipped, static fn( $a, $b ): int => $b['count'] <=> $a['count'] );
		foreach ( $skipped as $code => $entry ) {
			$this->group( Texts::reason( (string) $code ), (int) $entry['count'], (array) $entry['sample'], '' );
		}
		echo '</div>';
	}

	private function issues( array $feed, array $issues ): void {
		echo '<div class="nwr-pf-card"><h2>' . esc_html__( 'Validácia položiek', 'nwr-product-feeds' ) . '</h2>';
		if ( ! $issues ) {
			echo '<p class="nwr-pf-ok">' . esc_html__( 'Bez nálezov.', 'nwr-product-feeds' ) . '</p></div>';
			return;
		}
		$order = array(
			'error'   => 0,
			'warning' => 1,
			'info'    => 2,
		);
		uksort(
			$issues,
			static function ( $a, $b ) use ( $order, $issues ): int {
				$sa = $order[ Texts::issue( (string) $a )[1] ];
				$sb = $order[ Texts::issue( (string) $b )[1] ];
				return $sa <=> $sb ?: $issues[ $b ]['count'] <=> $issues[ $a ]['count'];
			}
		);
		foreach ( $issues as $code => $entry ) {
			[ $label, $severity ] = Texts::issue( (string) $code );
			$this->group( $label, (int) $entry['count'], (array) $entry['sample'], $severity );
		}
		if ( ( isset( $issues['missing_brand'] ) || isset( $issues['missing_identifiers'] ) ) && '' === (string) $feed['brand_default'] ) {
			echo '<p class="nwr-pf-hint">';
			printf(
				/* translators: %s: link to the feed settings */
				esc_html__( 'Tip: ak predávate vlastnú značku, nastavte ju ako predvolenú v %s. Dostanú ju produkty aj variácie bez značky.', 'nwr-product-feeds' ),
				'<a href="' . esc_url( Admin::url( array( 'view' => 'edit', 'feed' => $feed['id'] ) ) . '#nwr-pf-brand_default' ) . '">' . esc_html__( 'nastaveniach feedu', 'nwr-product-feeds' ) . '</a>'
			);
			echo '</p>';
		}
		echo '<p class="nwr-pf-muted">' . esc_html__( 'Chyby: položku platforma pravdepodobne odmietne. Upozornenia: položka prejde, ale oplatí sa ju opraviť. Informácie: na vedomie.', 'nwr-product-feeds' ) . '</p>';
		echo '</div>';
	}

	private function group( string $label, int $count, array $sample, string $severity ): void {
		$ids = array();
		foreach ( $sample as [ $id, $parent ] ) {
			$ids[] = (int) $id;
			$ids[] = (int) $parent;
		}
		Source::prime( array_filter( $ids ), false );

		printf(
			'<details class="nwr-pf-group nwr-pf-group--%1$s"><summary><span class="nwr-pf-count">%2$s</span> %3$s</summary><ul>',
			esc_attr( '' !== $severity ? $severity : 'skip' ),
			esc_html( number_format_i18n( $count ) ),
			esc_html( $label )
		);
		foreach ( $sample as [ $id, $parent, $detail ] ) {
			$edit  = get_edit_post_link( $parent ? (int) $parent : (int) $id, 'raw' );
			$title = get_the_title( (int) $id );
			if ( $parent ) {
				/* translators: 1: variation title, 2: variation ID */
				$title = sprintf( __( '%1$s (variácia #%2$d)', 'nwr-product-feeds' ), $title ?: get_the_title( (int) $parent ), (int) $id );
			} else {
				$title = ( $title ?: __( '(bez názvu)', 'nwr-product-feeds' ) ) . ' #' . (int) $id;
			}
			echo '<li>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) );
			if ( '' !== (string) $detail ) {
				echo ' <span class="nwr-pf-muted">— ' . esc_html( (string) $detail ) . '</span>';
			}
			echo '</li>';
		}
		if ( $count > count( $sample ) ) {
			/* translators: %d: count */
			echo '<li class="nwr-pf-muted">' . esc_html( sprintf( __( '…a ďalších %d', 'nwr-product-feeds' ), $count - count( $sample ) ) ) . '</li>';
		}
		echo '</ul></details>';
	}

	private function preview( array $feed, ?array $info ): void {
		$limit  = isset( $_GET['n'] ) ? min( 50, max( 1, absint( $_GET['n'] ) ) ) : 5; // phpcs:ignore WordPress.Security.NonceVerification
		$sample = isset( $_GET['sample'] ); // phpcs:ignore WordPress.Security.NonceVerification

		echo '<div class="nwr-pf-card"><h2>' . esc_html__( 'Náhľad položiek', 'nwr-product-feeds' ) . '</h2>';
		echo '<form method="get" class="nwr-pf-inline"><input type="hidden" name="page" value="' . esc_attr( Admin::PAGE ) . '"><input type="hidden" name="view" value="report"><input type="hidden" name="feed" value="' . esc_attr( $feed['id'] ) . '">';
		echo '<label>' . esc_html__( 'Počet položiek', 'nwr-product-feeds' ) . ' <input type="number" class="small-text" name="n" min="1" max="50" value="' . (int) $limit . '"></label> ';
		echo '<button class="button">' . esc_html__( 'Zo zverejneného súboru', 'nwr-product-feeds' ) . '</button> ';
		echo '<button class="button" name="sample" value="1">' . esc_html__( 'Ukážka z aktuálnych nastavení', 'nwr-product-feeds' ) . '</button>';
		echo '</form>';

		if ( $sample ) {
			$result = Generator::sample( $feed, min( 20, $limit ) );
			/* translators: 1: items, 2: scanned products */
			echo '<p class="nwr-pf-muted">' . esc_html( sprintf( __( 'Ukážka: %1$d položiek z prvých %2$d produktov, s aktuálnymi nastaveniami (nič sa neuložilo).', 'nwr-product-feeds' ), count( $result['items'] ), $result['scanned'] ) ) . '</p>';
			echo '<pre class="nwr-pf-code">' . esc_html( $result['body'] ) . '</pre>';
		} elseif ( $info ) {
			echo '<pre class="nwr-pf-code">' . esc_html( self::head( $feed, $limit ) ) . '</pre>';
		} else {
			echo '<p>' . esc_html__( 'Zverejnený súbor zatiaľ neexistuje.', 'nwr-product-feeds' ) . '</p>';
		}
		echo '</div>';
	}

	/** First $limit items of the published file, without reading it whole. */
	public static function head( array $feed, int $limit ): string {
		$path = Storage::path( $feed );
		if ( 'xml' !== Storage::extension( $feed ) || ! class_exists( XMLReader::class ) ) {
			$lines  = array();
			$handle = fopen( $path, 'rb' );
			while ( $handle && count( $lines ) <= $limit && false !== ( $line = fgets( $handle ) ) ) {
				$lines[] = rtrim( $line, "\n" );
			}
			if ( $handle ) {
				fclose( $handle );
			}
			return implode( "\n", $lines );
		}

		$reader = new XMLReader();
		if ( ! $reader->open( $path, 'UTF-8', LIBXML_NONET ) ) {
			return '';
		}
		$out = array();
		while ( count( $out ) < $limit && @$reader->read() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( XMLReader::ELEMENT === $reader->nodeType && 'item' === $reader->name ) {
				$out[] = str_replace( ' xmlns:g="' . \Nowera\ProductFeeds\Output\Rss_Writer::NAMESPACE_G . '"', '', $reader->readOuterXml() );
			}
		}
		$reader->close();
		return implode( "\n", $out );
	}

	private function log( string $feed_id ): void {
		$entries = array_slice( Log::entries( $feed_id ), 0, 20 );
		echo '<div class="nwr-pf-card"><h2>' . esc_html__( 'Log feedu', 'nwr-product-feeds' ) . '</h2>';
		Settings_Page::log_table( $entries, false );
		echo '</div>';
	}
}
