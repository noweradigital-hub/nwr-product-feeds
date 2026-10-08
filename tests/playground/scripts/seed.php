<?php
// Builds the test catalog: every case from ZADANIE §1, §5 and §7. Idempotent.
require __DIR__ . '/_bootstrap.php';

$existing = nwr_ids();
if ( $existing && wc_get_product( $existing['simple'] ?? 0 ) && empty( $_GET['fresh'] ) ) {
	nwr_out( $existing );
	exit;
}

// SK VAT 23 %, prices entered with tax.
global $wpdb;
if ( ! $wpdb->get_var( "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_country = 'SK'" ) ) {
	WC_Tax::_insert_tax_rate(
		array(
			'tax_rate_country'  => 'SK',
			'tax_rate'          => '23.0000',
			'tax_rate_name'     => 'DPH',
			'tax_rate_priority' => 1,
			'tax_rate_compound' => 0,
			'tax_rate_shipping' => 1,
			'tax_rate_order'    => 0,
			'tax_rate_class'    => '',
		)
	);
}

const NWR_JPEG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

function nwr_image( string $name, string $ext = 'jpg' ): int {
	$upload = wp_upload_dir();
	wp_mkdir_p( $upload['path'] );
	$path = $upload['path'] . '/' . $name . '.' . $ext;
	file_put_contents( $path, base64_decode( NWR_JPEG ) );
	return (int) wp_insert_attachment(
		array(
			'post_mime_type' => 'webp' === $ext ? 'image/webp' : 'image/jpeg',
			'post_title'     => $name,
			'post_status'    => 'inherit',
		),
		$path
	);
}

function nwr_term( string $name, string $taxonomy, int $parent = 0, string $slug = '' ): int {
	$found = term_exists( $slug ?: $name, $taxonomy, $parent ?: null );
	if ( $found ) {
		return (int) $found['term_id'];
	}
	$args = array( 'parent' => $parent );
	if ( $slug ) {
		$args['slug'] = $slug;
	}
	$term = wp_insert_term( $name, $taxonomy, $args );
	return is_wp_error( $term ) ? 0 : (int) $term['term_id'];
}

function nwr_attribute( string $name, string $slug, array $terms ): array {
	$id = wc_attribute_taxonomy_id_by_name( $slug );
	if ( ! $id ) {
		$id = wc_create_attribute( array( 'name' => $name, 'slug' => $slug, 'type' => 'select' ) );
	}
	$taxonomy = wc_attribute_taxonomy_name( $slug );
	if ( ! taxonomy_exists( $taxonomy ) ) {
		register_taxonomy( $taxonomy, array( 'product' ) );
	}
	$ids = array();
	foreach ( $terms as $term_slug => $term_name ) {
		$ids[ $term_slug ] = nwr_term( $term_name, $taxonomy, 0, $term_slug );
	}
	return array( 'id' => (int) $id, 'taxonomy' => $taxonomy, 'terms' => $ids );
}

function nwr_attr( array $attribute, array $slugs, bool $variation ): WC_Product_Attribute {
	$a = new WC_Product_Attribute();
	$a->set_id( $attribute['id'] );
	$a->set_name( $attribute['taxonomy'] );
	$a->set_options( array_values( array_intersect_key( $attribute['terms'], array_flip( $slugs ) ) ) );
	$a->set_visible( true );
	$a->set_variation( $variation );
	return $a;
}

function nwr_simple( string $name, array $props, string $class = 'WC_Product_Simple' ): WC_Product {
	$product = new $class();
	$product->set_name( $name );
	$product->set_props( $props );
	$product->save();
	return $product;
}

// Taxonomies.
$vaky    = nwr_term( 'Detské vaky', 'product_cat' );
$zimne   = nwr_term( 'Zimné', 'product_cat', $vaky );
$deky    = nwr_term( 'Deky & prikrývky', 'product_cat' );
$doplnky = nwr_term( 'Doplnky', 'product_cat' );
$novinka = nwr_term( 'Novinka', 'product_tag' );
$best    = nwr_term( 'Bestseller', 'product_tag' );
$brand   = taxonomy_exists( 'product_brand' ) ? nwr_term( 'DEMO', 'product_brand' ) : 0;

$farba    = nwr_attribute( 'Farba', 'farba', array( 'modra' => 'Modrá', 'ruzova' => 'Ružová', 'siva' => 'Sivá' ) );
$material = nwr_attribute( 'Materiál', 'material', array( 'bavlna' => 'Bavlna', 'merino' => 'Merino' ) );
$nosnost  = nwr_attribute( 'Nosnosť', 'nosnost', array( '10-kg' => '10 kg', '15-kg' => '15 kg' ) );

$img = array();
foreach ( array( 'osuska', 'osuska-2', 'osuska-3', 'deka', 'ciapka', 'skryty', 'bez-ceny', 'heslo', 'koncept', 'elem-a', 'elem-b', 'special', 'yoast', 'vylucene', 'meta-only', 'classic', 'classic-2', 'classic-modra', 'rast', 'rast-gal', 'composite', 'composite-0', 'composite-dyn', 'giftcard', 'external', 'grouped', 'webp-src', 'invalid-gtin', 'vg-extra' ) as $name ) {
	$img[ $name ] = nwr_image( $name );
}
$img['webp'] = nwr_image( 'obrazok-webp', 'webp' );

$ids = array( 'cats' => compact( 'vaky', 'zimne', 'deky', 'doplnky' ), 'tags' => array( 'novinka' => $novinka, 'bestseller' => $best ), 'brand' => $brand );

// 1. Simple with GTIN, own SKU, brand, gallery, HTML + shortcode description.
$p = nwr_simple(
	'Detská osuška',
	array(
		'sku'               => 'DM-OSU-1',
		'regular_price'     => '24.90',
		'category_ids'      => array( $doplnky ),
		'tag_ids'           => array( $novinka ),
		'image_id'          => $img['osuska'],
		'gallery_image_ids' => array( $img['osuska-2'], $img['osuska-3'] ),
		'short_description' => '<p>Mäkká <strong>osuška</strong> &amp; uterák [vc_row]pre deti[/vc_row]</p><ul><li>100 % bavlna</li><li>70 × 140 cm</li></ul>',
		'description'       => '<p>Dlhý popis osušky.</p>',
		'global_unique_id'  => '4006381333931',
		'weight'            => '0.4',
	)
);
if ( $brand ) {
	wp_set_object_terms( $p->get_id(), array( $brand ), 'product_brand' );
}
$ids['simple'] = $p->get_id();

// 2. Sale with dates.
$ids['sale'] = nwr_simple(
	'Deka Merino',
	array(
		'regular_price'     => '59.90',
		'sale_price'        => '49.90',
		'date_on_sale_from' => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
		'date_on_sale_to'   => gmdate( 'Y-m-d', strtotime( '+10 days' ) ),
		'category_ids'      => array( $deky ),
		'tag_ids'           => array( $best ),
		'image_id'          => $img['deka'],
		'short_description' => 'Hrejivá deka z merino vlny pre bábätká aj batoľatá.',
	)
)->get_id();

// 3. Out of stock (stays in the feed).
$ids['oos'] = nwr_simple(
	'Čiapka',
	array(
		'regular_price'     => '15',
		'manage_stock'      => true,
		'stock_quantity'    => 0,
		'category_ids'      => array( $doplnky ),
		'image_id'          => $img['ciapka'],
		'short_description' => 'Pletená detská čiapka z merino vlny, teplá a mäkká.',
	)
)->get_id();

// 4.–8. Left out: hidden, no price, no image, password, draft.
$ids['hidden']   = nwr_simple( 'Skrytý produkt', array( 'regular_price' => '10', 'catalog_visibility' => 'hidden', 'image_id' => $img['skryty'], 'category_ids' => array( $doplnky ) ) )->get_id();
$ids['noprice']  = nwr_simple( 'Bez ceny', array( 'image_id' => $img['bez-ceny'], 'category_ids' => array( $doplnky ) ) )->get_id();
$ids['noimage']  = nwr_simple( 'Bez obrázka', array( 'regular_price' => '12', 'category_ids' => array( $doplnky ) ) )->get_id();
$ids['password'] = nwr_simple( 'Zaheslovaný', array( 'regular_price' => '12', 'image_id' => $img['heslo'], 'post_password' => 'tajne' ) )->get_id();
$ids['draft']    = nwr_simple( 'Koncept', array( 'regular_price' => '12', 'image_id' => $img['koncept'], 'status' => 'draft' ) )->get_id();

// 9. Elementor pages: content is a builder dump, not a description.
$ea = nwr_simple( 'Elementor bez krátkeho popisu', array( 'regular_price' => '30', 'image_id' => $img['elem-a'], 'description' => '[elementor-template id="5"]<div class="elementor">Hlavička webu Pridať do košíka</div>' ) );
update_post_meta( $ea->get_id(), '_elementor_edit_mode', 'builder' );
$eb = nwr_simple( 'Elementor s krátkym popisom', array( 'regular_price' => '30', 'image_id' => $img['elem-b'], 'short_description' => 'Krátky popis produktu, ktorý postavili v Elementore.', 'description' => '<div class="elementor">Hlavička webu</div>' ) );
update_post_meta( $eb->get_id(), '_elementor_edit_mode', 'builder' );
$ids['elementor_a'] = $ea->get_id();
$ids['elementor_b'] = $eb->get_id();

// 10. Characters XML does not like.
$ids['special'] = nwr_simple(
	'Vak "Classic" & <b>Plus</b> 😀',
	array(
		'regular_price'     => '45.50',
		'image_id'          => $img['special'],
		'short_description' => "Text s \x0B riadiacim znakom, &amp; entitou, <script>alert(1)</script> a [neznamy_shortcode id=\"1\"] shortcodom. [2 ks] ostáva.",
		'category_ids'      => array( $vaky ),
	)
)->get_id();

// 11. Yoast primary category decides product_type.
$ids['yoast'] = nwr_simple( 'Produkt s primárnou kategóriou', array( 'regular_price' => '20', 'image_id' => $img['yoast'], 'category_ids' => array( $vaky, $zimne, $deky ), 'short_description' => 'Produkt zaradený vo viacerých kategóriách naraz.' ) )->get_id();
update_post_meta( $ids['yoast'], '_yoast_wpseo_primary_product_cat', $deky );

// 12. Excluded everywhere; 13. excluded only from the Meta feed (set by feeds.php).
$ids['excluded']  = nwr_simple( 'Vylúčený zo všetkých', array( 'regular_price' => '20', 'image_id' => $img['vylucene'] ) )->get_id();
update_post_meta( $ids['excluded'], '_nwr_pf_exclude', 'yes' );
$ids['meta_only_excluded'] = nwr_simple( 'Vylúčený len z Meta', array( 'regular_price' => '20', 'image_id' => $img['meta-only'], 'short_description' => 'Tento produkt ide do Google, ale nie do Meta katalógu.' ) )->get_id();

// 14. Variable product hidden from the catalog; variations are listed on their own.
$classic = new WC_Product_Variable();
$classic->set_props(
	array(
		'name'               => 'Demo Classic',
		'sku'                => 'DM-CL',
		'catalog_visibility' => 'hidden',
		'category_ids'       => array( $zimne ),
		'image_id'           => $img['classic'],
		'gallery_image_ids'  => array( $img['classic-2'] ),
		'short_description'  => 'Zimný spací vak pre deti s odopínateľnými rukávmi.',
		'global_unique_id'   => '4006381333924',
	)
);
$classic->set_attributes( array( nwr_attr( $farba, array( 'modra', 'ruzova', 'siva' ), true ), nwr_attr( $material, array( 'merino' ), false ) ) );
$classic->save();
if ( $brand ) {
	wp_set_object_terms( $classic->get_id(), array( $brand ), 'product_brand' );
}
$v1 = new WC_Product_Variation();
$v1->set_props( array( 'parent_id' => $classic->get_id(), 'regular_price' => '89', 'sku' => 'DM-CL-M', 'image_id' => $img['classic-modra'], 'global_unique_id' => '4006381333900' ) );
$v1->set_attributes( array( 'pa_farba' => 'modra' ) );
$v1->save();
update_post_meta( $v1->get_id(), 'wd_additional_variation_images_data', (string) $img['vg-extra'] );
$v2 = new WC_Product_Variation();
$v2->set_props( array( 'parent_id' => $classic->get_id(), 'regular_price' => '89', 'sale_price' => '79' ) );
$v2->set_attributes( array( 'pa_farba' => 'ruzova' ) );
$v2->save();
$v3 = new WC_Product_Variation();
$v3->set_props( array( 'parent_id' => $classic->get_id(), 'regular_price' => '89', 'status' => 'private' ) );
$v3->set_attributes( array( 'pa_farba' => 'siva' ) );
$v3->save();
WC_Product_Variable::sync( $classic->get_id() );
$ids['classic']            = $classic->get_id();
$ids['classic_modra']      = $v1->get_id();
$ids['classic_ruzova']     = $v2->get_id();
$ids['classic_siva_off']   = $v3->get_id();

// 15. Visible variable product; variations without SKU, stock, backorder and an "any" value.
$rast = new WC_Product_Variable();
$rast->set_props(
	array(
		'name'              => 'Rastúci Demo',
		'sku'               => 'DM-RAST',
		'category_ids'      => array( $vaky ),
		'tag_ids'           => array( $novinka ),
		'image_id'          => $img['rast'],
		'gallery_image_ids' => array( $img['rast-gal'] ),
		'description'       => '<h2>Rastúci vak</h2><p>Vak, ktorý rastie s dieťaťom.</p>',
	)
);
$rast->set_attributes( array( nwr_attr( $farba, array( 'modra', 'siva' ), true ), nwr_attr( $nosnost, array( '10-kg', '15-kg' ), true ) ) );
$rast->save();
$r1 = new WC_Product_Variation();
$r1->set_props( array( 'parent_id' => $rast->get_id(), 'regular_price' => '99', 'manage_stock' => true, 'stock_quantity' => 5 ) );
$r1->set_attributes( array( 'pa_farba' => 'modra', 'pa_nosnost' => '10-kg' ) );
$r1->save();
$r2 = new WC_Product_Variation();
$r2->set_props( array( 'parent_id' => $rast->get_id(), 'regular_price' => '109', 'stock_status' => 'onbackorder' ) );
$r2->set_attributes( array( 'pa_farba' => 'siva', 'pa_nosnost' => '15-kg' ) );
$r2->save();
$r3 = new WC_Product_Variation();
$r3->set_props( array( 'parent_id' => $rast->get_id(), 'regular_price' => '109', 'description' => '<p>Popis <em>variácie</em> pre ľubovoľnú farbu.</p>' ) );
$r3->set_attributes( array( 'pa_farba' => '', 'pa_nosnost' => '15-kg' ) );
$r3->save();
WC_Product_Variable::sync( $rast->get_id() );
$ids['rast']     = $rast->get_id();
$ids['rast_1']   = $r1->get_id();
$ids['rast_2']   = $r2->get_id();
$ids['rast_any'] = $r3->get_id();

// 16. Variable product without variations.
$empty = new WC_Product_Variable();
$empty->set_props( array( 'name' => 'Prázdny variabilný', 'image_id' => $img['rast'] ) );
$empty->save();
$ids['variable_empty'] = $empty->get_id();

// 17.–19. Composite: fixed price, zero price, price from components.
$ids['composite']       = nwr_simple( 'Set Basic', array( 'regular_price' => '120', 'image_id' => $img['composite'], 'category_ids' => array( $deky ), 'short_description' => 'Zvýhodnený set: vak, deka a osuška za pevnú cenu.' ), 'WC_Product_Composite' )->get_id();
$ids['composite_zero']  = nwr_simple( 'Set bez ceny', array( 'image_id' => $img['composite-0'], 'category_ids' => array( $deky ) ), 'WC_Product_Composite' )->get_id();
$ids['composite_dyn']   = nwr_simple( 'Set podľa výberu', array( 'regular_price' => '50', 'image_id' => $img['composite-dyn'], 'category_ids' => array( $deky ) ), 'WC_Product_Composite' )->get_id();
update_post_meta( $ids['composite_dyn'], 'wooco_pricing', 'exclude' );

// 20.–22. Gift card, external, grouped.
$ids['giftcard'] = nwr_simple( 'Darčeková karta', array( 'regular_price' => '50', 'image_id' => $img['giftcard'] ), 'WC_Product_Gift_Card' )->get_id();
$ids['external'] = nwr_simple( 'Externý produkt', array( 'regular_price' => '30', 'image_id' => $img['external'], 'product_url' => 'https://example.com/' ), 'WC_Product_External' )->get_id();
$ids['grouped']  = nwr_simple( 'Skupina', array( 'image_id' => $img['grouped'], 'children' => array( $ids['simple'] ) ), 'WC_Product_Grouped' )->get_id();

// 23. WebP main image (Meta wants JPEG/PNG); 24. invalid GTIN.
$ids['webp']         = nwr_simple( 'Produkt s WebP', array( 'regular_price' => '18', 'image_id' => $img['webp'], 'short_description' => 'Obrázok tohto produktu je vo formáte WebP.' ) )->get_id();
$ids['invalid_gtin'] = nwr_simple( 'Zlý EAN', array( 'regular_price' => '18', 'image_id' => $img['invalid-gtin'], 'short_description' => 'Produkt s EAN kódom, ktorý nemá správnu kontrolnú číslicu.' ) )->get_id();
update_post_meta( $ids['invalid_gtin'], '_global_unique_id', '1234567890123' );

update_option( 'nwr_pf_test_ids', $ids, false );
nwr_out( $ids );
