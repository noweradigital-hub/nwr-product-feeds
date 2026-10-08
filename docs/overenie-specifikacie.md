# Overenie špecifikácie feedov — Google Merchant Center × Meta katalóg

Overené 2026-09-17 proti oficiálnym zdrojom. Stránky Google sa sťahovali priamo. Stránky Meta sa čítali cez `r.jina.ai` alebo cez surové `.md` verzie na Meta for Developers. Vzorové súbory sa sťahovali priamo.
Citácie sú v pôvodnom znení (angličtina) a sú skrátené. **NEOVERENÉ** znamená, že to oficiálny zdroj neuvádza alebo sa to nepodarilo overiť.

Hlavné zdroje (skratky používané nižšie):

| Skratka | URL |
|---|---|
| G-spec | https://support.google.com/merchants/answer/7052112 |
| G-RSS | https://support.google.com/merchants/answer/14987622 (RSS 2.0 specification) |
| M-spec | https://www.facebook.com/business/help/120325381656392 |
| M-ref | https://developers.facebook.com/docs/marketing-api/catalog/reference/ (stránka uvádza „Updated: Apr 22, 2026“) |
| M-fields | https://developers.facebook.com/docs/commerce-platform/catalog/fields |
| M-sample | https://lookaside.facebook.com/developers/resources/?id=dpa_product_catalog_sample_feed_rss.xml |
| M-api | https://developers.facebook.com/documentation/ads-commerce/marketing-api/reference/product-catalog/products |
| M-variants | https://www.facebook.com/business/help/2256580051262113 (čítané cez en-gb.facebook.com) |
| M-troubleshoot | https://www.facebook.com/business/help/2041876302542944 |

## 0. Čo obsahuje vzorový RSS súbor Meta

Súbor [M-sample] je malý (873 B). Obsahuje:

- deklaráciu `<?xml version="1.0"?>` bez uvedeného kódovania,
- `<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">`,
- `<channel>` s `title`, `link` a `description` bez prefixu,
- v `<item>` **všetky polia s prefixom `g:`**: `g:id`, `g:title`, `g:description`, `g:link`, `g:image_link`, `g:brand`, `g:condition`, `g:availability` (`in stock`), `g:price` (`9.99 GBP`), vnorený `g:shipping` (`g:country`, `g:service`, `g:price`), `g:google_product_category` ako cesta (`Animals &gt; Pet Supplies`) a `g:custom_label_0`.

**Nie je v ňom** `additional_image_link`, `item_group_id`, `status`, `sale_price` ani žiadne pole určené len pre Meta. Pre tieto polia preto vzor nič nepreukazuje.
Vzorový Atom súbor (`…?id=dpa_product_catalog_sample_feed_atom.xml`) má rovnaké polia a navyše `<applink …/>` bez prefixu.

---

## A. Meta — `availability`

**Odpoveď**
- Aktuálna špecifikácia feedu (help aj developer reference) pozná **iba dve hodnoty: `in stock` a `out of stock`**. Píšu sa s medzerou a v americkej angličtine.
- Hodnoty `preorder`, `available for order` a `discontinued` vo feed špecifikácii **nie sú**. Vyskytujú sa len na dvoch iných miestach:
  - Graph API (`POST /{catalog_id}/products`) má enum `in stock, out of stock, preorder, available for order, discontinued, pending, mark_as_sold, mark_as_expired`.
  - Pixel/microdata katalógy (`product:availability` a schema.org `availability`) prijímajú `in stock`, `out of stock`, `available for order` a `discontinued`.
- Podčiarkovníkové tvary Google (`in_stock`…) Meta nikde neuvádza. Či ich Meta prijme, je **NEOVERENÉ**. Pre Meta používať tvar s medzerou.
- Vypredané produkty sa nezobrazujú v reklamách. V shopoch na FB/IG ostávajú až 56 dní s označením „sold out“.
- Dôsledok pre plugin: Meta dostane len `in stock` alebo `out of stock`. Pre WooCommerce `onbackorder` treba rozhodnúť. Návrh: `in stock`, keďže objednávku prijmeme. `available for order` je mimo feed špecifikácie.

**Citácie**
- „Supported values: in stock, out of stock.“ — [M-spec]
- „Must be written in U.S. English.“ — [M-ref]
- „in stock, out of stock, preorder, available for order, discontinued“ — enum v [M-api]

**Zdroje:** [M-spec], [M-ref], [M-api], https://www.facebook.com/business/help/1033769603329681

## B. Meta — `status` (predtým `visibility`)

**Odpoveď**
- Pole sa volá `status`. Starý názov `visibility` Meta stále prijíma, ale odporúča `status`.
- Hodnoty sú `active` a `archived`. Predvolená je `active`, prázdne pole znamená tiež `active`. Archivované produkty sa nezobrazujú v reklamách ani v shope.
- Pri **replace** schedule platí: keď pole `status` z feedu vypustíte, archivované produkty sa po ďalšom načítaní vrátia na `active`.
- `status` (rovnako ako `price`, `sale_price` a `availability`) patrí len do country feedu, nie do language feedu.
- **Prefix v XML:** podľa Meta dev docs je `g:` povinný pre atribúty z Google namespace. Ostatné atribúty sa píšu bez prefixu. `status` nie je Google atribút, preto `<status>archived</status>`. Či Meta prijme aj `<g:status>`, je **NEOVERENÉ** (oficiálny príklad chýba).
- Pozor: Graph API má iné pole `visibility` s hodnotami `staging|published`. S feed poľom `status` ho nemiešať.

**Citácie**
- „Supported values: active, archived. Items are active by default.“ — [M-ref]
- „This field was previously called visibility.“ — [M-ref]
- „use it without prefix, such as video, additional_image_link“ — [M-ref], sekcia Example XML Feed
- „any products marked archived will automatically revert back to the default status“ — https://www.facebook.com/business/help/543317109402043
- „must only be provided in a country feed“ — [M-ref]

**Zdroje:** [M-spec], [M-ref], [M-api], https://www.facebook.com/business/help/543317109402043

## C. Meta — `quantity_to_sell_on_facebook` (predtým `inventory`)

**Odpoveď**
- **Význam:** počet kusov, ktoré môžete predať. Pole je relevantné len pre Shops na Facebooku a Instagrame. Pri nízkom počte môže shop zobraziť štítok „Only a few left“.
- **Formát:** celé číslo (integer), napríklad `500`. Starý názov `inventory` Meta stále prijíma.
- **Kedy je povinné:** len pri onsite checkoute. Sekcia s ďalšími povinnými poľami je v dokumentácii označená „(US Only)“. Pre reklamy a slovenský shop bez checkoutu je pole nepovinné.
- Ak je `availability` = `in stock` a množstvo chýba, Meta môže nastaviť 999 999 (nekonečno).
- V XML ide o pole len pre Meta, takže sa píše bez `g:` (viď B).
- Pozn.: [M-fields] na jednom mieste píše `quantity_to_sell_on_fb`. Ide o preklep, v tabuľke polí je `quantity_to_sell_on_facebook`.

**Citácie**
- „This field is only relevant for Shops on Facebook and Instagram.“ — [M-spec]
- „Enter a whole number.“ — [M-ref]
- „set automatically to 999,999 (representing infinity)“ — [M-spec]
- „If your business is using onsite checkout, there are more required fields“ — [M-fields]

**Zdroje:** [M-spec], [M-ref], [M-fields]

## D. Meta — `additional_variant_attribute`

**Odpoveď**
- **Áno, špecifikácia feedu ho podporuje.** Je uvedené v sekcii Optional Fields v [M-ref] (katalógy pre reklamy aj commerce) aj v [M-fields]. Nie je obmedzené na checkout. Podporujú ho aj supplementary feedy a dá sa lokalizovať v language feede.
- **Účel:** vlastné variantné atribúty, ktoré nie sú „core“. Core atribúty (`size`, `color`, `gender`, `pattern`…) sa sem nedávajú.
- **Formát pre CSV, TSV a Sheets:** páry `Label:Value` oddelené čiarkou, napríklad `Scent:Fruity, Flavor:Strawberry`.
- **Formát pre XML** podľa príkladu v dev docs (bez prefixu, vnorené `label` a `value`):
  ```xml
  <additional_variant_attribute>
    <label>Style</label>
    <value>Cool</value>
  </additional_variant_attribute>
  ```
  Nejde teda o jeden reťazec.
- Viac párov v XML: príklad ukazuje len jeden pár. Opakovanie celého elementu je logické, ale **NEOVERENÉ**.
- Limity (dĺžka labelu a hodnoty, počet párov) dokumentácia neuvádza, preto **NEOVERENÉ**.
- Pravidlá variantov podľa help stránky [M-variants]:
  - Varianty sa odlišujú cez `color`, `size`, `material`, `pattern`, `gender` alebo `additional_variant_attribute`.
  - Všetky varianty v skupine musia mať vyplnené **rovnaké** atribúty a každý variant musí mať **unikátnu kombináciu**.
  - Iný obrázok na odlíšenie nestačí.
  - Cez feed sa dá pridať max. 800 variantov na produkt, nad 300 Meta zobrazí varovanie.
  - Všetky varianty musia byť v tom istom feede.
- Návrh pre obchod s vlastnou značkou:
  - `pa_farba` → `color`,
  - `pa_material` → `material` (core atribút, do `additional_variant_attribute` nepatrí),
  - `pa_nosnost` → `additional_variant_attribute` (napr. label `Nosnosť`, value `15 kg`).

**Citácie**
- „Additional attributes that are not core attributes (size, color, gender, pattern, and so on).“ — [M-ref]
- „Do not use a core attribute as an additional attribute.“ — [M-ref]
- „up to 800 variants of the same product using a data feed“ — [M-variants]

**Zdroje:** [M-ref], [M-fields], [M-variants], https://developers.facebook.com/documentation/ads-commerce/catalog/guides/product-variants

## E. Meta — `fb_product_category` a `internal_label`

**`fb_product_category`**
- **Hodnota:** názov kategórie (celá cesta, veľkosť písmen nerozhoduje) **alebo** číselné ID.
- **Zoznam:** https://www.facebook.com/products/categories/en_US.txt. Stiahnutý súbor má tieto vlastnosti:
  - v skutočnosti je to CSV s hlavičkou `category_id,category`,
  - UTF-8 **s BOM**, bez koncového nového riadku,
  - 2 967 kategórií s ID 1–2971, hĺbka najviac 6 úrovní,
  - cesty sú malými písmenami,
  - názvy obsahujúce čiarku sú v úvodzovkách.
- Existuje aj **`sk_SK.txt`** (rovnaké ID, slovenské názvy) a `cs_CZ.txt`.
- Cesta z dokumentácie (`Clothing & Accessories > Clothing > Women's Clothing > Tops & T-Shirts` = 430) sa nezhoduje s aktuálnym súborom, kde je 430 = `clothing & accessories > clothing > women's clothing > tops > tops & t-shirts`. **Preto posielať ID.**

**`internal_label`** (predtým `product_tags`)
- **Formát pre TSV, XLSX a Sheets:** `['summer','trending']`. Každý label je v apostrofoch, labely sú oddelené čiarkou a na okrajoch labelu nesmú byť medzery.
- **Formát pre CSV:** celý zoznam v úvodzovkách, `"['summer','trending']"`.
- **Formát pre XML:** každý label vo vlastnom elemente bez prefixu, `<internal_label>summer</internal_label><internal_label>trending</internal_label>`.
- **Limity:** najviac 5 000 labelov na produkt a 110 znakov na label.
- Meta ukladá labely malými písmenami. Odporúča ASCII malé písmená a oddeľovače `#`, `_`, `:`.
- V produktových sadách používať podmienku „is any of“. Podmienka „contains“ nemusí zabrať.
- Meta odporúča pre produktové sady `internal_label` namiesto `custom_label_*`, lebo jeho zmena nespúšťa policy review.

**Citácie**
- „Enter either the category name (not case sensitive) or its ID number.“ — [M-spec]
- „Up to 5,000 labels per product and 110 characters per label.“ — [M-ref]
- „The Atom XML format requires to wrap each label“ — [M-ref]
- „Internal labels are case insensitive and will be stored lowercased.“ — [M-ref]

**Zdroje:** [M-spec], [M-ref], https://www.facebook.com/business/help/526764014610932, https://www.facebook.com/products/categories/en_US.txt, https://www.facebook.com/products/categories/sk_SK.txt

## F. Meta — `gender`, `age_group` a limity textových polí

**Odpoveď**
- `gender`: `female`, `male`, `unisex`.
- `age_group`: `adult`, `all ages`, `teen`, `kids`, `toddler`, `infant`, `newborn`.
- Limity textových polí:

  | Pole | Max. znakov | Poznámka |
  |---|---|---|
  | `color` | 200 | farba slovom, nie hex kód |
  | `size` | 200 | medzera medzi slovom a číslom („US 12“), bez slova „size“ |
  | `material` | 200 | |
  | `pattern` | 100 | |
  | `brand`, `mpn`, `custom_label_0–4`, `vendor_id` | 100 | |
  | `product_type` | 750 | |

**Citácie**
- „Supported values: female, male, unisex.“ — [M-spec]
- „Supported values: adult, all ages, teen, kids, toddler, infant, newborn.“ — [M-spec]
- „Describe the color in words, not a hex code.“ — [M-spec]

**Zdroje:** [M-spec], [M-ref]

## G. Meta — `additional_image_link`

**Odpoveď**
- **Počet:** najviac 20 ďalších obrázkov.
- **Help stránka:** jedno pole, URL oddelené čiarkou, bodkočiarkou, medzerou alebo `|`.
- **Dev reference:** pole má najviac **2 000 znakov**. V CSV musí byť celý zoznam v úvodzovkách.
- **XML:** vzorový RSS súbor toto pole **neobsahuje**, takže sa z neho overiť nedá. Príklad XML feedu v dev docs používa **opakované elementy bez prefixu**:
  ```xml
  <additional_image_link>https://…/image2.jpg</additional_image_link>
  <additional_image_link>https://…/image3.jpg</additional_image_link>
  ```
  Text dev docs výslovne uvádza `additional_image_link` medzi poľami „bez prefixu“.
- **NEOVERENÉ:**
  - či Meta prijme aj opakovaný `<g:additional_image_link>` v tvare, ktorý používa Google,
  - či sa limit 2 000 znakov pri opakovaných elementoch sčítava.
  - Bezpečná voľba: najviac 20 URL a spolu najviac 2 000 znakov.
- Obrázky musia spĺňať rovnaké požiadavky ako `image_link` (JPEG alebo PNG, viď R).

**Citácie**
- „Up to 20 URLs of additional product images“ — [M-spec]
- „separated by a comma (,), semicolon (;), space ( ) or vertical bar“ — [M-spec]
- „Maximum character limit: 2000“ — [M-ref]

**Zdroje:** [M-spec], [M-ref], [M-sample]

## H. Meta — `sale_price_effective_date`, `price`, `title`, `description`, `rich_text_description`

**`sale_price_effective_date`**
- Formát: `YYYY-MM-DDThh:mm±hh:mm/YYYY-MM-DDThh:mm±hh:mm`. Čas je v 24-hodinovom formáte, časové pásmo od −12:00 do +14:00.
- Príklad: `2025-11-24T09:30-08:00/2025-11-30T23:59-08:00`.
- Bez tohto poľa platí zľava, kým `sale_price` neodstránite.
- CSV príklad v dev docs obsahuje aj tvar `-0300` bez dvojbodky. V dokumentácii Meta sa teda vyskytujú oba zápisy.

**`sale_price`**
- Musí byť nižšia ako `price`, inak sa nezobrazí.
- Pri **update** schedule sa zľava zruší len prázdnym poľom alebo hodnotou 0. Samotné vynechanie poľa nestačí.

**`price`**
- Číslo, medzera a trojpísmenový kód ISO 4217, napríklad `9.99 USD` alebo `7.99 EUR`.
- Desatinná bodka, žiadne symboly meny.
- V jednom súbore len jedna mena. Ak kód meny chýba, Meta použije predvolenú menu feedu.

**`title`**
- Najviac 200 znakov, odporúčané menej ako 65.
- Odporúča sa title case.

**`description`**
- **30 až 9 999 znakov.**
- Čistý text: bez HTML, bez odkazov, nie celé veľkými písmenami.
- Má sa líšiť od `title`.

**`rich_text_description`**
- **Pole existuje.** Najviac 9 999 znakov.
- V shopoch sa zobrazuje namiesto `description`. `description` však ostáva povinné ako záloha.
- V reklamách sa nepoužíva.
- Povolené tagy sa v zdrojoch líšia:
  - help: `<p>`, `<b>`, `<i>`, `<ul>`, `<li>`,
  - dev docs: dlhší zoznam (`h1`–`h6`, `table`, `ol`, `strong`, `em`, `br`…).
- Atribúty tagov (napr. `style`) Meta odstráni.

**Citácie**
- „YYYY-MM-DDT23:59+00:00/YYYY-MM-DDT23:59+00:00“ — [M-spec]
- „a number, followed by a space and then the 3-letter ISO 4217 currency code“ — [M-spec]
- „Include only 1 currency per data file.“ — [M-spec]
- „Descriptions must be between 30 to 9,999 characters.“ — https://www.facebook.com/business/help/2302017289821154
- „Rich text descriptions are not supported in ads.“ — [M-spec]
- „leave the field in the feed but make it empty“ — https://www.facebook.com/business/help/2284463181837648

**Zdroje:** [M-spec], [M-ref], https://www.facebook.com/business/help/1033769603329681, https://www.facebook.com/business/help/2104231189874655, https://www.facebook.com/business/help/2302017289821154

## I. Meta — `item_group_id` a `id`

**`id`**
- Najviac **100 znakov**, rozlišuje veľké a malé písmená.
- Musí byť unikátne v celom katalógu a **nesmie sa zhodovať so žiadnym `item_group_id`**.
- Pre Advantage+ katalógové reklamy musí presne sedieť s content ID v pixeli.

**`item_group_id`**
- Všetky varianty jedného produktu majú rovnakú hodnotu.
- Hodnota musí byť unikátna, nesmie sa zhodovať so žiadnym `id` a rozlišuje veľké a malé písmená.
- Dev reference uvádza najviac **100 znakov**. Help stránka limit neuvádza.
- Konflikt `id` s group ID je chyba položky („ID conflicts with group ID“).

**Varianty podľa [M-variants]**
- Každý variant má mať vlastný link, ideálne s predvoleným variantom.
- Všetky varianty musia byť v tom istom feede.
- Ak k existujúcemu produktu pridávate varianty, jeden variant má ponechať pôvodné content ID. Inak sa stratia dáta z pixelu.
- Dev guide varianty ukazuje, že rodič nemá byť samostatná položka.
- Jeho „správny“ príklad používa **rovnaký názov pre všetky varianty**. Help príklad ale má rôzne názvy („Blue shirt“, „Red shirt“), takže nejde o tvrdé pravidlo.

**Citácie**
- „Character limit: 100.“ — [M-spec] (pole `id`)
- „must not be the same as any individual content IDs“ — [M-spec]
- „Max character limit: 100“ — [M-ref] (pole `item_group_id`)
- „All variants of the same product must belong to the same data feed.“ — [M-variants]
- „so that the name does not change when variants are selected“ — dev guide Product Variants

**Zdroje:** [M-spec], [M-ref], [M-variants], [M-troubleshoot], https://developers.facebook.com/documentation/ads-commerce/catalog/guides/product-variants

## J. Google — `availability` a `availability_date`

**`availability`**
- Hodnoty: `in_stock`, `out_of_stock`, `preorder`, `backorder`. Existuje aj `build_to_order`, ale len pre vehicle ads. Hodnoty sa píšu po anglicky.
- `preorder` je len pre produkty, ktoré ešte nevyšli. Vypredaný produkt, na ktorý prijímate objednávky, má hodnotu `backorder`.
- `out_of_stock` sa nemá používať na pozastavenie produktu (na to slúži `pause` alebo `excluded_destination`). Ukončené produkty treba z feedu odstrániť.
- Pozn.: Google vo vlastnom príklade RSS 2.0 píše `<g:availability>in stock</g:availability>` s medzerou. Špecifikácia atribútu však uvádza len tvary s podčiarkovníkom, preto posielať `in_stock`.

**`availability_date`**
- Je **povinné pri `preorder` aj pri `backorder`**. Tak to uvádza stránka atribútu aj stránka `availability`. Tabuľka v hlavnej špecifikácii má v nadpise len preorder, ale text pod ňou uvádza oba prípady.
- Formát: `YYYY-MM-DDThh:mm[±hhmm]` alebo `YYYY-MM-DDThh:mmZ`, 1–25 znakov, najviac 1 rok dopredu.
- Bez času sa použije polnoc UTC.
- XML: `<g:availability_date>2016-11-25T13:00-0800</g:availability_date>`.

**Dôsledok pre plugin:** WooCommerce nemá natívny dátum dostupnosti. `onbackorder` → `backorder` sa preto dá použiť len vtedy, keď dátum poznáme (pole pri produkte alebo nastavenie „dnes + N dní“). Inak treba rozhodnúť, čo posielať.

**Citácie**
- „In stock [in_stock] Out of stock [out_of_stock] Preorder [preorder] Backorder [backorder]“ — [G-spec]
- „Required if you set the availability [availability] attribute to preorder or backorder.“ — https://support.google.com/merchants/answer/6324470

**Zdroje:** [G-spec], https://support.google.com/merchants/answer/12472827, https://support.google.com/merchants/answer/6324470, [G-RSS]

## K. Google — maximálne dĺžky

| Atribút | Limit | Zdroj (answer/…) |
|---|---|---|
| `id` | 1–50 znakov (odporúčané ASCII: písmená, číslice, `_`, `-`). Medzery na okrajoch Google oreže. | 6324405 |
| `title` | 1–150 znakov. Dlhší Google skráti a zobrazí varovanie. | 6324415 |
| `description` | 1–5 000 znakov. Dlhší Google skráti a zobrazí varovanie. | 6324468 |
| `item_group_id` | 1–50 znakov | 12472646 |
| `item_group_title` | 1–150 znakov | 17085146 |
| `product_type` | 0–750 znakov, najviac 5 hodnôt. Pre bidding a reporting sa použije prvá. | 6324406 |
| `custom_label_0–4` | 1–100 znakov, v účte najviac 1 000 unikátnych hodnôt na label | 6324473 |
| `mpn` | 1–70 znakov | 12474954 |
| `brand` | 1–70 znakov | 12468352 |
| `color` | 1–100 znakov spolu, 1–40 znakov na farbu, najviac 3 farby | 12471922 |
| `size` | 1–100 znakov | 12471627 |
| `material` | 0–200 znakov, najviac 3 materiály | 12472145 |
| `pattern` | 0–100 znakov | 12472146 |
| `gtin` | 8, 12, 13 alebo 14 číslic, najviac 10 hodnôt. Hlavná špecifikácia: spolu najviac 50 číslic, najviac 14 na hodnotu. | 12473440 |
| `link`, `image_link`, `additional_image_link` | URL do 2 000 znakov | 6324416, 12472547, 12472826 |
| `additional_image_link` | **najviac 10** (element sa opakuje) | 12472826 |
| `variant_option` | najviac 30 hodnôt a spolu 5 000 znakov; `name` a `value` po 250 znakov | 17085214 |
| `product_detail` | najviac 100 detailov. Hlavná špecifikácia: `section_name` a `attribute_name` po 140, `attribute_value` 1 000 znakov. Stránka atribútu uvádza „1 - 150 characters (per detail)“. | G-spec, 9218260 |
| `sale_price_effective_date` | 0–51 znakov | 12471843 |
| `availability_date` | 1–25 znakov | 6324470 |
| `shipping` | najviac 100-krát na produkt | 12471847 |

**Citácie**
- „Include up to 10 additional images“ — https://support.google.com/merchants/answer/12472826
- „1–100 characters total (1–40 characters per color)“ — https://support.google.com/merchants/answer/12471922

**Zdroje:** [G-spec] a stránky atribútov `https://support.google.com/merchants/answer/<ID>` podľa tabuľky.

## L. Google — `sale_price_effective_date` a `price`

**`sale_price_effective_date`**
- Dva časové údaje podľa ISO 8601 oddelené lomkou `/`. Každý v tvare `YYYY-MM-DDThh:mm[±hhmm]` alebo `YYYY-MM-DDThh:mmZ`.
- Príklad pre XML:
  `<g:sale_price_effective_date>2016-02-24T13:00-0800/2016-02-29T15:30-0800</g:sale_price_effective_date>`
- Hlavná špecifikácia ukazuje lomku obklopenú medzerami (`… / …`), stránka atribútu bez nich. Posielať **bez medzier**.
- Najviac 51 znakov.
- Bez času: zľava začne o 00:00 v deň začiatku a skončí o 23:59 v deň konca. Bez časového pásma sa použije UTC.
- Bez tohto atribútu platí `sale_price` stále.
- Pre SK: `2026-10-01T00:00+0200/2026-10-31T23:59+0100`. Posun pásma treba určiť zvlášť pre každý dátum (letný a zimný čas).

**`price`**
- Číslo a kód meny ISO 4217, napríklad `<g:price>15.00 USD</g:price>`.
- Desatinná bodka, najviac 2 desatinné miesta (viac Google zaokrúhli).
- Mimo USA a Kanady vrátane DPH.
- Cena 0 nie je povolená, až na výnimky (napr. telefóny so zmluvou).

**Citácie**
- „separated by slash ( / )“ — https://support.google.com/merchants/answer/12471843
- „Don't provide more than 2 digits after the decimal point.“ — https://support.google.com/merchants/answer/12471842
- „Include value added tax (VAT) or Goods and Services Tax (GST) in the price.“ — [G-spec]

**Zdroje:** https://support.google.com/merchants/answer/12471843, https://support.google.com/merchants/answer/12471842, [G-spec]

## M. Google — `identifier_exists`

**Odpoveď**
- **Hodnoty pre textové a XML zdroje:** `yes`, `true`, `no`, `false`. Merchant API prijíma len `true` a `false`. Hlavná špecifikácia uvádza `yes` a `no`. Hodnoty sa píšu po anglicky.
- **Predvolené:** ak atribút chýba, platí `yes`.
- **Kedy poslať `no`:** keď produkt nemá priradené identifikátory. Hlavná špecifikácia to rozlišuje podľa typu produktu:

  | Typ produktu | `no` poslať, keď |
  |---|---|
  | médiá | chýba GTIN |
  | oblečenie | chýba značka |
  | ostatné | chýba GTIN **a zároveň** chýba kombinácia MPN + brand |

  Stránka atribútu odporúča `no` pre produkty bez GTIN, MPN alebo značky.
- Aj pri `no` treba poslať identifikátory, ktoré máme (napr. `brand`).
- Ak identifikátory existujú, `no` neposielať. Hrozí varovanie alebo zamietnutie.
- Rozdiel oproti ZADANIU (bez GTIN a bez MPN): položka s MPN, ale bez značky má podľa Google dostať `no`.

**Citácie**
- „Recommended for products that don’t have a GTIN“ — https://support.google.com/merchants/answer/12472746
- „If you don't submit the attribute, the default value is yes.“ — [G-spec]
- „doesn’t have a GTIN, or a combination of MPN and brand“ — [G-spec]
- „If set to no, still provide the UPIs you have.“ — [G-spec]

**Zdroje:** https://support.google.com/merchants/answer/12472746, [G-spec]

## N. Google — `gender`, `age_group`, pravidlá pre `size` a `color`

**`gender`:** `male`, `female`, `unisex`.

**`age_group`**
- Hodnoty: `newborn` (0–3 mes.), `infant` (3–12 mes.), `toddler` (1–5 r.), `kids` (5–13 r.), `adult` (tínedžeri a starší).
- **Google nepozná `teen` ani `all ages`**, ktoré má Meta. Mapovanie preto musí byť pre každý kanál zvlášť.
- `gender` a `age_group` sú povinné pre kategóriu Apparel & Accessories (ID 166): vo free listings vždy, v reklamách pri cielení na BR, FR, DE, JP, UK a US.

**`size`**
- Najviac 100 znakov, jedna hodnota na produkt.
- Viac veľkostí alebo rozmerov sa oddeľuje **lomkou**, nie čiarkou (`small/medium/large`, `16/34`).
- Pre univerzálnu veľkosť: `one size`, `OS`, `OSFA`…
- Detské veľkosti napríklad `3-6 Mo`.

**`color`**
- Najviac 3 farby oddelené **lomkou**, primárna prvá (`Red/Green/Black`).
- Pri čiarke Google použije len jednu farbu.
- Nepoužívať čísla, hex kódy, „multicolor“ ani „N/A“.

**`material`:** najviac 3 materiály oddelené lomkou (`cotton/polyester/elastane`).

**Citácie**
- „Male [male] Female [female] Unisex [unisex]“ — [G-spec]
- „Adult [adult] Teens or older“ — [G-spec]
- „Don’t submit multiple sizes separated by a comma“ — https://support.google.com/merchants/answer/12471627 (ďalej odporúča lomku)
- „Don’t submit multiple colors with a comma“ — https://support.google.com/merchants/answer/12471922

**Zdroje:** [G-spec], https://support.google.com/merchants/answer/12471626, https://support.google.com/merchants/answer/12472028, https://support.google.com/merchants/answer/12471627, https://support.google.com/merchants/answer/12471922

## O. Google — `google_product_category` a súbor taxonómie

### Atribút

- Hodnota je číselné ID **alebo** celá cesta, nie oboje. Google odporúča ID.
- Google priraďuje kategórie automaticky. Hodnotu z feedu prijme ako prepis len v určitých prípadoch:
  - kvôli povinným atribútom kategórie,
  - kvôli zacieleniu kampaní,
  - pri alkohole.
- Organizácia Shopping kampaní podľa tejto kategórie funguje len vo vybraných krajinách a **SK medzi nimi nie je**. Pre SK je preto dôležitý `product_type`.
- Ak taxonómia nie je v jazyku feedu, treba použiť anglické názvy alebo ID. Slovenská verzia existuje.
- **Pozor:** príklad ID `371` z hlavnej špecifikácie v súbore taxonómie neexistuje (Coats & Jackets má `5598`). ID treba overovať proti súboru.

### Súbor taxonómie

Overené stiahnutím 2026-09-17.

**en-US** (https://www.google.com/basepages/producttype/taxonomy-with-ids.en-US.txt)
- HTTP 200, 482 896 bajtov, `text/plain`.
- UTF-8 bez BOM, konce riadkov LF, súbor končí novým riadkom.
- **Prvých 5 riadkov doslovne:**
  ```
  # Google_Product_Taxonomy_Version: 2021-09-21
  1 - Animals & Pet Supplies
  3237 - Animals & Pet Supplies > Live Animals
  2 - Animals & Pet Supplies > Pet Supplies
  3 - Animals & Pet Supplies > Pet Supplies > Bird Supplies
  ```
- **Počet riadkov: 5 596.** Prvý je komentár s verziou, zvyšných 5 595 sú kategórie.
- **Formát riadku:** `<ID> - <úroveň 1> > <úroveň 2> > …`. Za ID nasleduje ` - `, úrovne oddeľuje ` > `. Každý riadok okrem komentára tomuto vzoru zodpovedá.
- 21 hlavných kategórií, najviac 7 úrovní.
- Verzia je 2021-09-21.

**sk-SK — existuje** (https://www.google.com/basepages/producttype/taxonomy-with-ids.sk-SK.txt)
- HTTP 200, 597 624 bajtov, rovnaká verzia.
- Obsahuje **rovnakých 5 595 ID**, ale riadky sú zoradené podľa slovenských názvov, napr. druhý riadok je `5181 - Batožina a tašky`.
- Existuje aj `taxonomy-with-ids.sk-SK.xls` (777 216 bajtov) a `taxonomy.sk-SK.txt` bez ID.
- Kontrola: neexistujúce kódy `xx-XX` a `sk` vrátia 404. Súbory existujú aj pre cs-CZ, de-DE, pl-PL, hu-HU a en-GB.
- **Pre parser:** nespoliehať sa na poradie riadkov. Nadradenú kategóriu určovať podľa cesty, nie podľa predchádzajúceho riadku.

**Citácie**
- „You may submit either the ID or the full path, but not both.“ — https://support.google.com/merchants/answer/6324436
- „It is recommended to use the category ID.“ — [G-spec]
- „Google will only accept a product category override in the following cases“ — https://support.google.com/merchants/answer/6324436
- „For all other countries, you can only use the product type“ — https://support.google.com/merchants/answer/6324436

**Zdroje:** https://support.google.com/merchants/answer/6324436, https://support.google.com/merchants/answer/10668075, [G-spec], súbory taxonómie vyššie

## P. Google — `shipping` v XML a `shipping_weight`

### `shipping`

Vnorené sub-atribúty s prefixom. Element sa opakuje, najviac 100-krát na produkt:
```xml
<g:shipping>
  <g:country>SK</g:country>
  <g:service>Kuriér</g:service>
  <g:price>3.90 EUR</g:price>
</g:shipping>
```
- **Sub-atribúty:**
  - `country` je povinný (ISO 3166),
  - voliteľné: `region`, `postal_code`, `location_id`, `location_group_name`, `service`, `price`, `min_handling_time`, `max_handling_time`, `min_transit_time`, `max_transit_time` a ďalšie.
- Cena v EUR môže byť 0–1000.
- V textovom feede sa píše `SK::Kuriér:3.90 EUR`.
- Povinnosť uviesť dopravu platí pre vymenované krajiny (napr. CZ, AT, PL, DE). **SK v zozname nie je.** Dopravu stačí nastaviť v účte.

### `shipping_weight`

- Číslo a jednotka: `lb`, `oz`, `g` alebo `kg`. **Desatinné čísla sú povolené, takže `1.5 kg` je platné.**
- Rozsah 0–1000 kg (0–2000 lbs).
- XML: `<g:shipping_weight>3 kg</g:shipping_weight>`.
- Treba ho len pri doprave počítanej podľa hmotnosti v GMC.

### Meta pre porovnanie

- Help a dev docs popisujú `shipping` ako reťazec `Country:Region:Service:Price`, napr. `US:NY:Ground:9.99 USD, PH::Air:300 PHP`. Pre overlay „Free shipping“ sa cena uvádza `0.0`.
- Oficiálny vzorový RSS od Meta ale používa vnorený `<g:shipping>` rovnako ako Google.
- `shipping_weight` napr. `0.3 kg` alebo `10 kg`.

**Citácie**
- „Submit the shipping [shipping] attribute up to 100 times per product“ — https://support.google.com/merchants/answer/12471847
- „Decimal points to the values are supported. For example, 1.25 kg“ — https://support.google.com/merchants/answer/12472551

**Zdroje:** https://support.google.com/merchants/answer/12471847, https://support.google.com/merchants/answer/12472551, [G-spec], [M-spec], [M-sample]

## Q. Zamietne chybná položka alebo duplicitné `id` celý súbor?

### Meta

- **Duplicitné `id`:** Meta **ignoruje všetky položky s týmto ID**, zvyšok súboru spracuje. Troubleshooting stránka radí duplicitu medzi chyby, ktoré sa týkajú len niektorých produktov.
- **Chyby celého feedu:**
  - prázdny feed,
  - nedostupný súbor (HTTP chyby, zablokovaný crawler `facebookexternalhit`),
  - HTML namiesto podporovaného formátu,
  - v CSV nesedí počet stĺpcov,
  - príliš veľký súbor alebo pomalé sťahovanie,
  - riadok dlhší ako 5 MB (v XML preto písať jedno pole na riadok).
- Súbor musí začínať deklaráciou `<?xml`.
- **Chyby jednotlivých položiek:**
  - chýbajúce povinné pole alebo neplatná hodnota,
  - `id` rovnaké ako group ID, duplicitné ID,
  - chybná cena alebo URL, neplatný GTIN,
  - text celý veľkými písmenami,
  - chýba `brand`, `gtin` aj `mpn` (aspoň jedno je povinné).
- Plánované načítanie, ktoré opakovane zlyhá, sa môže pozastaviť.
- Pri **replace** schedule Meta zmaže produkty, ktoré v novom súbore chýbajú. Pri **update** schedule nie (na mazanie slúži pole `delete`).

### Google

- Čo sa stane pri duplicitnom `id` v jednom súbore, oficiálne texty **výslovne nepopisujú**. Tvrdenie, že Google odmietne celý súbor, je preto **NEOVERENÉ**.
- Súvisiace problémy („Duplicate product“, „Reused value [id]“, „Duplicate GTIN“, „Duplicate variant“) sa zobrazujú ako problémy produktov v záložke **Needs attention**.
- **Problémy celého súboru:**
  - neescapované znaky (`&`),
  - veľké písmená v názvoch atribútov,
  - chybné kódovanie,
  - prípona iná ako `.xml`,
  - prípona `.zip` alebo `.gz` pri súbore, ktorý nie je skomprimovaný,
  - nedostupná URL.
- Report zo spracovania uvádza, koľko produktov bolo zasiahnutých.
- Atribúty z Google namespace bez prefixu `g:` Google **ignoruje**. Výnimkou sú štandardné RSS elementy `title`, `link` a `description`.
- Prázdne atribúty treba z feedu vynechať.
- Produkt, ktorý z RSS feedu zmizne, Google odstráni aj z GMC.

### Záver k ZADANIU

Tvrdenie „duplicitné ID → Google aj Meta odmietnu celý súbor“ oficiálne zdroje nepotvrdzujú. Pri Meta ho priamo vyvracajú: Meta ignoruje všetky položky s daným ID. Pre plugin je to rovnako zlé, lebo tieto produkty z katalógu zmiznú. Kontrola unikátnosti ID je preto nutná.

**Citácie**
- „If there are multiple instances of the same ID, we ignore all instances.“ — [M-ref]
- „Some errors affect your entire feed and may stop it from being uploaded“ — [M-troubleshoot]
- „Other issues may affect only certain products in your file“ — [M-troubleshoot]
- „deletes any products you removed from the new file“ — https://www.facebook.com/business/help/2284463181837648
- „You can't include un-escaped special characters like "&".“ — https://support.google.com/merchants/answer/13760862
- „the attributes and any values they contain will be ignored“ — [G-RSS]
- „Remove attributes that do not contain any values.“ — [G-RSS]
- „it will be removed from Google Merchant Center“ — [G-RSS]

**Zdroje:** [M-ref], [M-troubleshoot], https://www.facebook.com/business/help/2284463181837648, [G-RSS], https://support.google.com/merchants/answer/13760862, https://support.google.com/merchants/answer/12472696, https://support.google.com/merchants/answer/12473198, https://support.google.com/merchants/answer/12470642

## R. Google — `link` pri variantoch a `image_link`

### `link`

- **Povinné:**
  - začína `http` alebo `https`,
  - overená doména,
  - len ASCII znaky podľa RFC 3986, 1–2 000 znakov,
  - symboly a medzery URL-enkódované,
  - stránka prístupná pre Googlebot, bez prihlásenia,
  - jeden link na produkt alebo variant.
- **Predvolenie variantu** je odporúčanie, nie tvrdá podmienka. Stránka `item_group_id` však odporúča **pre každý variant inú URL** (iná cesta alebo query parametre). Landing page sa musí s variantom zhodovať v cene, dostupnosti a obrázku.
- **`?attribute_pa_farba=modra` je v poriadku.** Google sám uvádza príklady `?color=black` a `/t-shirt?color=green&size=small`.
- V XML treba `&` escapovať ako `&amp;`.
- Do linku nepatria parametre ValueTrack (na to slúži `ads_redirect`). Ak link obsahuje tracking parametre, Google odporúča pridať `canonical_link`.

### `image_link`

- Začína `http` **alebo** `https`. HTTPS nie je povinné, ale odporúča sa.
- **Formáty:** JPEG, WebP, PNG, GIF (bez animácie), BMP, TIFF. Prípona súboru musí sedieť s formátom.
- **Veľkosť:**
  - aspoň 500 × 500 px (nová požiadavka, Google ju začne vynucovať 31. 1. 2027),
  - odporúčané okolo 1500 × 1500 px,
  - najviac 64 MP a 16 MB.
- URL najviac 2 000 znakov, ASCII, RFC 3986.
- `robots.txt` musí povoliť Googlebot aj Googlebot-image.
- Obrázok nesmie byť zástupný, s vodoznakom, s promo textom ani s rámčekom.
- Pri zmene obrázka použiť novú URL, Google ho tak rýchlejšie znovu načíta.

### Meta pre porovnanie

- **Len JPEG alebo PNG**, najviac 8 MB.
- Aspoň 500 × 500 px (Shops), odporúčané 1024 × 1024 px.
- Zmenu obrázka Meta zaregistruje len pri novej URL.
- Meta odporúča pre každý variant vlastný link s predvoleným variantom.
- WebP Meta neuvádza. **Do Meta feedu posielať pôvodný JPG/PNG, nie WebP verzie z optimalizačných pluginov.**

**Citácie**
- „Pre-select the correct variant.“ — https://support.google.com/merchants/answer/6324416
- „each URL using a different path segment and/or query parameters“ — https://support.google.com/merchants/answer/12472646
- „ASCII characters only, and RFC 3986 compliant“ — https://support.google.com/merchants/answer/6324416
- „new image size requirements of at least 500 x 500 pixels“ — https://support.google.com/merchants/answer/12472547
- „Images must be in JPEG or PNG format and 8 MB maximum.“ — https://www.facebook.com/business/help/686259348512056
- „Provide a unique link for each variant.“ — [M-variants]

**Zdroje:** https://support.google.com/merchants/answer/6324416, https://support.google.com/merchants/answer/12472646, https://support.google.com/merchants/answer/12472547, https://support.google.com/merchants/answer/12472826, https://www.facebook.com/business/help/686259348512056, [M-variants]

---

## Rozpory a doplnenia k ZADANIE.md (sekcie 1–2)

1. **Meta `availability`:** feed špecifikácia pozná len `in stock|out of stock`. `preorder` a `available for order` sú len v Graph API a microdata, vo feede nie.
2. **Google `backorder` aj `preorder`** vyžadujú `availability_date`. WooCommerce ho nemá, treba doplniť pole alebo nastavenie.
3. **„Duplicitné ID → odmietnutý celý súbor“** zdroje nepotvrdzujú. Meta ignoruje všetky položky s daným ID, Google hlási problém produktu (viď Q).
4. **GTIN z rodiča pre variácie nepreberať.** Google hlási „Duplicate GTIN“ aj pri variantoch s rovnakým GTIN; každý variant má mať vlastný.
   Citácia: „Submitting unique GTINs for each variant“ — https://support.google.com/merchants/answer/12470642
5. **MPN = vlastné SKU** je podľa Google správne len vtedy, keď je obchod výrobcom (napr. vlastná značka obchodu). Inak nie.
   Citácia: „Unless you’re the manufacturer, don’t use a value that you’ve created.“ — https://support.google.com/merchants/answer/12474954
6. **Predvolená značka (fallback)** je podľa Google správna len pri vlastnej výrobe alebo privátnej značke. Hodnoty typu „N/A“ alebo „Generic“ nie sú povolené. Meta má `brand` medzi povinnými poľami a vyžaduje aspoň jedno z `brand`, `gtin`, `mpn`.
   Citácia: „Only provide your own brand name as the brand if you manufacture the product“ — [G-spec]
7. **`identifier_exists`:** Google posiela `no`, keď chýba GTIN a zároveň kombinácia MPN + brand (viď M).
8. **Názov variácie:** Google chce v názve odlišujúce údaje variantu. Dev guide Meta ukazuje rovnaký názov pre všetky varianty. Šablónu názvu treba nastavovať zvlášť pre každý kanál.
9. **Meta `description`** musí mať aspoň 30 znakov a byť čistý text. Google formátovanie (zlomy riadkov, zoznamy) dovoľuje.
10. **Obrázky pre Meta:** len JPEG alebo PNG a najviac 8 MB. WebP sa neposiela.
11. **Polia len pre Meta** (`status`, `quantity_to_sell_on_facebook`, `fb_product_category`, `internal_label`, `additional_variant_attribute`, `rich_text_description`, `video[n].url`) sa podľa Meta dev docs píšu **bez prefixu `g:`**.
12. **Google má `item_group_title` a `variant_option`** (varianty s vlastným rozmerom). Pre atribúty mimo štandardných (napr. `pa_nosnost`) je to náprotivok k Meta `additional_variant_attribute`. Lepšie ako `custom_label_*`, ktoré slúžia len na bidding a reporting.
13. **Časové pásmo v `sale_price_effective_date`:** Google píše `+0200`, Meta v helpe `+02:00`. Zapisovať podľa kanála.
14. **`age_group`:** Meta má navyše `teen` a `all ages`, Google tieto hodnoty nepozná.
15. **Google `google_product_category`** berie len ako prepis v určitých prípadoch. Pre kampane v SK je dôležitý `product_type`. Google taxonómia má aj **sk-SK** verziu s rovnakými ID.
16. **Prázdne polia:** Google chce prázdne atribúty vynechať. Meta pri update schedule maže hodnotu práve prázdnym poľom. Pri replace schedule (predvolený pri plánovanom načítaní) stačí pole vynechať.
17. **Meta varianty:** všetky varianty musia byť v tom istom feede a na produkt ich môže byť najviac 800. Konflikt `id` s `item_group_id` je chyba položky. Pri šablóne `{sku}` treba overiť, či sa ID produktov nekryjú s group ID.

## Rozdiely Google vs Meta

| Pole / téma | Google (hodnota, formát) | Meta (hodnota, formát) |
|---|---|---|
| `id` | 1–50 znakov. Google rozlišuje veľké a malé písmená, ale neodporúča na tom stavať unikátnosť. | Max. 100 znakov, rozlišuje veľké a malé písmená. Pri duplicite Meta ignoruje všetky položky s daným ID. Nesmie sa zhodovať so žiadnym `item_group_id`. |
| `title` | 1–150 znakov (dlhší sa skráti s varovaním). Pri variantoch pridať odlišujúce údaje. | Max. 200 znakov (odporúčané < 65), title case. Dev guide má rovnaký názov pre všetky varianty. |
| `description` | 1–5 000 znakov. Formátovanie (zlomy riadkov, zoznamy) je povolené. | 30–9 999 znakov, len čistý text. |
| `rich_text_description` | — | Max. 9 999 znakov. Len pre Shops, nie pre reklamy. |
| `availability` | `in_stock`, `out_of_stock`, `preorder`, `backorder` | `in stock`, `out of stock` (Graph API a microdata poznajú aj ďalšie hodnoty) |
| `availability_date` | Povinné pri `preorder` a `backorder`. `YYYY-MM-DDThh:mm±hhmm`, max. 25 znakov. | Vo feed špecifikácii nie je (NEOVERENÉ) |
| `condition` | `new`, `refurbished`, `used`. Pri nových produktoch nepovinné. | `new`, `refurbished`, `used`. Povinné. |
| `price` | `15.00 USD`, max. 2 desatinné miesta, v EÚ s DPH | `9.99 USD`, desatinná bodka, bez symbolov meny, jedna mena na súbor |
| `sale_price_effective_date` | `YYYY-MM-DDThh:mm±hhmm/YYYY-MM-DDThh:mm±hhmm`, max. 51 znakov | `YYYY-MM-DDThh:mm±hh:mm/YYYY-MM-DDThh:mm±hh:mm` |
| `image_link` | http(s), JPEG/WebP/PNG/GIF/BMP/TIFF. Min. 500×500 px od 31. 1. 2027, max. 16 MB a 64 MP, URL max. 2 000 znakov. | http(s), **len JPEG/PNG**, min. 500×500 px, max. 8 MB. Zmena obrázka vyžaduje novú URL. |
| `additional_image_link` | Opakovaný `<g:additional_image_link>`, **max. 10**, každá URL max. 2 000 znakov | **Max. 20**. Jedno pole oddelené `,` `;` medzerou alebo `\|`, max. 2 000 znakov. V XML príklade opakovaný `<additional_image_link>` bez prefixu. |
| `brand` | 1–70 znakov. Vlastnú značku uvádzať len pri vlastnej výrobe. | Max. 100 znakov, povinné. Aspoň jedno z `brand`, `gtin`, `mpn` musí byť vyplnené. |
| `gtin` | 8, 12, 13 alebo 14 číslic, max. 10 hodnôt. Každý variant má mať vlastný GTIN. | UPC, EAN, JAN, ISBN-13 alebo ITF-14, bez pomlčiek a medzier |
| `mpn` | 1–70 znakov, len MPN od výrobcu | Max. 100 znakov |
| `identifier_exists` | `yes`, `true`, `no`, `false`. Predvolené `yes`. | — |
| `item_group_id` | 1–50 znakov. Veľkosť písmen hodnoty neodlišuje. | Max. 100 znakov, rozlišuje veľké a malé písmená. Nesmie sa zhodovať so žiadnym `id`. |
| Názov skupiny variantov | `item_group_title`, 1–150 znakov | — |
| Variantné atribúty | `color`, `size`, `material`, `pattern`, `gender`, `age_group` + `variant_option` | `color`, `size`, `material`, `pattern`, `gender` + `additional_variant_attribute` |
| Vlastný variantný atribút | `<g:variant_option><g:name>…</g:name><g:value>…</g:value></g:variant_option>`, max. 30 hodnôt, name aj value po 250 znakov | `<additional_variant_attribute><label>…</label><value>…</value></additional_variant_attribute>`. V CSV `Label:Value, Label:Value`. Limity NEOVERENÉ. |
| Limit variantov | NEOVERENÉ | Max. 800 na produkt (nad 300 varovanie), všetky v tom istom feede |
| `gender` | `male`, `female`, `unisex` | `female`, `male`, `unisex` |
| `age_group` | `newborn`, `infant`, `toddler`, `kids`, `adult` | `adult`, `all ages`, `teen`, `kids`, `toddler`, `infant`, `newborn` |
| `color` | Spolu max. 100 znakov, max. 40 na farbu, najviac 3 farby oddelené `/` | Max. 200 znakov, farba slovom |
| `size` | Max. 100 znakov, viac hodnôt oddelených `/` | Max. 200 znakov („US 12“, bez slova „size“) |
| `material` | Max. 200 znakov, najviac 3 materiály oddelené `/` | Max. 200 znakov |
| `pattern` | Max. 100 znakov | Max. 100 znakov |
| `google_product_category` | ID alebo celá cesta (nie oboje), odporúčané ID. Google ju berie len ako prepis v určitých prípadoch. | Názov (veľkosť písmen nerozhoduje) alebo ID |
| `fb_product_category` | — | Názov alebo ID z FB taxonómie (2 967 kategórií, existuje aj sk_SK) |
| `product_type` | Max. 750 znakov, až 5 hodnôt, pre bidding sa použije prvá | Max. 750 znakov |
| `custom_label_0–4` | 1–100 znakov, max. 1 000 unikátnych hodnôt na label | Max. 100 znakov. Meta ich používa v kreatíve a pre sady odporúča `internal_label`. |
| `internal_label` | — | `['a','b']`, v XML opakovaný `<internal_label>`. Max. 110 znakov na label a 5 000 labelov. |
| Archivácia a pozastavenie | `pause` (max. 14 dní) alebo `excluded_destination`. `out_of_stock` sa na to nepoužíva. | `status`: `active` / `archived` (starý názov `visibility`) |
| Počet kusov | — | `quantity_to_sell_on_facebook` (celé číslo, len Shops, starý názov `inventory`) |
| `shipping` | Vnorené `<g:shipping>` so sub-atribútmi, najviac 100-krát, cena v EUR 0–1000 | Reťazec `Country:Region:Service:Price`. Vzorový RSS od Meta používa vnorený `<g:shipping>`. Cena `0.0` zapne overlay „Free shipping“. |
| `shipping_weight` | `3 kg` (lb, oz, g, kg), desatinné čísla povolené, 0–1000 kg | `0.3 kg` (lb, oz, g, kg) |
| `link` | Overená doména, ASCII, RFC 3986, max. 2 000 znakov. Predvolený variant a iná URL pre každý variant sú odporúčané. | http(s), doména firmy. Odporúča sa vlastný link pre každý variant s predvoleným variantom. |
| Prefix v XML | `g:` je povinný, inak Google atribút ignoruje (okrem RSS `title`, `link`, `description`) | `g:` pre Google atribúty. Polia len pre Meta bez prefixu (tvar `g:` pri nich NEOVERENÝ). |
| Prázdne polia | Vynechať | Pri update schedule prázdne pole zmaže hodnotu |
| Duplicitné `id` v súbore | Problém produktu v Needs attention. Správanie v rámci jedného súboru NEOVERENÉ. | Meta ignoruje všetky položky s daným ID, zvyšok súboru spracuje |
| Formát súboru | Prípona `.xml`, max. 4 GB, namespace `xmlns:g="http://base.google.com/ns/1.0"` | Začína `<?xml`, jedno pole na riadok, riadok max. 5 MB, plánovaný feed max. 4 GB |
| Produkt vypadne z feedu | Google ho odstráni z GMC | Replace schedule ho zmaže, update schedule ho ponechá |
| Taxonómia v slovenčine | `taxonomy-with-ids.sk-SK.txt` (rovnaké ID ako en-US) | `products/categories/sk_SK.txt` (rovnaké ID ako en_US) |

---

## Rozhodnutia vo verzii 1.0.1 (ZADANIE §9, 2026-09-17)

Podklad: kontrola verzie 1.0.0 na produkčnom e-shope a report Meta k prvému nahratiu (0 blokujúcich chýb, 3 odporúčania „Brand or universal ID is missing“).

| Téma | 1.0.0 | 1.0.1 | Zdroj a riziko |
|---|---|---|---|
| Meta `availability` pri tovare na objednávku | `in stock` | `available for order`, predobjednávka `preorder`. Vo feede sa dá prepnúť na `in stock`. | Referencia Meta pre feed stále uvádza len „Supported values: `in stock`, `out of stock`“ ([M-ref], overené 17. 9.). Graph API, OpenGraph/microdata a návody k feedom od tretích strán uvádzajú aj `available for order`. Ak by Commerce Manager hodnotu odmietol, prepnúť voľbu vo feede. |
| Meta `quantity_to_sell_on_facebook` pri tovare na objednávku | posielal sa (aj 0) | neposiela sa | 0 kusov by sa čítalo ako vypredané, čo je v rozpore s `available for order` aj s `in stock`. |
| Prázdne polia | Meta dostávala prázdny `<g:sale_price>` (kvôli update schedule) | nezapisuje sa žiadny prázdny element, ani v hlavičke kanála | Pri plánovanom načítaní typu **replace** (predvolené) Meta položku nahradí celú, takže vynechaná zľava zmizne. Prázdna hodnota je potrebná len pri **update** schedule — ten sa pre tento feed nepoužíva. |
| Prefix polí len pre Meta | bez `g:` (podľa textu dev docs) | `g:` pri všetkých poliach položky, vrátane `g:status`, `g:quantity_to_sell_on_facebook`, `g:internal_label`, `g:fb_product_category`, `g:additional_variant_attribute` s `g:label`/`g:value` | Vzorový RSS od Meta [M-sample] má všetky polia položky s `g:`. Meta na produkčnom e-shope prijala súbor so zmiešanými tvarmi. |
| „Chýba značka, GTIN aj MPN“ | chyba | upozornenie | Meta to hlási ako odporúčanie, položku neodmietne. |
| Predvolená značka | použila sa, v reporte nebolo vidieť kde | report „Značka z nastavenia feedu“ (aj variácie) a tip s odkazom, ak značka chýba a predvolená nie je nastavená | ZADANIE §9.3 |
