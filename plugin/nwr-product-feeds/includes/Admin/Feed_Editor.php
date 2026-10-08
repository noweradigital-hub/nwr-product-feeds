<?php
namespace Nowera\ProductFeeds\Admin;

use Nowera\ProductFeeds\Catalog\Attributes;
use Nowera\ProductFeeds\Catalog\Categories;
use Nowera\ProductFeeds\Catalog\Labels;
use Nowera\ProductFeeds\Catalog\Product_Types;
use Nowera\ProductFeeds\Channels\Registry;
use Nowera\ProductFeeds\Feeds;
use Nowera\ProductFeeds\Settings;
use Nowera\ProductFeeds\Storage;

defined( 'ABSPATH' ) || exit;

final class Feed_Editor {

	private array $feed;

	public function render( ?array $feed, string $channel = 'google' ): void {
		$registry = Registry::instance();
		if ( ! $feed ) {
			$channel = $registry->get( $channel ) ? $channel : 'google';
			$feed    = array_merge( Feeds::defaults(), $registry->get( $channel )->defaults(), array( 'channel' => $channel ) );
		}
		$this->feed = $feed;
		$is_new     = '' === $feed['id'];
		$current    = $registry->get( $feed['channel'] );

		$title = $is_new
			/* translators: %s: channel name */
			? sprintf( __( 'Nový feed: %s', 'nwr-product-feeds' ), $current ? $current->label() : $feed['channel'] )
			/* translators: %s: feed name */
			: sprintf( __( 'Upraviť feed: %s', 'nwr-product-feeds' ), $feed['name'] );
		$actions = $is_new ? '' : ' <a class="page-title-action" href="' . esc_url( Admin::url( array( 'view' => 'report', 'feed' => $feed['id'] ) ) ) . '">' . esc_html__( 'Náhľad a report', 'nwr-product-feeds' ) . '</a>';
		Admin::header( $title, 'list', $actions );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="nwr-pf-form">';
		echo '<input type="hidden" name="action" value="nwr_pf_save_feed"><input type="hidden" name="feed" value="' . esc_attr( $feed['id'] ) . '">';
		wp_nonce_field( 'nwr_pf_save_feed' );

		$this->section_basics( $is_new );
		$this->section_products();
		$this->section_content();
		$this->section_attributes();
		$this->section_categories();
		$this->section_labels();
		if ( $current && $current->supports_archive() ) {
			$this->section_meta();
		}
		$this->section_shipping();
		$this->section_advanced( $is_new );

		echo '<p class="submit nwr-pf-submit">';
		echo '<button type="submit" class="button button-primary button-large" name="generate" value="1">' . esc_html__( 'Uložiť a vygenerovať', 'nwr-product-feeds' ) . '</button> ';
		if ( ! $is_new ) {
			echo '<button type="submit" class="button button-large">' . esc_html__( 'Len uložiť', 'nwr-product-feeds' ) . '</button> ';
		}
		echo '<a class="button button-link" href="' . esc_url( Admin::url() ) . '">' . esc_html__( 'Zrušiť', 'nwr-product-feeds' ) . '</a></p>';
		echo '</form>';
	}

	/* ------------------------------------------------------------ sections */

	private function section_basics( bool $is_new ): void {
		$registry = Registry::instance();
		$this->open( __( 'Základ', 'nwr-product-feeds' ) );

		$this->text( 'name', __( 'Názov feedu', 'nwr-product-feeds' ), '', array( 'required' => 'required' ) );

		$channels = array();
		foreach ( $registry->all() as $key => $channel ) {
			$channels[ $key ] = $channel->label();
		}
		$this->select( 'channel', __( 'Šablóna (kanál)', 'nwr-product-feeds' ), $channels, $is_new ? '' : __( 'Zmena šablóny mení hodnoty polí. Po zmene skontroluj náhľad.', 'nwr-product-feeds' ) );

		$current = $registry->get( $this->feed['channel'] );
		$formats = array();
		foreach ( $current ? $current->formats() : array( 'xml' ) as $format ) {
			$formats[ $format ] = 'xml' === $format ? __( 'XML (RSS 2.0) — odporúčané', 'nwr-product-feeds' ) : strtoupper( $format );
		}
		$this->select( 'format', __( 'Formát súboru', 'nwr-product-feeds' ), $formats );

		$slug_help = $is_new
			? __( 'Časť názvu súboru. URL feedu dostane aj náhodný token, aby sa nedala uhádnuť.', 'nwr-product-feeds' )
			: sprintf( /* translators: %s: URL */ __( 'URL: %s — zmena slugu alebo formátu zmení URL.', 'nwr-product-feeds' ), Storage::url( $this->feed ) );
		$this->text( 'slug', __( 'Slug', 'nwr-product-feeds' ), $slug_help );

		$intervals = array();
		foreach ( Feeds::INTERVALS as $minutes ) {
			$intervals[ $minutes ] = Texts::interval( $minutes );
		}
		$this->select( 'interval', __( 'Pravidelné generovanie', 'nwr-product-feeds' ), $intervals, __( 'Zmena produktu, ceny alebo skladu navyše spustí generovanie o pár minút.', 'nwr-product-feeds' ) );

		$countries = array( '' => sprintf( /* translators: %s: country */ __( 'Krajina obchodu (%s)', 'nwr-product-feeds' ), WC()->countries->get_base_country() ) ) + WC()->countries->get_countries();
		$this->select( 'country', __( 'Cieľová krajina', 'nwr-product-feeds' ), $countries, __( 'Podľa nej sa počíta DPH v cene a krajina v poli shipping.', 'nwr-product-feeds' ) );

		$this->checkbox( 'enabled', __( 'Feed je aktívny (pravidelne sa generuje)', 'nwr-product-feeds' ), __( 'Vypnutý feed sa negeneruje, posledný súbor však na URL ostáva, kým feed nevymažeš.', 'nwr-product-feeds' ) );
		$this->close();
	}

	private function section_products(): void {
		$this->open( __( 'Ktoré produkty', 'nwr-product-feeds' ), __( 'Produkty sa čítajú priamo z databázy, takže témy ani pluginy, ktoré menia zoznam produktov (napr. „zobraziť variácie samostatne“), výsledok neovplyvnia.', 'nwr-product-feeds' ) );

		echo '<div class="nwr-pf-field"><span class="nwr-pf-label">' . esc_html__( 'Typy produktov', 'nwr-product-feeds' ) . '</span><div>';
		echo '<input type="hidden" name="feed_data[types][]" value="">';
		foreach ( Product_Types::choices() as $key => [ $label, $count ] ) {
			$note = in_array( $key, Product_Types::BUNDLES, true ) ? ' — ' . __( 'len s pevnou cenou', 'nwr-product-feeds' ) : '';
			printf(
				'<label class="nwr-pf-check"><input type="checkbox" name="feed_data[types][]" value="%1$s"%2$s> %3$s <span class="nwr-pf-muted">(%4$d)%5$s</span></label>',
				esc_attr( $key ),
				checked( in_array( $key, $this->feed['types'], true ), true, false ),
				esc_html( $label ),
				(int) $count,
				esc_html( $note )
			);
		}
		echo '<p class="description">' . esc_html__( 'Variabilný produkt sám vo feede nie je — sú v ňom jeho variácie (item_group_id = ID rodiča). Skrytý variabilný produkt svoje variácie nevyradí.', 'nwr-product-feeds' ) . '</p>';
		echo '</div></div>';

		$this->checkbox( 'out_of_stock', __( 'Zahrnúť vypredané produkty (odporúčané — reklamy sa zastavia samy a párovanie udalostí funguje ďalej)', 'nwr-product-feeds' ) );

		$hidden = array(
			'skip'        => __( 'Vynechať skryté produkty (viditeľnosť „skryté“)', 'nwr-product-feeds' ),
			'skip_search' => __( 'Vynechať skryté aj „len vo vyhľadávaní“', 'nwr-product-feeds' ),
			'include'     => __( 'Zahrnúť aj skryté', 'nwr-product-feeds' ),
		);
		if ( 'meta' === $this->feed['channel'] ) {
			$hidden['archive'] = __( 'Skryté poslať ako archivované (status archived)', 'nwr-product-feeds' );
		}
		$this->select( 'hidden', __( 'Produkty skryté z katalógu', 'nwr-product-feeds' ), $hidden, __( 'Platí pre jednoduché produkty. Variácie skrytého variabilného produktu vo feede ostávajú.', 'nwr-product-feeds' ) );

		echo '<div class="nwr-pf-grid">';
		$this->term_box( 'include_cats', __( 'Len tieto kategórie', 'nwr-product-feeds' ), 'product_cat', __( 'Nič neoznačené = všetky. Podkategórie sa zahrnú automaticky.', 'nwr-product-feeds' ) );
		$this->term_box( 'exclude_cats', __( 'Vylúčiť kategórie', 'nwr-product-feeds' ), 'product_cat', __( 'Vrátane podkategórií.', 'nwr-product-feeds' ) );
		$this->term_box( 'include_tags', __( 'Len tieto štítky', 'nwr-product-feeds' ), 'product_tag', __( 'Nič neoznačené = všetky.', 'nwr-product-feeds' ) );
		$this->term_box( 'exclude_tags', __( 'Vylúčiť štítky', 'nwr-product-feeds' ), 'product_tag', '' );
		echo '</div>';

		echo '<div class="nwr-pf-field"><span class="nwr-pf-label">' . esc_html__( 'Cena od – do', 'nwr-product-feeds' ) . '</span><div>';
		printf( '<input type="text" inputmode="decimal" class="small-text" name="feed_data[price_min]" value="%1$s"> – <input type="text" inputmode="decimal" class="small-text" name="feed_data[price_max]" value="%2$s"> %3$s', esc_attr( $this->feed['price_min'] ), esc_attr( $this->feed['price_max'] ), esc_html( get_woocommerce_currency_symbol() ) );
		echo '<p class="description">' . esc_html__( 'Porovnáva sa výsledná cena položky (akciová, ak práve platí). Prázdne = bez obmedzenia.', 'nwr-product-feeds' ) . '</p></div></div>';

		$this->text( 'exclude_ids', __( 'Vylúčené ID produktov a variácií', 'nwr-product-feeds' ), __( 'Oddelené čiarkou. Jednotlivé produkty sa dajú vylúčiť aj priamo v produkte (záložka Feedy).', 'nwr-product-feeds' ), array(), implode( ', ', $this->feed['exclude_ids'] ) );
		$this->close();
	}

	private function section_content(): void {
		$meta = 'meta' === $this->feed['channel'];
		$this->open( __( 'Obsah položiek', 'nwr-product-feeds' ) );

		$this->checkbox(
			'title_attributes',
			__( 'Názov variácie = názov produktu + hodnoty atribútov („Demo Classic – Modrá“)', 'nwr-product-feeds' ),
			$meta ? __( 'Meta vo svojom návode používa pre varianty rovnaký názov; pri dynamických reklamách je názov s farbou zrozumiteľnejší.', 'nwr-product-feeds' ) : ''
		);
		$this->select(
			'description_order',
			__( 'Zdroj popisu', 'nwr-product-feeds' ),
			array(
				'short' => __( 'Vlastný text pre feed → popis variácie → krátky popis → popis produktu', 'nwr-product-feeds' ),
				'long'  => __( 'Vlastný text pre feed → popis variácie → popis produktu → krátky popis', 'nwr-product-feeds' ),
			),
			__( 'Text sa čistí od HTML a shortcodov. Popis stránky postavenej v Elementore sa nepoužije.', 'nwr-product-feeds' )
		);
		$this->text( 'brand_default', __( 'Predvolená značka', 'nwr-product-feeds' ), __( 'Keď produkt nemá značku (taxonómia Značky alebo atribút). Google ju povoľuje len pri vlastnej výrobe alebo privátnej značke — nie „N/A“ ani „Generic“.', 'nwr-product-feeds' ) );
		$this->select(
			'condition',
			__( 'Stav tovaru', 'nwr-product-feeds' ),
			array(
				'new'         => __( 'nový (new)', 'nwr-product-feeds' ),
				'refurbished' => __( 'repasovaný (refurbished)', 'nwr-product-feeds' ),
				'used'        => __( 'použitý (used)', 'nwr-product-feeds' ),
			)
		);
		$this->select(
			'price_tax',
			__( 'Cena', 'nwr-product-feeds' ),
			array(
				'incl' => __( 's DPH (Slovensko, EÚ)', 'nwr-product-feeds' ),
				'excl' => __( 'bez DPH (napr. USA)', 'nwr-product-feeds' ),
			)
		);
		$this->number( 'images_max', __( 'Najviac ďalších obrázkov', 'nwr-product-feeds' ), 0, 20, $meta ? __( '0 = limit platformy (Meta 20).', 'nwr-product-feeds' ) : __( '0 = limit platformy (Google 10).', 'nwr-product-feeds' ) );
		$this->text( 'utm', __( 'UTM parametre v odkaze', 'nwr-product-feeds' ), __( 'Voliteľné, napr. utm_source=google&utm_medium=product_feed. Parametre add-to-cart sa nepridávajú nikdy.', 'nwr-product-feeds' ) );

		$this->checkbox( 'mpn_from_sku', __( 'MPN = vlastné SKU produktu', 'nwr-product-feeds' ), __( 'Len ak ste výrobca (SKU je kód výrobcu). Variácia bez vlastného SKU MPN nedostane.', 'nwr-product-feeds' ) );
		$this->checkbox( 'gtin_from_parent', __( 'Variácii bez GTIN dať GTIN rodiča', 'nwr-product-feeds' ), __( 'Neodporúčané: Google hlási duplicitný GTIN — každý variant má mať vlastný.', 'nwr-product-feeds' ) );

		echo '<div class="nwr-pf-field"><span class="nwr-pf-label">' . esc_html__( 'Tovar na objednávku (backorder)', 'nwr-product-feeds' ) . '</span><div>';
		$this->bare_select(
			'backorder',
			array(
				'backorder' => $meta ? __( 'available for order (na objednávku)', 'nwr-product-feeds' ) : __( 'backorder s dátumom dostupnosti', 'nwr-product-feeds' ),
				'in_stock'  => $meta ? __( 'in stock (skladom)', 'nwr-product-feeds' ) : __( 'in_stock (skladom)', 'nwr-product-feeds' ),
			)
		);
		if ( $meta ) {
			echo '<p class="description">' . esc_html__( 'Počet kusov (quantity_to_sell_on_facebook) sa pri tovare na objednávku neposiela. Ak by Commerce Manager hodnotu „available for order“ odmietol, prepnite na in stock.', 'nwr-product-feeds' ) . '</p>';
		} else {
			printf( ' <label>%1$s <input type="number" class="small-text" min="1" max="365" name="feed_data[backorder_days]" value="%2$d"> %3$s</label>', esc_html__( 'dostupné o', 'nwr-product-feeds' ), (int) $this->feed['backorder_days'], esc_html__( 'dní', 'nwr-product-feeds' ) );
			echo '<p class="description">' . esc_html__( 'Google pri backorder a preorder vyžaduje availability_date. Presný dátum sa dá zadať pri produkte.', 'nwr-product-feeds' ) . '</p>';
		}
		echo '</div></div>';

		$this->checkbox(
			'extra_attributes',
			$meta ? __( 'Ostatné atribúty variácie posielať ako additional_variant_attribute', 'nwr-product-feeds' ) : __( 'Ostatné atribúty variácie posielať ako variant_option', 'nwr-product-feeds' ),
			__( 'Atribúty, ktoré nie sú farba, veľkosť, materiál, vzor, pohlavie ani vek (napr. Nosnosť).', 'nwr-product-feeds' )
		);
		$this->close();
	}

	private function section_attributes(): void {
		$this->open( __( 'Atribúty produktov', 'nwr-product-feeds' ), __( '„Automaticky“ skúša bežné názvy (napr. farba: pa_color, pa_farba, pa_barva). Hodnota variácie má prednosť pred hodnotou produktu.', 'nwr-product-feeds' ) );
		$labels  = array(
			'brand'     => __( 'Značka (brand)', 'nwr-product-feeds' ),
			'color'     => __( 'Farba (color)', 'nwr-product-feeds' ),
			'size'      => __( 'Veľkosť (size)', 'nwr-product-feeds' ),
			'material'  => __( 'Materiál (material)', 'nwr-product-feeds' ),
			'pattern'   => __( 'Vzor (pattern)', 'nwr-product-feeds' ),
			'gender'    => __( 'Pohlavie (gender)', 'nwr-product-feeds' ),
			'age_group' => __( 'Veková skupina (age_group)', 'nwr-product-feeds' ),
		);
		$choices = array(
			'auto' => __( 'Automaticky', 'nwr-product-feeds' ),
			''     => __( '— nepoužívať —', 'nwr-product-feeds' ),
		) + Attributes::choices();

		echo '<table class="widefat nwr-pf-map"><tbody>';
		foreach ( $labels as $field => $label ) {
			$value = (string) ( $this->feed['attribute_map'][ $field ] ?? 'auto' );
			$list  = $choices;
			if ( str_starts_with( $value, 'local:' ) ) {
				/* translators: %s: attribute name */
				$list[ $value ] = sprintf( __( 'Vlastný atribút produktu: %s', 'nwr-product-feeds' ), substr( $value, 6 ) );
			}
			echo '<tr><th>' . esc_html( $label ) . '</th><td><select name="feed_data[attribute_map][' . esc_attr( $field ) . ']">';
			foreach ( $list as $key => $name ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $key ), selected( $value, (string) $key, false ), esc_html( $name . ( str_starts_with( (string) $key, 'pa_' ) ? ' (' . $key . ')' : '' ) ) );
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';

		$genders = array(
			''       => __( '— nevyplniť —', 'nwr-product-feeds' ),
			'female' => __( 'ženy (female)', 'nwr-product-feeds' ),
			'male'   => __( 'muži (male)', 'nwr-product-feeds' ),
			'unisex' => __( 'unisex', 'nwr-product-feeds' ),
		);
		$ages    = array(
			''         => __( '— nevyplniť —', 'nwr-product-feeds' ),
			'newborn'  => __( 'novorodenci (newborn, do 3 mesiacov)', 'nwr-product-feeds' ),
			'infant'   => __( 'dojčatá (infant, 3–12 mesiacov)', 'nwr-product-feeds' ),
			'toddler'  => __( 'batoľatá (toddler, 1–5 rokov)', 'nwr-product-feeds' ),
			'kids'     => __( 'deti (kids, 5–13 rokov)', 'nwr-product-feeds' ),
			'adult'    => __( 'dospelí (adult)', 'nwr-product-feeds' ),
		);
		if ( 'meta' === $this->feed['channel'] ) {
			$ages['teen']     = __( 'tínedžeri (teen, len Meta)', 'nwr-product-feeds' );
			$ages['all ages'] = __( 'všetky veky (all ages, len Meta)', 'nwr-product-feeds' );
		}
		$this->select( 'gender_default', __( 'Predvolené pohlavie', 'nwr-product-feeds' ), $genders, __( 'Keď ho produkt nemá ani v atribúte, ani v záložke Feedy.', 'nwr-product-feeds' ) );
		$this->select( 'age_group_default', __( 'Predvolená veková skupina', 'nwr-product-feeds' ), $ages );
		$this->close();
	}

	private function section_categories(): void {
		$meta = 'meta' === $this->feed['channel'];
		$this->open(
			__( 'Kategórie', 'nwr-product-feeds' ),
			__( 'product_type sa tvorí z cesty kategórie (primárna kategória z Yoastu má prednosť). Google kategóriu priraď kategórii — platí aj pre jej podkategórie.', 'nwr-product-feeds' )
		);

		echo '<div class="nwr-pf-field"><span class="nwr-pf-label">' . esc_html__( 'Predvolená Google kategória', 'nwr-product-feeds' ) . '</span><div>';
		Taxonomy::picker( 'feed_data[gpc_default]', (string) $this->feed['gpc_default'] );
		echo '</div></div>';
		if ( $meta ) {
			$this->text( 'fbc_default', __( 'Predvolená Facebook kategória (ID)', 'nwr-product-feeds' ), __( 'Voliteľné. ID zo zoznamu facebook.com/products/categories/sk_SK.txt.', 'nwr-product-feeds' ) );
		}

		$tree = Categories::tree();
		if ( ! $tree ) {
			echo '<p>' . esc_html__( 'Obchod nemá žiadne kategórie.', 'nwr-product-feeds' ) . '</p>';
			$this->close();
			return;
		}
		echo '<input type="hidden" name="feed_data[gpc_map][0]" value="">';
		if ( $meta ) {
			echo '<input type="hidden" name="feed_data[fbc_map][0]" value="">';
		}
		echo '<table class="widefat striped nwr-pf-map"><thead><tr><th>' . esc_html__( 'Kategória obchodu', 'nwr-product-feeds' ) . '</th><th>' . esc_html__( 'Google kategória', 'nwr-product-feeds' ) . '</th>';
		if ( $meta ) {
			echo '<th>' . esc_html__( 'Facebook kategória (ID)', 'nwr-product-feeds' ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $tree as [ $term, $depth ] ) {
			echo '<tr><td>' . esc_html( str_repeat( '— ', $depth ) . $term->name ) . ' <span class="nwr-pf-muted">(' . (int) $term->count . ')</span></td><td>';
			Taxonomy::picker( 'feed_data[gpc_map][' . (int) $term->term_id . ']', (string) ( $this->feed['gpc_map'][ $term->term_id ] ?? '' ) );
			echo '</td>';
			if ( $meta ) {
				printf( '<td><input type="text" class="regular-text" name="feed_data[fbc_map][%1$d]" value="%2$s"></td>', (int) $term->term_id, esc_attr( (string) ( $this->feed['fbc_map'][ $term->term_id ] ?? '' ) ) );
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		$this->close();
	}

	private function section_labels(): void {
		$this->open( __( 'Vlastné štítky (custom_label_0–4)', 'nwr-product-feeds' ), __( 'Na delenie produktov v kampaniach. Hodnota zadaná pri produkte má prednosť. Max. 100 znakov.', 'nwr-product-feeds' ) );
		$rules = Labels::rules();
		echo '<table class="widefat nwr-pf-map"><tbody>';
		foreach ( $this->feed['labels'] as $slot => $label ) {
			echo '<tr><th>custom_label_' . (int) $slot . '</th><td><select name="feed_data[labels][' . (int) $slot . '][rule]">';
			foreach ( $rules as $key => $name ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $key ), selected( $label['rule'], $key, false ), esc_html( $name ) );
			}
			printf( '</select></td><td><input type="text" class="regular-text" placeholder="%1$s" name="feed_data[labels][%2$d][param]" value="%3$s"></td></tr>', esc_attr__( 'parameter', 'nwr-product-feeds' ), (int) $slot, esc_attr( $label['param'] ) );
		}
		echo '</tbody></table>';
		$this->close();
	}

	private function section_meta(): void {
		$this->open( __( 'Meta', 'nwr-product-feeds' ) );
		$this->checkbox( 'meta_quantity', __( 'Posielať quantity_to_sell_on_facebook (pri produktoch so sledovaným skladom)', 'nwr-product-feeds' ), __( 'Používa ho len obchod na Facebooku/Instagrame s nákupom priamo na platforme.', 'nwr-product-feeds' ) );
		$this->select(
			'internal_label',
			__( 'internal_label', 'nwr-product-feeds' ),
			array(
				''           => __( '— neposielať —', 'nwr-product-feeds' ),
				'tags'       => __( 'štítky produktu (tag_…)', 'nwr-product-feeds' ),
				'categories' => __( 'kategórie produktu (cat_…)', 'nwr-product-feeds' ),
				'both'       => __( 'kategórie aj štítky', 'nwr-product-feeds' ),
			),
			__( 'Meta ich odporúča na produktové sady (ich zmena nespúšťa kontrolu reklám).', 'nwr-product-feeds' )
		);
		$this->close();
	}

	private function section_shipping(): void {
		$this->open( __( 'Doprava (voliteľné)', 'nwr-product-feeds' ), __( 'Dopravu je lepšie nastaviť v Merchant Center a v Commerce Manageri. Pole shipping sa pošle, len ak vyplníš cenu.', 'nwr-product-feeds' ) );
		$this->text( 'shipping_price', __( 'Cena dopravy', 'nwr-product-feeds' ), __( 'S DPH, napr. 3.90. Prázdne = bez poľa shipping.', 'nwr-product-feeds' ) );
		$this->text( 'shipping_service', __( 'Názov služby', 'nwr-product-feeds' ), __( 'Napr. Kuriér. Voliteľné.', 'nwr-product-feeds' ) );
		$this->checkbox( 'shipping_weight', __( 'Posielať hmotnosť (shipping_weight)', 'nwr-product-feeds' ) );
		$this->close();
	}

	private function section_advanced( bool $is_new ): void {
		$this->open( __( 'Pokročilé', 'nwr-product-feeds' ) );
		$this->text(
			'id_template',
			__( 'Vlastná šablóna ID pre tento feed', 'nwr-product-feeds' ),
			sprintf(
				/* translators: %s: template */
				__( 'Prázdne = spoločná šablóna %s (rovnaké ID posiela aj meranie). Vyplň len pre katalóg so starými ID, napr. wc_{id} — meranie potom s týmto feedom nesedí. Premenné: {id}, {sku}, {parent_id}, {parent_sku}.', 'nwr-product-feeds' ),
				Settings::get( 'id_template' )
			)
		);
		$this->number( 'drop_guard', __( 'Poistka pri poklese položiek (%)', 'nwr-product-feeds' ), 0, 100, __( 'Ak má nový súbor o toľko percent menej položiek ako predchádzajúci, nezverejní sa a počká na potvrdenie (Meta by chýbajúce produkty z katalógu zmazala). 0 = vypnuté.', 'nwr-product-feeds' ) );
		if ( ! $is_new ) {
			echo '<p><a class="button" data-confirm="' . esc_attr__( 'Vytvoriť novú URL? Stará prestane fungovať po najbližšom generovaní a treba ju vymeniť v Merchant Center / Commerce Manageri.', 'nwr-product-feeds' ) . '" href="' . esc_url( Admin::action_url( 'rotate_token', array( 'feed' => $this->feed['id'] ) ) ) . '">' . esc_html__( 'Zmeniť URL (nový token)', 'nwr-product-feeds' ) . '</a></p>';
		}
		$this->close();
	}

	/* ------------------------------------------------------------- helpers */

	private function open( string $title, string $intro = '' ): void {
		echo '<div class="nwr-pf-card"><h2>' . esc_html( $title ) . '</h2>';
		if ( '' !== $intro ) {
			echo '<p class="nwr-pf-intro">' . esc_html( $intro ) . '</p>';
		}
	}

	private function close(): void {
		echo '</div>';
	}

	private function name( string $key ): string {
		return 'feed_data[' . $key . ']';
	}

	private function text( string $key, string $label, string $help = '', array $attrs = array(), ?string $value = null ): void {
		$extra = '';
		foreach ( $attrs as $attr => $attr_value ) {
			$extra .= ' ' . esc_attr( $attr ) . '="' . esc_attr( $attr_value ) . '"';
		}
		printf(
			'<div class="nwr-pf-field"><label class="nwr-pf-label" for="nwr-pf-%1$s">%2$s</label><div><input type="text" class="regular-text" id="nwr-pf-%1$s" name="%3$s" value="%4$s"%5$s>%6$s</div></div>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $this->name( $key ) ),
			esc_attr( $value ?? (string) $this->feed[ $key ] ),
			$extra, // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
			'' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	private function number( string $key, string $label, int $min, int $max, string $help = '' ): void {
		printf(
			'<div class="nwr-pf-field"><label class="nwr-pf-label" for="nwr-pf-%1$s">%2$s</label><div><input type="number" class="small-text" id="nwr-pf-%1$s" name="%3$s" min="%4$d" max="%5$d" value="%6$d">%7$s</div></div>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $this->name( $key ) ),
			$min,
			$max,
			(int) $this->feed[ $key ],
			'' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	private function checkbox( string $key, string $label, string $help = '' ): void {
		printf(
			'<div class="nwr-pf-field nwr-pf-field--check"><span class="nwr-pf-label"></span><div><input type="hidden" name="%1$s" value="0"><label><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label>%4$s</div></div>',
			esc_attr( $this->name( $key ) ),
			checked( ! empty( $this->feed[ $key ] ), true, false ),
			esc_html( $label ),
			'' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	private function select( string $key, string $label, array $options, string $help = '' ): void {
		printf( '<div class="nwr-pf-field"><label class="nwr-pf-label" for="nwr-pf-%1$s">%2$s</label><div>', esc_attr( $key ), esc_html( $label ) );
		$this->bare_select( $key, $options );
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</div></div>';
	}

	private function bare_select( string $key, array $options ): void {
		printf( '<select id="nwr-pf-%1$s" name="%2$s">', esc_attr( $key ), esc_attr( $this->name( $key ) ) );
		foreach ( $options as $value => $name ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $value ), selected( (string) $this->feed[ $key ], (string) $value, false ), esc_html( (string) $name ) );
		}
		echo '</select>';
	}

	private function term_box( string $key, string $label, string $taxonomy, string $help ): void {
		$selected = array_map( 'intval', (array) $this->feed[ $key ] );
		echo '<div class="nwr-pf-terms"><span class="nwr-pf-label">' . esc_html( $label ) . '</span>';
		echo '<input type="hidden" name="' . esc_attr( $this->name( $key ) ) . '[]" value="0"><div class="nwr-pf-termlist">';
		$tree = Categories::tree( $taxonomy );
		if ( ! $tree ) {
			echo '<span class="nwr-pf-muted">' . esc_html__( 'Žiadne.', 'nwr-product-feeds' ) . '</span>';
		}
		foreach ( $tree as [ $term, $depth ] ) {
			printf(
				'<label style="padding-left:%1$dpx"><input type="checkbox" name="%2$s[]" value="%3$d"%4$s> %5$s <span class="nwr-pf-muted">(%6$d)</span></label>',
				$depth * 14,
				esc_attr( $this->name( $key ) ),
				(int) $term->term_id,
				checked( in_array( (int) $term->term_id, $selected, true ), true, false ),
				esc_html( $term->name ),
				(int) $term->count
			);
		}
		echo '</div>';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</div>';
	}
}
