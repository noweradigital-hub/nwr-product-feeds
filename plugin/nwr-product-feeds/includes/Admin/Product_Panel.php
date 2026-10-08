<?php
namespace Nowera\ProductFeeds\Admin;

use Nowera\ProductFeeds\Catalog\Gtin;
use Nowera\ProductFeeds\Feeds;
use WC_Admin_Meta_Boxes;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * "Feedy" tab in Product data, and the same fields inside every variation.
 *
 * Values are saved only when the tab was really in the submitted form:
 * simplified product editors (e.g. BrikPanel) fire the same save hook with
 * a partial form, and a missing field must not wipe a stored value.
 */
final class Product_Panel {

	const MARKER = 'nwr_pf_panel';

	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'variation' ), 30, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( $this, 'save_variation' ), 10, 2 );
	}

	public function tab( $tabs ): array {
		$tabs                  = (array) $tabs;
		$tabs['nwr_pf_feeds'] = array(
			'label'    => __( 'Feedy', 'nwr-product-feeds' ),
			'target'   => 'nwr_pf_product_data',
			'class'    => array(),
			'priority' => 85,
		);
		return $tabs;
	}

	public function panel(): void {
		global $post, $product_object;
		$product = $product_object instanceof WC_Product ? $product_object : wc_get_product( $post ? $post->ID : 0 );
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		echo '<div id="nwr_pf_product_data" class="panel woocommerce_options_panel hidden nwr-pf-panel">';
		echo '<input type="hidden" name="' . esc_attr( self::MARKER ) . '" value="1">';

		if ( $product->get_id() && 'auto-draft' !== get_post_status( $product->get_id() ) ) {
			$id = nwr_pf_item_id( $product );
			echo '<p class="form-field"><label>' . esc_html__( 'ID vo feede', 'nwr-product-feeds' ) . '</label><code>' . esc_html( $id ) . '</code> ';
			echo $product->is_type( 'variable' )
				? esc_html__( '— variabilný produkt nie je samostatná položka; toto ID nesú jeho variácie ako item_group_id.', 'nwr-product-feeds' )
				: esc_html__( '— rovnaké ID posiela meranie.', 'nwr-product-feeds' );
			echo '</p>';
		}

		echo '<div class="options_group">';
		$this->fields( $product, '' );
		echo '</div></div>';
	}

	public function variation( $loop, $variation_data, $variation ): void {
		$product = wc_get_product( $variation instanceof \WP_Post ? $variation->ID : 0 );
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$loop = (int) $loop;
		echo '<details class="nwr-pf-variation"><summary>' . esc_html__( 'Feedy (Google, Meta)', 'nwr-product-feeds' ) . ' <code>' . esc_html( nwr_pf_item_id( $product ) ) . '</code></summary>';
		echo '<input type="hidden" name="nwr_pf_var[' . $loop . '][' . esc_attr( self::MARKER ) . ']" value="1">'; // phpcs:ignore WordPress.Security.EscapeOutput -- int.
		$this->fields( $product, 'nwr_pf_var[' . $loop . ']' );
		echo '</details>';
	}

	/** @param string $group '' for the product, "nwr_pf_var[n]" for variation n */
	private function fields( WC_Product $product, string $group ): void {
		$variation = '' !== $group;
		$name      = static fn( string $key ): string => '' === $group ? $key : $group . '[' . $key . ']';
		$id        = static fn( string $key ): string => '' === $group ? $key : sanitize_key( $group ) . '_' . $key;
		$wrap      = $variation ? 'form-row form-row-full' : '';
		$half      = $variation ? 'form-row form-row-first' : '';
		$half2     = $variation ? 'form-row form-row-last' : '';
		$value     = static fn( string $key ): string => is_scalar( $product->get_meta( $key, true, 'edit' ) ) ? (string) $product->get_meta( $key, true, 'edit' ) : '';

		woocommerce_wp_checkbox(
			array(
				'id'            => $id( '_nwr_pf_exclude' ),
				'name'          => $name( '_nwr_pf_exclude' ),
				'label'         => __( 'Vylúčiť zo všetkých feedov', 'nwr-product-feeds' ),
				'description'   => $variation ? __( 'Len táto variácia.', 'nwr-product-feeds' ) : __( 'Pri variabilnom produkte aj všetky jeho variácie.', 'nwr-product-feeds' ),
				'value'         => 'yes' === $value( '_nwr_pf_exclude' ) ? 'yes' : 'no',
				'wrapper_class' => $wrap,
			)
		);

		$feeds = Feeds::all();
		if ( $feeds ) {
			$excluded = $product->get_meta( '_nwr_pf_exclude_feeds', true, 'edit' );
			$excluded = is_array( $excluded ) ? array_map( 'strval', $excluded ) : array();
			echo '<p class="form-field nwr-pf-feedlist ' . esc_attr( $wrap ) . '"><label>' . esc_html__( 'Vylúčiť z feedov', 'nwr-product-feeds' ) . '</label>';
			echo '<input type="hidden" name="' . esc_attr( $name( '_nwr_pf_exclude_feeds' ) ) . '[]" value="">';
			foreach ( $feeds as $feed_id => $feed ) {
				printf(
					'<label class="nwr-pf-inline-check"><input type="checkbox" name="%1$s[]" value="%2$s"%3$s> %4$s</label>',
					esc_attr( $name( '_nwr_pf_exclude_feeds' ) ),
					esc_attr( $feed_id ),
					checked( in_array( (string) $feed_id, $excluded, true ), true, false ),
					esc_html( $feed['name'] )
				);
			}
			echo '</p>';
		}

		woocommerce_wp_text_input(
			array(
				'id'            => $id( '_nwr_pf_title' ),
				'name'          => $name( '_nwr_pf_title' ),
				'label'         => __( 'Názov pre feed', 'nwr-product-feeds' ),
				'value'         => $value( '_nwr_pf_title' ),
				'desc_tip'      => true,
				'description'   => $variation ? __( 'Prázdne = názov produktu + hodnoty atribútov.', 'nwr-product-feeds' ) : __( 'Prázdne = názov produktu. Google zobrazí najviac 150 znakov.', 'nwr-product-feeds' ),
				'wrapper_class' => $wrap,
			)
		);
		woocommerce_wp_textarea_input(
			array(
				'id'            => $id( '_nwr_pf_description' ),
				'name'          => $name( '_nwr_pf_description' ),
				'label'         => __( 'Popis pre feed', 'nwr-product-feeds' ),
				'value'         => $value( '_nwr_pf_description' ),
				'rows'          => 3,
				'desc_tip'      => true,
				'description'   => __( 'Čistý text. Prázdne = popis variácie, krátky popis alebo popis produktu.', 'nwr-product-feeds' ),
				'wrapper_class' => $wrap,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => $id( '_nwr_pf_gtin' ),
				'name'          => $name( '_nwr_pf_gtin' ),
				'label'         => __( 'GTIN pre feed', 'nwr-product-feeds' ),
				'value'         => $value( '_nwr_pf_gtin' ),
				'desc_tip'      => true,
				'description'   => __( 'EAN/UPC s kontrolnou číslicou. Prázdne = pole GTIN, UPC, EAN alebo ISBN zo záložky Sklad.', 'nwr-product-feeds' ),
				'wrapper_class' => $half,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => $id( '_nwr_pf_mpn' ),
				'name'          => $name( '_nwr_pf_mpn' ),
				'label'         => __( 'MPN (kód výrobcu)', 'nwr-product-feeds' ),
				'value'         => $value( '_nwr_pf_mpn' ),
				'desc_tip'      => true,
				'description'   => __( 'Prázdne = vlastné SKU, ak to feed dovoľuje.', 'nwr-product-feeds' ),
				'wrapper_class' => $half2,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => $id( '_nwr_pf_brand' ),
				'name'          => $name( '_nwr_pf_brand' ),
				'label'         => __( 'Značka pre feed', 'nwr-product-feeds' ),
				'value'         => $value( '_nwr_pf_brand' ),
				'desc_tip'      => true,
				'description'   => __( 'Prázdne = značka produktu, atribút značky alebo predvolená značka feedu.', 'nwr-product-feeds' ),
				'wrapper_class' => $half,
			)
		);

		echo '<p class="form-field ' . esc_attr( $half2 ) . '"><label>' . esc_html__( 'Google kategória', 'nwr-product-feeds' ) . '</label>';
		Taxonomy::picker( $name( '_nwr_pf_gpc' ), $value( '_nwr_pf_gpc' ) );
		echo '</p>';

		woocommerce_wp_select(
			array(
				'id'            => $id( '_nwr_pf_gender' ),
				'name'          => $name( '_nwr_pf_gender' ),
				'label'         => __( 'Pohlavie', 'nwr-product-feeds' ),
				'value'         => $value( '_nwr_pf_gender' ),
				'options'       => array(
					''       => __( '— podľa feedu —', 'nwr-product-feeds' ),
					'female' => __( 'ženy', 'nwr-product-feeds' ),
					'male'   => __( 'muži', 'nwr-product-feeds' ),
					'unisex' => __( 'unisex', 'nwr-product-feeds' ),
				),
				'wrapper_class' => $half,
			)
		);
		woocommerce_wp_select(
			array(
				'id'            => $id( '_nwr_pf_age_group' ),
				'name'          => $name( '_nwr_pf_age_group' ),
				'label'         => __( 'Veková skupina', 'nwr-product-feeds' ),
				'value'         => $value( '_nwr_pf_age_group' ),
				'options'       => array(
					''        => __( '— podľa feedu —', 'nwr-product-feeds' ),
					'newborn' => __( 'novorodenci', 'nwr-product-feeds' ),
					'infant'  => __( 'dojčatá', 'nwr-product-feeds' ),
					'toddler' => __( 'batoľatá', 'nwr-product-feeds' ),
					'kids'    => __( 'deti', 'nwr-product-feeds' ),
					'adult'   => __( 'dospelí', 'nwr-product-feeds' ),
				),
				'wrapper_class' => $half2,
			)
		);
		woocommerce_wp_select(
			array(
				'id'            => $id( '_nwr_pf_availability' ),
				'name'          => $name( '_nwr_pf_availability' ),
				'label'         => __( 'Dostupnosť vo feede', 'nwr-product-feeds' ),
				'value'         => $value( '_nwr_pf_availability' ),
				'options'       => array(
					''             => __( '— podľa skladu —', 'nwr-product-feeds' ),
					'in_stock'     => __( 'skladom', 'nwr-product-feeds' ),
					'out_of_stock' => __( 'vypredané', 'nwr-product-feeds' ),
					'preorder'     => __( 'predobjednávka', 'nwr-product-feeds' ),
					'backorder'    => __( 'na objednávku', 'nwr-product-feeds' ),
				),
				'desc_tip'      => true,
				'description'   => __( 'Pri predobjednávke a tovare na objednávku vyplň aj dátum — Google ho vyžaduje.', 'nwr-product-feeds' ),
				'wrapper_class' => $half,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => $id( '_nwr_pf_availability_date' ),
				'name'          => $name( '_nwr_pf_availability_date' ),
				'label'         => __( 'Dostupné od', 'nwr-product-feeds' ),
				'value'         => $value( '_nwr_pf_availability_date' ),
				'type'          => 'date',
				'wrapper_class' => $half2,
			)
		);

		for ( $slot = 0; $slot < Feeds::LABEL_SLOTS; $slot++ ) {
			woocommerce_wp_text_input(
				array(
					'id'                => $id( '_nwr_pf_label_' . $slot ),
					'name'              => $name( '_nwr_pf_label_' . $slot ),
					'label'             => 'custom_label_' . $slot,
					'value'             => $value( '_nwr_pf_label_' . $slot ),
					'custom_attributes' => array( 'maxlength' => '100' ),
					'placeholder'       => __( 'podľa pravidla feedu', 'nwr-product-feeds' ),
					'wrapper_class'     => $variation ? ( 0 === $slot % 2 ? $half : $half2 ) : '',
				)
			);
		}
	}

	public function save_product( $product ): void {
		if ( ! $product instanceof WC_Product || empty( $_POST[ self::MARKER ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verified the product form.
			return;
		}
		$this->apply( $product, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- sanitized per field.
	}

	public function save_variation( $variation, $index ): void {
		$data = $_POST['nwr_pf_var'][ (int) $index ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- WooCommerce verified the request; sanitized per field.
		if ( ! $variation instanceof WC_Product || ! is_array( $data ) || empty( $data[ self::MARKER ] ) ) {
			return;
		}
		$this->apply( $variation, wp_unslash( $data ) );
	}

	/** Sets or clears every field of the tab from submitted values. */
	private function apply( WC_Product $product, array $in ): void {
		$set = static function ( string $key, string $value ) use ( $product ): void {
			if ( '' === $value ) {
				$product->delete_meta_data( $key );
			} else {
				$product->update_meta_data( $key, $value );
			}
		};

		$set( '_nwr_pf_exclude', ! empty( $in['_nwr_pf_exclude'] ) && 'no' !== $in['_nwr_pf_exclude'] ? 'yes' : '' );

		$feeds    = array_keys( Feeds::all() );
		$excluded = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $in['_nwr_pf_exclude_feeds'] ?? array() ) ), $feeds ) );
		if ( $excluded ) {
			$product->update_meta_data( '_nwr_pf_exclude_feeds', $excluded );
		} else {
			$product->delete_meta_data( '_nwr_pf_exclude_feeds' );
		}

		$set( '_nwr_pf_title', mb_substr( sanitize_text_field( (string) ( $in['_nwr_pf_title'] ?? '' ) ), 0, 300 ) );
		$set( '_nwr_pf_description', mb_substr( sanitize_textarea_field( (string) ( $in['_nwr_pf_description'] ?? '' ) ), 0, 9999 ) );
		$set( '_nwr_pf_mpn', mb_substr( sanitize_text_field( (string) ( $in['_nwr_pf_mpn'] ?? '' ) ), 0, 70 ) );
		$set( '_nwr_pf_brand', mb_substr( sanitize_text_field( (string) ( $in['_nwr_pf_brand'] ?? '' ) ), 0, 100 ) );

		$gtin = Gtin::normalize( sanitize_text_field( (string) ( $in['_nwr_pf_gtin'] ?? '' ) ) );
		if ( '' !== $gtin && ! Gtin::valid( $gtin ) ) {
			if ( class_exists( WC_Admin_Meta_Boxes::class ) ) {
				/* translators: %s: GTIN */
				WC_Admin_Meta_Boxes::add_error( sprintf( __( 'Produktové feedy: GTIN %s nemá platnú kontrolnú číslicu, neuložil som ho.', 'nwr-product-feeds' ), $gtin ) );
			}
		} else {
			$set( '_nwr_pf_gtin', $gtin );
		}

		$gpc = preg_match( '/^\s*(\d{1,7})\b/', (string) ( $in['_nwr_pf_gpc'] ?? '' ), $m ) ? $m[1] : '';
		$set( '_nwr_pf_gpc', $gpc );

		$gender = (string) ( $in['_nwr_pf_gender'] ?? '' );
		$set( '_nwr_pf_gender', in_array( $gender, array( 'female', 'male', 'unisex' ), true ) ? $gender : '' );
		$age = (string) ( $in['_nwr_pf_age_group'] ?? '' );
		$set( '_nwr_pf_age_group', in_array( $age, array( 'newborn', 'infant', 'toddler', 'kids', 'adult' ), true ) ? $age : '' );
		$availability = (string) ( $in['_nwr_pf_availability'] ?? '' );
		$set( '_nwr_pf_availability', in_array( $availability, array( 'in_stock', 'out_of_stock', 'preorder', 'backorder' ), true ) ? $availability : '' );
		$date = (string) ( $in['_nwr_pf_availability_date'] ?? '' );
		$set( '_nwr_pf_availability_date', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '' );

		for ( $slot = 0; $slot < Feeds::LABEL_SLOTS; $slot++ ) {
			$set( '_nwr_pf_label_' . $slot, mb_substr( sanitize_text_field( (string) ( $in[ '_nwr_pf_label_' . $slot ] ?? '' ) ), 0, 100 ) );
		}
	}
}
