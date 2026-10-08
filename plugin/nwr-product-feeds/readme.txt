=== Produktové feedy — Google & Meta ===
Contributors: nowera
Tags: woocommerce, google merchant center, meta catalog, product feed, facebook
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 8.0
WC tested up to: 11.1
Stable tag: 1.0.1
License: GPL-2.0-or-later

Produktové feedy z WooCommerce pre Google Merchant Center a Meta katalóg (Facebook, Instagram).

== Description ==

* Feed sa generuje na pozadí (Action Scheduler, dávky po 50 produktoch) do statického súboru
  `wp-content/uploads/nwr-feeds/<token>-<slug>.xml` — webserver ho posiela bez PHP a bez page cache.
* Jednoduchý produkt = položka s jeho ID, variácia = položka s ID variácie a `item_group_id` rodiča,
  variabilný produkt sám položkou nie je. Rovnaké ID vracia `nwr_pf_item_id()`, ktoré používa meranie.
* Produkty sa čítajú priamo z databázy — témy ani pluginy, ktoré menia dopyty na produkty
  (napr. Woodmart „zobraziť variácie samostatne“), výsledok nezmenia.
* Viac feedov (Google, Meta; XML, CSV, TSV), filtre, mapovanie kategórií Google s vyhľadávaním,
  atribúty, vlastné štítky, nastavenia pri produkte aj variácii (záložka „Feedy“).
* Report vynechaných produktov s dôvodom, validácia položiek, náhľad, log, export a import nastavení.
* Pri chybe ostáva posledný platný súbor; nový súbor s oveľa menším počtom položiek čaká na potvrdenie.
* Aktualizácie z GitHub Releases.

== Hooks ==

* `nwr_pf_item_id` ( string $id, WC_Product $product, string $feed_id ) — ID položky.
* `nwr_pf_item` ( array $item, WC_Product $product, ?WC_Product $parent, array $feed ) — položka pred prevodom na polia kanála.
* `nwr_pf_skip_item` ( string $reason, array $item, … ) — neprázdny text položku vynechá (dôvod ide do reportu).
* `nwr_pf_channels` ( array $channels ) — ďalšie šablóny (trieda odvodená od `Nowera\ProductFeeds\Channels\Channel`).
* `nwr_pf_gtin_meta_keys`, `nwr_pf_brand_taxonomies`, `nwr_pf_variation_gallery_meta_keys`, `nwr_pf_has_fixed_price`,
  `nwr_pf_custom_label_rule`, `nwr_pf_htaccess`, `nwr_pf_multistore_shared_meta`.

== Changelog ==

= 1.0.1 =
* Meta: tovar na objednávku sa posiela ako `available for order` (predtým `in stock`), predobjednávka ako `preorder`.
  Počet kusov sa pri ňom neposiela. Voľba vo feede „in stock“ ostáva.
* Prázdne polia sa do XML nezapisujú nikdy — ani `sale_price` mimo akcie.
* Meta: všetky polia položky s prefixom `g:` ako vo vzorovom feede Meta (`g:status`, `g:quantity_to_sell_on_facebook`,
  `g:additional_variant_attribute`, `g:internal_label`, `g:fb_product_category`).
* Report: položky, ktoré dostali predvolenú značku feedu (aj variácie), a tip na nastavenie značky, keď chýba.
  „Chýba značka, GTIN aj MPN“ je upozornenie (Meta ho hlási ako odporúčanie).
* Po aktualizácii pluginu sa feedy pregenerujú do 10 minút.

= 1.0.0 =
* Nová verzia: generovanie na pozadí do statického súboru, spoločné ID s meraním, report a validácia,
  nastavenia pri produktoch, export/import, aktualizácie z GitHubu.
* Feedy z prvej verzie pluginu (dynamická URL /produkty-feed/…) sa prevezmú a stará URL presmeruje na nový súbor.
