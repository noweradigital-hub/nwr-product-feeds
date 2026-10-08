<?php
// Independent SQL count of what the default feed must contain (simple + variations,
// hidden simple products out, sold-out in, needs a price and an image).
require __DIR__ . '/_bootstrap.php';
global $wpdb;

$hidden_tt = (int) $wpdb->get_var( "SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'product_visibility' AND t.slug = 'exclude-from-catalog'" );
$search_tt = (int) $wpdb->get_var( "SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'product_visibility' AND t.slug = 'exclude-from-search'" );
$type_sql  = static fn( string $type ): string => $wpdb->prepare(
	"SELECT tr.object_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'product_type' AND t.slug = %s",
	$type
);

$simple = $wpdb->get_col(
	"SELECT p.ID FROM {$wpdb->posts} p
	WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.post_password = ''
	AND p.ID IN (" . $type_sql( 'simple' ) . ")
	AND NOT ( p.ID IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = {$hidden_tt})
		AND p.ID IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = {$search_tt}) )
	AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_price' AND m.meta_value + 0 > 0)
	AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_thumbnail_id' AND m.meta_value + 0 > 0)
	AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_nwr_pf_exclude' AND m.meta_value = 'yes')
	ORDER BY p.ID"
);

$variations = $wpdb->get_col(
	"SELECT v.ID FROM {$wpdb->posts} v
	JOIN {$wpdb->posts} p ON p.ID = v.post_parent AND p.post_type = 'product' AND p.post_status = 'publish' AND p.post_password = ''
	WHERE v.post_type = 'product_variation' AND v.post_status = 'publish'
	AND p.ID IN (" . $type_sql( 'variable' ) . ")
	AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = v.ID AND m.meta_key = '_price' AND m.meta_value + 0 > 0)
	AND ( EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = v.ID AND m.meta_key = '_thumbnail_id' AND m.meta_value + 0 > 0)
		OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_thumbnail_id' AND m.meta_value + 0 > 0) )
	ORDER BY v.ID"
);

nwr_out( array( 'simple' => array_map( 'intval', $simple ), 'variations' => array_map( 'intval', $variations ) ) );
