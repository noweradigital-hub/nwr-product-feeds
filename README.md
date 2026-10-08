# nwr-product-feeds 1.0.1 — Produktové feedy Google & Meta

WooCommerce plugin pre obchody, ktoré spravuje Nowera. Generuje feedy pre Google Merchant Center a Meta katalóg a spravuje sa vo **WooCommerce → Produktové feedy**.

## Obsah repozitára

| Cesta | Čo je tam |
|---|---|
| `plugin/nwr-product-feeds/` | samotný plugin (to, čo ide do ZIP) |
| `tests/playground/` | integračné testy vo WordPress Playground (WooCommerce 11.1, PHP 8.3) |
| `tools/build.sh` | lint + `.pot` + `dist/nwr-product-feeds-<verzia>.zip` |
| `tools/make-pot.mjs` | šablóna prekladov bez WP-CLI |
| `tools/dryrun-bundle.py`, `tools/dryrun-driver.php` | suchý beh na živom webe cez Novamira `execute-php` (len čítanie) |
| `docs/overenie-specifikacie.md` | overenie polí proti špecifikácii Google a Meta |

## Ako plugin funguje

**Položky a ID** (ZADANIE §1)
- Jednoduchý produkt → položka s ID produktu. Variácia → položka s ID variácie a `item_group_id` = ID rodiča. Variabilný rodič položkou nie je.
- ID vytvára `nwr_pf_item_id( WC_Product $product, string $feed_id = '' )` zo spoločnej šablóny (predvolene `{id}`; ďalej `{sku}`, `{parent_id}`, `{parent_sku}`, prefix/sufix). Tú istú funkciu volá meranie (`nowera-capi`), takže sa ID nerozídu. Pre variabilný produkt vracia ID skupiny.
- `{sku}` berie **len vlastné SKU** (`get_sku('edit')`, a nie rovnaké ako rodičovo). Variácia bez SKU dostane svoje ID.
- Feed môže mať vlastnú šablónu (katalóg so starými ID). Meranie vtedy s týmto feedom nesedí — admin na to upozorní.

**Výber produktov** (§5.1–5.3)
- Rodičia a variácie sa čítajú priamo cez `$wpdb` (keyset po 50 ID), takže `pre_get_posts` z Woodmartu či WPC nič nezmení. Test to overuje simulovaným „show single variations + hide parent“.
- Skrytý jednoduchý produkt sa vynechá (nastaviteľné). **Skrytý variabilný rodič svoje variácie nevyradí.**
- Vypredané ostávajú (`out_of_stock` / `out of stock`). Vynechané: nepublikované, zaheslované, bez ceny, bez obrázka, zakázané variácie, nepovolené typy (darčekové karty, externé, zoskupené; composite/bundle len s pevnou cenou > 0). Každé vynechanie ide do reportu s dôvodom.

**Generovanie** (§4)
- Nikdy pri HTTP požiadavke. `Action Scheduler`: úloha `nwr_pf_generate` (každý feed podľa plánu, predvolene hodinu) → `nwr_pf_batch` (dávky) → kontrola súboru (XMLReader, počet `<item>`) → atomický `rename()` na `uploads/nwr-feeds/<token>-<slug>.xml`.
- Dočasné súbory sú v `uploads/nwr-feeds/.tmp/` (deny all). V priečinku je `index.php` a `.htaccess` s `Options -Indexes`, `X-Robots-Tag: noindex` a `Cache-Control: max-age=300`.
- Pri chybe ostáva posledný platný súbor. Súbor s o viac ako 50 % menej položkami (nastaviteľné) sa nezverejní a čaká na potvrdenie — Meta pri plánovanom načítaní mazanie chýbajúcich produktov nerieši nijak inak.
- Zmena produktu, ceny, skladu, kategórie či značky len nastaví príznak; na `shutdown` sa naplánuje **jedno** generovanie o 10 min (nastaviteľné 1–60). Ďalšie zmeny v tom okne nič nepridajú. Počas behu sa požiadavka zapíše ako „rerun“.
- Dávka sa na pomalom serveri zmenší (> 20 s), inak späť. Po dávke sa čistí len runtime cache (`wp_cache_flush_runtime`), nikdy zdieľaná objektová cache. Žiadny purge, preload ani HTTP na vlastný web.
- DPH sa počíta pre krajinu feedu (predvolene krajina obchodu) aj vtedy, keď úlohu spustí geolokovaný návštevník (napr. pri `tax_based_on = billing` a `default_customer_address = geolocation`).
- Test s 300 produktmi: 7 dávok, 1 s, pamäť ~90 MB (celý WordPress v Playground).

**Polia** (§2, overené v `docs/overenie-specifikacie.md`)
- RSS 2.0 s `g:`. Súbor začína `<?xml`, UTF-8 bez BOM, znaky neplatné v XML 1.0 sa odstránia. **Prázdne polia sa nezapisujú nikdy** (ani `sale_price` mimo akcie). Voliteľne CSV (RFC 4180) a TSV.
- Google: `in_stock|out_of_stock|preorder|backorder` + `availability_date`, `identifier_exists=no` bez GTIN a bez dvojice značka + MPN, `item_group_title`, `variant_option` pre ostatné atribúty variácie, limity 150/5000/50/10 obrázkov.
- Meta: `in stock|out of stock|available for order|preorder`, `status`, `quantity_to_sell_on_facebook` (nie pri tovare na objednávku), `internal_label`, `fb_product_category`, `additional_variant_attribute` — **všetky polia položky s prefixom `g:`** ako vo vzorovom feede Meta, dátumy s `+02:00`, limity 200/9999/100/20 obrázkov, upozornenie na obrázky mimo JPEG/PNG a popis kratší ako 30 znakov.
- Popis: vlastný text → popis variácie → krátky popis → popis produktu; bez shortcodov (aj nenačítaných pluginov) a HTML; Elementor dump sa nepoužije. Názov variácie: „Rodič – Hodnota, Hodnota“.
- `product_type` z primárnej kategórie Yoastu (inak najhlbšia), predvolená kategória WooCommerce („Nezaradené“) sa ignoruje. Google kategória sa dedí na podkategórie, výber cez vyhľadávanie v oficiálnej taxonómii (sk-SK).
- Značka: pole v produkte → `product_brand` (a iné taxonómie značiek) → atribút → predvolená značka feedu, rovnako pre variácie (hľadá aj pri rodičovi). Report ukáže, ktoré položky dostali predvolenú značku, a ak značka chýba a predvolená nie je nastavená, odkáže na nastavenie. GTIN: pole v produkte → natívne `_global_unique_id` → známe meta kľúče; kontrolná číslica sa overuje.
- Obrázky: vlastný obrázok variácie → jej galéria (natívna aj Woodmart `wd_additional_variation_images_data`) → obrázok a galéria rodiča.

### Odchýlky od zadania (po overení špecifikácií)

| Zadanie | Plugin | Prečo |
|---|---|---|
| Meta `availability` aj `preorder`, `available for order` | podľa zadania (§9): na objednávku = `available for order`, predobjednávka = `preorder`; vo feede sa dá prepnúť na `in stock` | referencia Meta pre feed uvádza len `in stock` / `out of stock`, Graph API a návody k feedom aj ostatné hodnoty — overí diagnostika v Commerce Manageri |
| Google `backorder` | `backorder` + `availability_date` (dátum pri produkte alebo „o N dní“ z feedu, predvolene 14) | Google dátum pri backorder vyžaduje |
| GTIN: najprv variácia, potom rodič | rodičov GTIN len po zapnutí voľby (predvolene vypnuté), v reporte upozornenie | Google hlási duplicitný GTIN, každý variant má mať vlastný |
| duplicitné ID odmietne celý súbor | duplicita sa vynechá a nahlási (prvá položka ostane) | Meta ignoruje všetky položky s tým ID, Google hlási chybu položky — stále treba predísť |
| `additional_variant_attribute` „ak ho špecifikácia podporuje“ | Meta áno; Google dostane `variant_option` | obe sú v aktuálnych špecifikáciách |
| MPN = vlastné SKU | áno, ale voľba sa dá vypnúť (text upozorní, že je to správne len pre výrobcu) | Google: MPN len od výrobcu |

## Admin

- **Zoznam feedov**: URL s kopírovaním, posledný súbor (čas, položky, veľkosť, vynechané), stav behu, ďalší beh, „Pregenerovať teraz“, duplikovanie, vymazanie. Počas behu sa stránka obnoví sama.
- **Editor**: základ (kanál, formát, slug, plán, krajina), filtre (typy, kategórie/štítky zahrnúť/vylúčiť, vypredané, skryté, cena od–do, vylúčené ID), obsah, atribúty, kategórie Google/Facebook, custom labels (pravidlá: kategória, štítok, akcia, cenové pásmo, sklad, značka, atribút, ostatné atribúty, meta, pevný text), Meta, doprava, pokročilé (šablóna ID, poistka poklesu, nový token).
- **Report**: počty z posledného behu + kontrola voči databáze, vynechané produkty podľa dôvodu s odkazmi, validácia (chyby/upozornenia/info), náhľad prvých N položiek zo zverejneného súboru alebo ukážka z aktuálnych nastavení, log feedu.
- **Nastavenia**: šablóna ID, oneskorenie, dávka, log, aktualizácie; export/import JSON (kategórie sa párujú podľa slugu, tokeny sa neprenášajú); prostredie (cron, Action Scheduler, priečinok, webserver, Multistore); celý log.
- **Produkt / variácia → „Feedy“**: vylúčiť zo všetkých / z vybraných feedov, názov, popis, GTIN, MPN, značka, Google kategória, pohlavie, vek, dostupnosť a dátum, custom_label_0–4. Uloží sa len vtedy, keď bola záložka vo formulári (zjednodušené editory ako BrikPanel hodnoty nezmažú).
- Oprávnenie `manage_woocommerce`, nonce pri každej akcii, sanitizácia všetkých vstupov. UI po slovensky, text domain `nwr-product-feeds` (`languages/nwr-product-feeds.pot`).
- Upozornenie, ak je `DISABLE_WP_CRON` alebo ak úlohy feedov meškajú.

## Testy

Bez lokálneho PHP: WordPress Playground CLI.

```bash
npx --yes @wp-playground/cli@latest server --port=9420 --php=8.3 --login \
  --blueprint=tests/playground/blueprint.json \
  --mount=plugin/nwr-product-feeds:/wordpress/wp-content/plugins/nwr-product-feeds \
  --mount=tests/playground/scripts:/wordpress/nwr-tests
```

```bash
npm run test:wp
```

```bash
npm run test:wp:perf
```

36 testov (+1 výkonnostný): štruktúra a ID, skrytý rodič, SKU dedené od rodiča, povinné polia oboch platforiem, žiadne prázdne elementy, prefix `g:` pri Meta, akcie s dátumami, dostupnosť (aj tovar na objednávku so sledovaným skladom), predvolená značka pri variáciách, čistenie textov, kategórie, obrázky, štítky, report, **zhoda s nezávislým SQL výpočtom**, simulovaný `pre_get_posts`, šablóny ID, CSV/TSV, zlyhanie behu, poistka poklesu, rerun, sledovanie zmien (aj sklad z objednávky), pregenerovanie po aktualizácii pluginu, ukážka, admin obrazovky, nonce a oprávnenia, vyhľadávanie Google kategórií, záložka v produkte, export/import, GitHub updater, migrácia zo starej verzie s presmerovaním, Multistore. XML sa kontroluje aj `xmllint`.

Záludnosti Playground: pred JSON výstupom je varovanie `WP_DEBUG already defined` (Playground); `--login` presmeruje prvú požiadavku návštevníka (cookie `playground_auto_login_already_happened=1`); WP-Cron a async runner Action Scheduleru sú v testoch vypnuté (mu-plugin), úlohy spúšťa `jobs.php`; admin sa otvára s cookies z `login-cookie.php`.

Suchý beh na živom webe (len čítanie, nič nezapisuje):

```bash
python3 tools/dryrun-bundle.py tools/dryrun-driver.php --rename > /tmp/dryrun.php
```

Obsah súboru sa pošle ako `code` do Novamira `novamira/execute-php`. `--rename` presunie triedy do `Nowera\ProductFeedsDryRun` — nutné, keď je plugin na webe aktívny (inak by sa použili jeho triedy). Skript berie nastavenia feedov z webu, ak existujú, a kontroluje prázdne polia, prefixy, dostupnosť a značky.

## Build a vydanie

```bash
tools/build.sh
```

Vznikne `dist/nwr-product-feeds-<verzia>.zip` (priečinok `nwr-product-feeds/`). Verzia musí sedieť v `const VERSION`, hlavičke a `readme.txt`.

Aktualizácie na weboch: hlavička `Update URI: https://github.com/noweradigital-hub/nwr-product-feeds` → WordPress sa pýta filtra `update_plugins_github.com`, plugin číta `releases/latest` (cache 6 h) a inštaluje priložený ZIP. Repozitár musí byť **verejný** (alebo aspoň jeho releasy); `NWR_PF_GITHUB_TOKEN` vo `wp-config.php` len zvyšuje limit API. Workflow `.github/workflows/release.yml` pri tagu `vX.Y.Z` postaví ZIP a priloží ho k releasu.

## Obmedzenia

- Viacjazyčné pluginy (WPML, Polylang) a viac mien sa nerieši — každý obchod má jednu menu a jazyk.
- Nový blokový editor produktu nevykreslí záložku „Feedy“ (plugin to deklaruje ako nekompatibilné).
- Na nginx sa `.htaccess` neuplatní (výpis priečinka je tam predvolene vypnutý).
- Presmerovanie starých URL z verzie 1.0.0 beží, len kým feed existuje a má súbor; URL v GMC/Meta treba aj tak prepísať.
- Odinštalovanie zmaže nastavenia, stav, log a súbory feedov; meta produktov `_nwr_pf_*` len s `define( 'NWR_PF_REMOVE_ALL_DATA', true )`.

## Licencia
GPL-2.0-or-later, plné znenie v [LICENSE](LICENSE).
