<?php
namespace Nowera\ProductFeeds;

defined( 'ABSPATH' ) || exit;

/**
 * Per-feed runtime data, each in its own non-autoloaded option:
 * - run    — the generation in progress (or the last one's outcome),
 * - report — the last published file: counts, skipped products, issues,
 * - seen   — item IDs written by the current run (duplicate check).
 */
final class State {

	const RUN    = 'nwr_pf_run_';
	const REPORT = 'nwr_pf_report_';
	const SEEN   = 'nwr_pf_seen_';

	/** Samples kept per skip reason / issue, for the admin report. */
	const SAMPLES = 200;

	public static function run_defaults(): array {
		return array(
			'status'      => 'idle', // idle | running | failed | guarded
			'run_id'      => '',
			'trigger'     => '',
			'started_at'  => 0,
			'updated_at'  => 0,
			'finished_at' => 0,
			'cursor'      => 0,
			'batch_size'  => 0,
			'batches'     => 0,
			'rerun'       => false,
			'tmp'         => '',
			'error'       => '',
			'counts'      => self::zero_counts(),
			'skipped'     => array(),
			'issues'      => array(),
			'pending'     => null, // held-back file: ['file' => …, 'items' => n, 'previous' => n, 'at' => ts]
		);
	}

	public static function zero_counts(): array {
		return array(
			'products'   => 0, // published parent products examined
			'variable'   => 0, // of which variable
			'items'      => 0, // written to the file
			'simple'     => 0, // items: simple and other single products
			'variations'   => 0, // items: variations
			'out_of_stock' => 0, // items: sold out (they stay in the feed)
			'skipped'      => 0, // products or variations left out
		);
	}

	/**
	 * @param bool $fresh bypass this request's option cache — another request
	 *                    (a batch, the admin) may have changed the run since
	 */
	public static function run( string $feed_id, bool $fresh = false ): array {
		if ( $fresh ) {
			wp_cache_delete( self::RUN . $feed_id, 'options' );
		}
		$run = get_option( self::RUN . $feed_id, array() );
		return array_merge( self::run_defaults(), is_array( $run ) ? $run : array() );
	}

	public static function save_run( string $feed_id, array $run ): void {
		update_option( self::RUN . $feed_id, $run, false );
	}

	public static function report( string $feed_id ): array {
		$report = get_option( self::REPORT . $feed_id, array() );
		return is_array( $report ) ? $report : array();
	}

	public static function save_report( string $feed_id, array $report ): void {
		update_option( self::REPORT . $feed_id, $report, false );
	}

	/** @return array<string,int> item ID => product ID */
	public static function seen( string $feed_id ): array {
		$seen = get_option( self::SEEN . $feed_id, array() );
		return is_array( $seen ) ? $seen : array();
	}

	public static function save_seen( string $feed_id, array $seen ): void {
		update_option( self::SEEN . $feed_id, $seen, false );
	}

	public static function forget( string $feed_id ): void {
		delete_option( self::RUN . $feed_id );
		delete_option( self::REPORT . $feed_id );
		delete_option( self::SEEN . $feed_id );
	}

	/**
	 * Adds one batch's outcome to the running totals.
	 *
	 * @param array $bucket skipped or issues: code => ['count' => n, 'sample' => [[id, parent, detail], …]]
	 * @param array $entries list of [code, id, parent, detail]
	 */
	public static function merge_entries( array $bucket, array $entries ): array {
		foreach ( $entries as [ $code, $id, $parent, $detail ] ) {
			if ( ! isset( $bucket[ $code ] ) ) {
				$bucket[ $code ] = array(
					'count'  => 0,
					'sample' => array(),
				);
			}
			++$bucket[ $code ]['count'];
			if ( count( $bucket[ $code ]['sample'] ) < self::SAMPLES ) {
				$bucket[ $code ]['sample'][] = array( (int) $id, (int) $parent, (string) $detail );
			}
		}
		return $bucket;
	}

	public static function merge_counts( array $totals, array $counts ): array {
		foreach ( $counts as $key => $value ) {
			$totals[ $key ] = (int) ( $totals[ $key ] ?? 0 ) + (int) $value;
		}
		return $totals;
	}
}
