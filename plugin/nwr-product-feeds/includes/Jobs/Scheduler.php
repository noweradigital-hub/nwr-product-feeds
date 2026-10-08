<?php
namespace Nowera\ProductFeeds\Jobs;

use ActionScheduler_Store;
use Nowera\ProductFeeds\Feeds;
use Nowera\ProductFeeds\Settings;
use Nowera\ProductFeeds\State;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler hooks (group "nwr-product-feeds"):
 * - nwr_pf_generate [feed]      recurring, per feed (default hourly)
 * - nwr_pf_batch    [feed, run] one batch of a running generation
 * - nwr_pf_dirty    []          products changed; runs once after a delay
 * - nwr_pf_rerun    [feed]      a change arrived while the feed was generating
 */
final class Scheduler {

	const GROUP            = 'nwr-product-feeds';
	const HOOK_RUN         = 'nwr_pf_generate';
	const HOOK_BATCH       = 'nwr_pf_batch';
	const HOOK_DIRTY       = 'nwr_pf_dirty';
	const HOOK_RERUN       = 'nwr_pf_rerun';
	const DIRTY_OPTION     = 'nwr_pf_dirty_at';
	const SIGNATURE_OPTION = 'nwr_pf_schedules';
	const CHECK_TRANSIENT  = 'nwr_pf_schedule_check';

	public function register(): void {
		add_action( self::HOOK_RUN, array( $this, 'scheduled' ) );
		add_action( self::HOOK_RERUN, array( $this, 'rerun' ) );
		add_action( self::HOOK_BATCH, array( Generator::class, 'batch' ), 10, 2 );
		add_action( self::HOOK_DIRTY, array( $this, 'dirty' ), 10, 0 );
		// Self-heal lost schedules, at most hourly, from the queue runner (cron), never from page views.
		add_action( 'action_scheduler_before_process_queue', array( $this, 'self_check' ) );
	}

	public static function available(): bool {
		return function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_enqueue_async_action' );
	}

	public function scheduled( $feed_id = '' ): void {
		Generator::request( (string) $feed_id, 'schedule' );
	}

	public function rerun( $feed_id = '' ): void {
		Generator::request( (string) $feed_id, 'rerun' );
	}

	/** Products changed some minutes ago: regenerate every enabled feed. */
	public function dirty(): void {
		delete_option( self::DIRTY_OPTION );
		foreach ( Feeds::enabled() as $feed ) {
			Generator::request( $feed['id'], 'change' );
		}
	}

	public function self_check(): void {
		if ( get_transient( self::CHECK_TRANSIENT ) ) {
			return;
		}
		set_transient( self::CHECK_TRANSIENT, 1, HOUR_IN_SECONDS );
		self::ensure();
	}

	/**
	 * Called on shutdown of a request that changed products. Cheap when a run
	 * is already pending (one autoloaded option), otherwise one delayed action.
	 */
	public static function mark_dirty(): void {
		$pending = (int) get_option( self::DIRTY_OPTION, 0 );
		if ( $pending && $pending > time() - 30 * MINUTE_IN_SECONDS ) {
			return;
		}
		if ( ! self::available() || ! Feeds::enabled() ) {
			return;
		}
		$when = time() + max( 1, (int) Settings::get( 'dirty_delay' ) ) * MINUTE_IN_SECONDS;
		update_option( self::DIRTY_OPTION, $when, true );
		as_schedule_single_action( $when, self::HOOK_DIRTY, array(), self::GROUP, true );
	}

	/** Makes the recurring schedules match the feed definitions. */
	public static function ensure(): void {
		if ( ! self::available() ) {
			return;
		}
		$signature = get_option( self::SIGNATURE_OPTION, array() );
		$signature = is_array( $signature ) ? $signature : array();
		$feeds     = Feeds::all();
		$next      = array();

		foreach ( $feeds as $id => $feed ) {
			$args = array( (string) $id );
			if ( empty( $feed['enabled'] ) ) {
				as_unschedule_all_actions( self::HOOK_RUN, $args, self::GROUP );
				continue;
			}
			$interval  = max( 15, (int) $feed['interval'] ) * MINUTE_IN_SECONDS;
			$scheduled = as_next_scheduled_action( self::HOOK_RUN, $args, self::GROUP );
			if ( ! $scheduled || (int) ( $signature[ $id ] ?? 0 ) !== $interval ) {
				as_unschedule_all_actions( self::HOOK_RUN, $args, self::GROUP );
				// A feed that never produced a file starts right away.
				$first = State::report( (string) $id ) ? time() + $interval : time() + MINUTE_IN_SECONDS;
				as_schedule_recurring_action( $first, $interval, self::HOOK_RUN, $args, self::GROUP );
			}
			$next[ $id ] = $interval;
		}

		foreach ( array_diff_key( $signature, $feeds ) as $id => $unused ) {
			self::unschedule_feed( (string) $id );
		}
		update_option( self::SIGNATURE_OPTION, $next, false );
	}

	public static function unschedule_feed( string $feed_id ): void {
		if ( self::available() ) {
			as_unschedule_all_actions( self::HOOK_RUN, array( $feed_id ), self::GROUP );
			as_unschedule_all_actions( self::HOOK_RERUN, array( $feed_id ), self::GROUP );
		}
	}

	public static function enqueue_batch( string $feed_id, string $run_id ): void {
		as_enqueue_async_action( self::HOOK_BATCH, array( $feed_id, $run_id ), self::GROUP );
	}

	public static function schedule_rerun( string $feed_id ): void {
		if ( self::available() && ! as_next_scheduled_action( self::HOOK_RERUN, array( $feed_id ), self::GROUP ) ) {
			as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::HOOK_RERUN, array( $feed_id ), self::GROUP );
		}
	}

	/** Next scheduled regeneration of a feed (timestamp) or null. */
	public static function next_run( string $feed_id ): ?int {
		if ( ! self::available() ) {
			return null;
		}
		$next = as_next_scheduled_action( self::HOOK_RUN, array( $feed_id ), self::GROUP );
		return is_int( $next ) ? $next : null;
	}

	/**
	 * For the admin warning: WP-Cron switched off, and our jobs waiting past
	 * their time (a sign that nothing runs the queue).
	 *
	 * @return array{wp_cron_disabled:bool,late:int,oldest:int}
	 */
	public static function health(): array {
		$late   = 0;
		$oldest = 0;
		if ( self::available() && function_exists( 'as_get_scheduled_actions' ) ) {
			$actions = as_get_scheduled_actions(
				array(
					'group'        => self::GROUP,
					'status'       => ActionScheduler_Store::STATUS_PENDING,
					'date'         => as_get_datetime_object( time() - 15 * MINUTE_IN_SECONDS ),
					'date_compare' => '<=',
					'per_page'     => 50,
					'orderby'      => 'date',
					'order'        => 'ASC',
				)
			);
			$late = count( $actions );
			foreach ( $actions as $action ) {
				$date = $action->get_schedule()->get_date();
				if ( $date ) {
					$oldest = $date->getTimestamp();
					break;
				}
			}
		}
		return array(
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'late'             => $late,
			'oldest'           => $oldest,
		);
	}
}
