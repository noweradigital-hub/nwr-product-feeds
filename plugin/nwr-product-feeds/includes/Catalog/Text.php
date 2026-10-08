<?php
namespace Nowera\ProductFeeds\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text helpers for feed values.
 */
final class Text {

	/** Valid UTF-8 (invalid sequences become U+FFFD) without characters XML 1.0 forbids. */
	public static function scrub( string $text ): string {
		if ( '' === $text ) {
			return '';
		}
		if ( ! preg_match( '//u', $text ) ) {
			$text = function_exists( 'mb_scrub' )
				? mb_scrub( $text, 'UTF-8' )
				: htmlspecialchars_decode( htmlspecialchars( $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' ), ENT_NOQUOTES );
		}
		return preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text ) ?? '';
	}

	/** One line of text: no tags, entities decoded, whitespace collapsed. */
	public static function line( string $text ): string {
		$text = self::scrub( $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = self::scrub( $text );
		return trim( preg_replace( '/[\s\x{00A0}\x{200B}]+/u', ' ', $text ) ?? '' );
	}

	/**
	 * Readable text from product HTML: shortcodes (also of plugins that are not
	 * loaded in cron), scripts, styles and tags removed.
	 */
	public static function plain( string $html ): string {
		$html = self::scrub( $html );
		if ( '' === trim( $html ) ) {
			return '';
		}
		$html = strip_shortcodes( $html );
		$html = self::strip_unknown_shortcodes( $html );
		// Keep words of neighbouring blocks apart once the tags are gone.
		$html = preg_replace( '#<(?:br|hr)\b[^>]*>|</(?:p|div|li|ul|ol|h[1-6]|tr|td|th|table|blockquote|section|article)>#i', '$0 ', $html ) ?? $html;
		return self::line( $html );
	}

	/**
	 * Leftover [shortcodes]: closing tags, tags with attributes, and names with
	 * "_" or "-" (vc_row, et_pb_text, elementor-template…). "[2 ks]" stays.
	 */
	public static function strip_unknown_shortcodes( string $text ): string {
		return preg_replace(
			array(
				'/\[\/[a-zA-Z][\w-]*\]/',
				'/\[[a-zA-Z][\w-]*\s[^\[\]]*=[^\[\]]*\]/',
				'/\[[a-zA-Z][a-zA-Z0-9]*[_-][\w-]*\]/',
			),
			' ',
			$text
		) ?? $text;
	}

	/**
	 * Shortens to $max characters at a word boundary.
	 *
	 * @return array{0:string,1:bool} text, whether it was shortened
	 */
	public static function limit( string $text, int $max ): array {
		if ( $max <= 0 || mb_strlen( $text, 'UTF-8' ) <= $max ) {
			return array( $text, false );
		}
		$cut   = mb_substr( $text, 0, $max, 'UTF-8' );
		$space = mb_strrpos( $cut, ' ', 0, 'UTF-8' );
		if ( false !== $space && $space > $max * 0.7 ) {
			$cut = mb_substr( $cut, 0, $space, 'UTF-8' );
		}
		return array( preg_replace( '/[\s,;:\x{2013}\x{2014}-]+$/u', '', $cut ) ?? $cut, true );
	}
}
