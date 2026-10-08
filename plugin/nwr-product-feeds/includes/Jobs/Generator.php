<?php
namespace Nowera\ProductFeeds\Jobs;

use Nowera\ProductFeeds\Catalog\Item_Builder;
use Nowera\ProductFeeds\Catalog\Source;
use Nowera\ProductFeeds\Channels\Channel;
use Nowera\ProductFeeds\Channels\Registry;
use Nowera\ProductFeeds\Feeds;
use Nowera\ProductFeeds\Log;
use Nowera\ProductFeeds\Output\Validator;
use Nowera\ProductFeeds\Output\Writer;
use Nowera\ProductFeeds\Settings;
use Nowera\ProductFeeds\State;
use Nowera\ProductFeeds\Storage;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Generation of one feed in the background.
 *
 * request() opens a temp file and queues the first batch; every batch
 * handles the next parent products (keyset by ID) and queues the next one;
 * the last batch checks the file and renames it over the public one. Nothing
 * here ever runs during a normal page view.
 */
final class Generator {

	/** A running generation without progress for this long is considered dead. */
	const STALE_AFTER = 1800;
	const MIN_BATCH   = 5;
	const SLOW_BATCH  = 20;
	const FAST_BATCH  = 5;

	/**
	 * Starts a generation (or asks the running one to repeat afterwards).
	 *
	 * @param string $trigger schedule | change | manual | rerun
	 * @return string started | queued | missing | disabled | error
	 */
	public static function request( string $feed_id, string $trigger ): string {
		$feed = Feeds::get( $feed_id );
		if ( ! $feed ) {
			return 'missing';
		}
		if ( empty( $feed['enabled'] ) && 'manual' !== $trigger ) {
			return 'disabled';
		}
		$channel = Registry::instance()->get( $feed['channel'] );
		if ( ! $channel ) {
			Log::error( $feed_id, sprintf( 'Šablóna feedu „%s“ nie je dostupná.', $feed['channel'] ) );
			return 'error';
		}
		if ( ! Scheduler::available() ) {
			Log::error( $feed_id, 'Action Scheduler nie je dostupný — feed sa nedá generovať na pozadí.' );
			return 'error';
		}

		$run = State::run( $feed_id, true );
		if ( 'running' === $run['status'] ) {
			if ( time() - (int) $run['updated_at'] < self::STALE_AFTER ) {
				if ( empty( $run['rerun'] ) ) {
					$run['rerun'] = true;
					State::save_run( $feed_id, $run );
				}
				return 'queued';
			}
			Log::warning( $feed_id, 'Predchádzajúce generovanie 30 minút nepokročilo — spúšťam nové.' );
			self::delete_tmp( $run );
		}

		$ready = Storage::ensure();
		if ( is_wp_error( $ready ) ) {
			self::fail( $feed_id, $run, $ready->get_error_message() );
			return 'error';
		}
		Storage::cleanup_tmp();
		if ( ! empty( $run['pending'] ) ) {
			// A newer run replaces a file that was held back for review.
			Storage::delete_tmp( (string) $run['pending']['file'] );
		}

		$run_id = bin2hex( random_bytes( 6 ) );
		$tmp    = basename( Storage::tmp_path( $feed, $run_id ) );
		$fresh  = array_merge(
			State::run_defaults(),
			array(
				'status'     => 'running',
				'run_id'     => $run_id,
				'trigger'    => $trigger,
				'started_at' => time(),
				'updated_at' => time(),
				'batch_size' => (int) Settings::get( 'batch_size' ),
				'tmp'        => $tmp,
			)
		);

		try {
			Writer::for_feed( $feed, $channel )->begin( Storage::tmp_dir() . '/' . $tmp );
		} catch ( Throwable $e ) {
			self::fail( $feed_id, $fresh, $e->getMessage() );
			return 'error';
		}

		State::save_run( $feed_id, $fresh );
		State::save_seen( $feed_id, array() );
		Scheduler::enqueue_batch( $feed_id, $run_id );
		return 'started';
	}

	/** Action Scheduler handler: one batch of parent products. */
	public static function batch( $feed_id = '', $run_id = '' ): void {
		$feed_id = (string) $feed_id;
		$run_id  = (string) $run_id;
		$run     = State::run( $feed_id, true );
		if ( 'running' !== $run['status'] || $run['run_id'] !== $run_id ) {
			return; // Cancelled or replaced by a newer run.
		}

		$feed    = Feeds::get( $feed_id );
		$channel = $feed ? Registry::instance()->get( $feed['channel'] ) : null;
		if ( ! $feed || ! $channel ) {
			self::delete_tmp( $run );
			return;
		}

		$started = microtime( true );
		try {
			$size = max( self::MIN_BATCH, (int) $run['batch_size'] );
			$rows = Source::parents( (int) $run['cursor'], $size );
			if ( ! $rows ) {
				self::finish( $feed, $channel, $run );
				return;
			}

			$builder = new Item_Builder( $feed, $channel, State::seen( $feed_id ) );
			$result  = $builder->build( $rows );
			Writer::for_feed( $feed, $channel )->append( self::tmp_path( $run ), $result->rows );

			$run['cursor']     = $result->last_id;
			$run['counts']     = State::merge_counts( $run['counts'], $result->counts );
			$run['skipped']    = State::merge_entries( $run['skipped'], $result->skipped );
			$run['issues']     = State::merge_entries( $run['issues'], $result->issues );
			$run['updated_at'] = time();
			++$run['batches'];

			// Smaller batches on a slow site, back up to the setting when it is fast again.
			$elapsed    = microtime( true ) - $started;
			$configured = max( self::MIN_BATCH, (int) Settings::get( 'batch_size' ) );
			if ( $elapsed > self::SLOW_BATCH ) {
				$run['batch_size'] = max( self::MIN_BATCH, intdiv( $size, 2 ) );
			} elseif ( $elapsed < self::FAST_BATCH && $size < $configured ) {
				$run['batch_size'] = min( $configured, $size * 2 );
			}

			$current = State::run( $feed_id, true );
			if ( 'running' !== $current['status'] || $current['run_id'] !== $run_id ) {
				return; // Cancelled while this batch ran.
			}
			$run['rerun'] = ! empty( $current['rerun'] ) || ! empty( $run['rerun'] );
			State::save_seen( $feed_id, $builder->seen() );
			State::save_run( $feed_id, $run );

			$last_page = count( $rows ) < $size;
			unset( $builder, $result, $rows );
			self::free_memory();

			if ( $last_page ) {
				self::finish( $feed, $channel, $run );
			} else {
				Scheduler::enqueue_batch( $feed_id, $run_id );
			}
		} catch ( Throwable $e ) {
			self::fail( $feed_id, $run, $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')' );
		}
	}

	private static function finish( array $feed, Channel $channel, array $run ): void {
		$feed_id = $feed['id'];
		$path    = self::tmp_path( $run );
		Writer::for_feed( $feed, $channel )->finish( $path );

		$items = (int) $run['counts']['items'];
		$check = Validator::check( $path, Storage::extension( $feed ), $items );
		if ( ! $check['ok'] ) {
			self::fail( $feed_id, $run, 'Kontrola súboru zlyhala: ' . $check['error'] );
			return;
		}

		$report   = State::report( $feed_id );
		$previous = (int) ( $report['items'] ?? 0 );
		$guard    = (int) $feed['drop_guard'];
		if ( $guard > 0 && $previous >= 10 && $items < $previous * ( 100 - $guard ) / 100 ) {
			$pending = Storage::pending_path( $feed );
			if ( ! @rename( $path, $pending ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				self::fail( $feed_id, $run, 'Podržaný súbor sa nepodarilo odložiť.' );
				return;
			}
			$run['status']      = 'guarded';
			$run['finished_at'] = time();
			$run['tmp']         = '';
			$run['pending']     = array(
				'file'     => basename( $pending ),
				'items'    => $items,
				'previous' => $previous,
				'at'       => time(),
			);
			State::save_run( $feed_id, $run );
			State::save_seen( $feed_id, array() );
			Log::warning( $feed_id, sprintf( 'Nový súbor má %1$d položiek, predchádzajúci %2$d. Nezverejnil som ho — potvrď ho alebo zahoď v administrácii.', $items, $previous ) );
			return;
		}

		try {
			$file = Storage::publish( $path, $feed, (string) ( $report['file'] ?? '' ) );
		} catch ( Throwable $e ) {
			self::fail( $feed_id, $run, $e->getMessage() );
			return;
		}
		self::complete( $feed, $run, $file, $items );
	}

	/** The file is public: store the report, reset the run, repeat if changes came in meanwhile. */
	private static function complete( array $feed, array $run, string $file, int $items ): void {
		$feed_id = $feed['id'];
		$now     = time();
		$info    = Storage::info( $feed );
		$counts  = $run['counts'];

		State::save_report(
			$feed_id,
			array(
				'run_id'        => $run['run_id'],
				'trigger'       => $run['trigger'],
				'started_at'    => (int) $run['started_at'],
				'finished_at'   => $now,
				'duration'      => max( 0, $now - (int) $run['started_at'] ),
				'batches'       => (int) $run['batches'],
				'items'         => $items,
				'bytes'         => $info ? $info['size'] : 0,
				'file'          => $file,
				'channel'       => $feed['channel'],
				'format'        => $feed['format'],
				'counts'        => $counts,
				'skipped'       => $run['skipped'],
				'issues'        => $run['issues'],
				'census'        => Source::census(),
				'last_error'    => '',
				'last_error_at' => 0,
			)
		);

		$current = State::run( $feed_id, true );
		$rerun   = ! empty( $current['rerun'] ) || ! empty( $run['rerun'] );
		State::save_run(
			$feed_id,
			array_merge(
				State::run_defaults(),
				array(
					'status'      => 'idle',
					'run_id'      => $run['run_id'],
					'trigger'     => $run['trigger'],
					'started_at'  => (int) $run['started_at'],
					'finished_at' => $now,
					'counts'      => $counts,
				)
			)
		);
		State::save_seen( $feed_id, array() );

		Log::info(
			$feed_id,
			sprintf(
				'Feed zverejnený: %1$d položiek (%2$d jednoduchých, %3$d variácií), vynechaných %4$d, %5$s za %6$d s (%7$s).',
				$items,
				(int) $counts['simple'],
				(int) $counts['variations'],
				(int) $counts['skipped'],
				size_format( $info ? $info['size'] : 0 ),
				max( 0, $now - (int) $run['started_at'] ),
				$run['trigger']
			)
		);

		if ( $rerun ) {
			Scheduler::schedule_rerun( $feed_id );
		}
	}

	/** Publishes a file the drop guard held back. */
	public static function publish_pending( string $feed_id ): bool {
		$feed = Feeds::get( $feed_id );
		$run  = State::run( $feed_id, true );
		if ( ! $feed || 'guarded' !== $run['status'] || empty( $run['pending'] ) ) {
			return false;
		}
		$path  = Storage::tmp_dir() . '/' . basename( (string) $run['pending']['file'] );
		$items = (int) $run['pending']['items'];
		$check = Validator::check( $path, Storage::extension( $feed ), $items );
		if ( ! $check['ok'] ) {
			self::fail( $feed_id, $run, 'Podržaný súbor je neplatný: ' . $check['error'] );
			return false;
		}
		try {
			$file = Storage::publish( $path, $feed, (string) ( State::report( $feed_id )['file'] ?? '' ) );
		} catch ( Throwable $e ) {
			self::fail( $feed_id, $run, $e->getMessage() );
			return false;
		}
		Log::info( $feed_id, sprintf( 'Podržaný súbor s %d položkami zverejnený ručne.', $items ) );
		self::complete( $feed, $run, $file, $items );
		return true;
	}

	public static function discard_pending( string $feed_id ): void {
		$run = State::run( $feed_id, true );
		if ( ! empty( $run['pending'] ) ) {
			Storage::delete_tmp( (string) $run['pending']['file'] );
		}
		$run['pending'] = null;
		$run['status']  = 'idle';
		State::save_run( $feed_id, $run );
		Log::info( $feed_id, 'Podržaný súbor zahodený, ostáva posledný zverejnený.' );
	}

	public static function cancel( string $feed_id ): void {
		$run = State::run( $feed_id, true );
		if ( 'running' !== $run['status'] ) {
			return;
		}
		self::delete_tmp( $run );
		$run['status']      = 'idle';
		$run['tmp']         = '';
		$run['rerun']       = false;
		$run['finished_at'] = time();
		$run['error']       = 'Zrušené v administrácii.';
		State::save_run( $feed_id, $run );
		State::save_seen( $feed_id, array() );
		Log::info( $feed_id, 'Generovanie zrušené, ostáva posledný zverejnený súbor.' );
	}

	/**
	 * A sample from the current settings, built in memory (admin preview).
	 * Scans at most $scan parent products.
	 *
	 * @return array{items:list<array>,skipped:list<array>,issues:list<array>,scanned:int,body:string}
	 */
	public static function sample( array $feed, int $limit, int $scan = 200 ): array {
		$channel = Registry::instance()->get( $feed['channel'] );
		$out     = array(
			'items'   => array(),
			'skipped' => array(),
			'issues'  => array(),
			'scanned' => 0,
			'body'    => '',
		);
		if ( ! $channel ) {
			return $out;
		}

		$builder = new Item_Builder( $feed, $channel, array(), true );
		$cursor  = 0;
		while ( count( $out['items'] ) < $limit && $out['scanned'] < $scan ) {
			$rows = Source::parents( $cursor, 20 );
			if ( ! $rows ) {
				break;
			}
			$batch = $builder->build( $rows );
			foreach ( $batch->items as $entry ) {
				if ( count( $out['items'] ) < $limit ) {
					$out['items'][] = $entry;
				}
			}
			$out['skipped']  = array_merge( $out['skipped'], $batch->skipped );
			$out['issues']   = array_merge( $out['issues'], $batch->issues );
			$out['scanned'] += count( $rows );
			$cursor          = $batch->last_id;
		}

		$ready = Storage::ensure();
		if ( is_wp_error( $ready ) ) {
			return $out;
		}
		$file = Storage::tmp_dir() . '/sample-' . bin2hex( random_bytes( 6 ) ) . '.part';
		try {
			$writer = Writer::for_feed( $feed, $channel );
			$writer->begin( $file );
			$writer->append( $file, array_column( $out['items'], 'fields' ) );
			$writer->finish( $file );
			$out['body'] = (string) file_get_contents( $file );
		} finally {
			Storage::delete_tmp( basename( $file ) );
		}
		return $out;
	}

	private static function fail( string $feed_id, array $run, string $message ): void {
		self::delete_tmp( $run );
		$run['status']      = 'failed';
		$run['error']       = $message;
		$run['tmp']         = '';
		$run['finished_at'] = time();
		$run['rerun']       = false;
		State::save_run( $feed_id, $run );
		State::save_seen( $feed_id, array() );

		$report                  = State::report( $feed_id );
		$report['last_error']    = $message;
		$report['last_error_at'] = time();
		State::save_report( $feed_id, $report );

		Log::error( $feed_id, 'Generovanie zlyhalo, ostáva posledný platný súbor. ' . $message );
	}

	private static function tmp_path( array $run ): string {
		return Storage::tmp_dir() . '/' . basename( (string) $run['tmp'] );
	}

	private static function delete_tmp( array $run ): void {
		if ( ! empty( $run['tmp'] ) ) {
			Storage::delete_tmp( basename( (string) $run['tmp'] ) );
		}
	}

	/** Runtime cache only — never a flush of a shared object cache. */
	private static function free_memory(): void {
		if ( function_exists( 'wp_cache_supports' ) && function_exists( 'wp_cache_flush_runtime' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
		gc_collect_cycles();
	}
}
