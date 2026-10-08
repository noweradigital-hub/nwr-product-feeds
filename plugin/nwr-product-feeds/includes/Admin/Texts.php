<?php
namespace Nowera\ProductFeeds\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Human-readable names of skip reasons and validation issues.
 */
final class Texts {

	public static function reason( string $code ): string {
		$reasons = array(
			'not_published'      => __( 'Nepublikovaný', 'nwr-product-feeds' ),
			'password'           => __( 'Chránený heslom', 'nwr-product-feeds' ),
			'hidden'             => __( 'Skrytý z katalógu', 'nwr-product-feeds' ),
			'type'               => __( 'Typ produktu nie je vo feede povolený', 'nwr-product-feeds' ),
			'no_price'           => __( 'Bez ceny', 'nwr-product-feeds' ),
			'no_image'           => __( 'Bez obrázka', 'nwr-product-feeds' ),
			'excluded'           => __( 'Vylúčený v nastaveniach produktu', 'nwr-product-feeds' ),
			'filter_category'    => __( 'Filter feedu: kategória', 'nwr-product-feeds' ),
			'filter_tag'         => __( 'Filter feedu: štítok', 'nwr-product-feeds' ),
			'filter_stock'       => __( 'Filter feedu: vypredané', 'nwr-product-feeds' ),
			'filter_price'       => __( 'Filter feedu: cena', 'nwr-product-feeds' ),
			'filter_id'          => __( 'Filter feedu: vylúčené ID', 'nwr-product-feeds' ),
			'variation_disabled' => __( 'Variácia je vypnutá', 'nwr-product-feeds' ),
			'no_variations'      => __( 'Variabilný produkt bez variácií', 'nwr-product-feeds' ),
			'dynamic_price'      => __( 'Cena sa počíta až z výberu zákazníka (nie je pevná)', 'nwr-product-feeds' ),
			'duplicate_id'       => __( 'Duplicitné ID položky', 'nwr-product-feeds' ),
			'load_failed'        => __( 'Produkt sa nepodarilo načítať', 'nwr-product-feeds' ),
			'custom'             => __( 'Vynechaný filtrom nwr_pf_skip_item', 'nwr-product-feeds' ),
		);
		return $reasons[ $code ] ?? $code;
	}

	/** @return array{0:string,1:string} label, severity (error | warning | info) */
	public static function issue( string $code ): array {
		$issues = array(
			'image_format'              => array( __( 'Obrázok nie je JPEG ani PNG — Meta v špecifikácii uvádza len tieto; ak Commerce Manager hlási chybu obrázka, nahraď ho', 'nwr-product-feeds' ), 'warning' ),
			'id_too_long'               => array( __( 'ID je dlhšie, ako platforma dovoľuje', 'nwr-product-feeds' ), 'error' ),
			'missing_identifiers'       => array( __( 'Chýba značka, GTIN aj MPN — Meta hlási „Brand or universal ID is missing“', 'nwr-product-feeds' ), 'warning' ),
			'missing_brand'             => array( __( 'Chýba značka', 'nwr-product-feeds' ), 'warning' ),
			'invalid_gtin'              => array( __( 'Neplatný GTIN — do feedu nešiel', 'nwr-product-feeds' ), 'warning' ),
			'missing_description'       => array( __( 'Chýba popis (použitý názov)', 'nwr-product-feeds' ), 'warning' ),
			'short_description'         => array( __( 'Popis kratší ako 30 znakov (Meta)', 'nwr-product-feeds' ), 'warning' ),
			'long_title'                => array( __( 'Názov skrátený na limit platformy', 'nwr-product-feeds' ), 'warning' ),
			'long_description'          => array( __( 'Popis skrátený na limit platformy', 'nwr-product-feeds' ), 'warning' ),
			'any_attribute'             => array( __( 'Variácia má atribút „ľubovoľná hodnota“', 'nwr-product-feeds' ), 'warning' ),
			'gtin_from_parent'          => array( __( 'Variácia preberá GTIN rodiča (Google hlási duplicitný GTIN)', 'nwr-product-feeds' ), 'warning' ),
			'builder_description'       => array( __( 'Popis je z Elementora a nepoužil sa — doplň krátky popis', 'nwr-product-feeds' ), 'warning' ),
			'availability_date_guessed' => array( __( 'Dátum dostupnosti odhadnutý z nastavenia feedu', 'nwr-product-feeds' ), 'info' ),
			'brand_default'             => array( __( 'Značka z nastavenia feedu (produkt ani rodič ju nemá)', 'nwr-product-feeds' ), 'info' ),
			'missing_gtin'              => array( __( 'Bez GTIN (EAN)', 'nwr-product-feeds' ), 'info' ),
			'missing_gpc'               => array( __( 'Bez Google kategórie', 'nwr-product-feeds' ), 'info' ),
			'variation_no_image'        => array( __( 'Variácia bez vlastného obrázka (použitý obrázok rodiča)', 'nwr-product-feeds' ), 'info' ),
		);
		return $issues[ $code ] ?? array( $code, 'info' );
	}

	public static function trigger( string $trigger ): string {
		$triggers = array(
			'schedule' => __( 'plán', 'nwr-product-feeds' ),
			'change'   => __( 'zmena produktov', 'nwr-product-feeds' ),
			'manual'   => __( 'ručne', 'nwr-product-feeds' ),
			'rerun'    => __( 'zmena počas generovania', 'nwr-product-feeds' ),
		);
		return $triggers[ $trigger ] ?? $trigger;
	}

	public static function interval( int $minutes ): string {
		if ( $minutes < 60 ) {
			/* translators: %d: minutes */
			return sprintf( __( 'každých %d min', 'nwr-product-feeds' ), $minutes );
		}
		if ( 60 === $minutes ) {
			return __( 'každú hodinu', 'nwr-product-feeds' );
		}
		if ( 1440 === $minutes ) {
			return __( 'raz denne', 'nwr-product-feeds' );
		}
		/* translators: %d: hours */
		return sprintf( __( 'každých %d h', 'nwr-product-feeds' ), intdiv( $minutes, 60 ) );
	}
}
