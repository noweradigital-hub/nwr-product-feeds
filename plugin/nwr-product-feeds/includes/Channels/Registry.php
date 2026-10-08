<?php
namespace Nowera\ProductFeeds\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * Available feed templates. Add one:
 *
 *     add_filter( 'nwr_pf_channels', fn( $channels ) => $channels + array( 'heureka' => My_Heureka::class ) );
 *
 * The class must extend Channel.
 */
final class Registry {

	private static ?self $instance = null;

	/** @var array<string,Channel>|null */
	private ?array $channels = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/** @return array<string,Channel> */
	public function all(): array {
		if ( null === $this->channels ) {
			$this->channels = array();
			$classes        = (array) apply_filters(
				'nwr_pf_channels',
				array(
					'google' => Google::class,
					'meta'   => Meta::class,
				)
			);
			foreach ( $classes as $key => $class ) {
				if ( is_string( $class ) && is_subclass_of( $class, Channel::class ) ) {
					$channel = new $class();
					if ( $channel->key() === $key ) {
						$this->channels[ $key ] = $channel;
					}
				}
			}
		}
		return $this->channels;
	}

	public function get( string $key ): ?Channel {
		return $this->all()[ $key ] ?? null;
	}
}
