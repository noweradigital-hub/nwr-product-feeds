# Integračné testy (WordPress Playground)

Skutočný plugin vo WordPresse s WooCommerce (PHP 8.3). Skripty v `scripts/` sa pripájajú do `/wordpress/nwr-tests/` a nikdy sa nenasadzujú.

```bash
npx --yes @wp-playground/cli@latest server --port=9420 --php=8.3 --login \
  --blueprint=tests/playground/blueprint.json \
  --mount=plugin/nwr-product-feeds:/wordpress/wp-content/plugins/nwr-product-feeds \
  --mount=tests/playground/scripts:/wordpress/nwr-tests
```

V druhom termináli z koreňa repozitára:

```bash
npm run test:wp
```

Prvé spustenie sťahuje WordPress aj WooCommerce (minúta–dve). `seed.php` je idempotentný, sadu možno púšťať opakovane.

| Skript | Čo robí |
|---|---|
| `mu-helpers.php` | mu-plugin: vypne WP-Cron a async runner, pridá typy `composite` a `gift-card`, prepínače `hijack` (Woodmart-like `pre_get_posts`) a `fail` (výnimka pri generovaní) |
| `seed.php` | katalóg so všetkými prípadmi zo zadania (skrytý rodič, variácie bez SKU, vypredané, akcia s dátumami, Elementor, zlý EAN, WebP, composite, darčeková karta…) |
| `feeds.php` | 4 feedy: Google XML, Meta XML, Google CSV s filtrami, Meta TSV bez DPH |
| `run.php`, `jobs.php` | spustí generovanie a vykoná úlohy Action Scheduleru (`force=` aj neskoršie) |
| `state.php`, `diag.php` | stav behov, reporty, súbory, log, naplánované úlohy, priečinok feedov |
| `expect.php`, `expect-sql.php` | pohľad WP_Query (aj s „hijackom“) a nezávislý SQL výpočet obsahu feedu |
| `item-ids.php`, `touch.php`, `set.php`, `guard.php`, `sample.php` | ID z `nwr_pf_item_id()`, zmeny produktov (cena, sklad, tovar na objednávku), prepínače, poistka poklesu, ukážka |
| `upgrade.php` | simuluje aktualizáciu z 1.0.0 (pregenerovanie feedov po aktualizácii) |
| `panel.php`, `login-cookie.php` | záložka „Feedy“ (render + uloženie) a prihlásenie pre admin testy |
| `transfer.php`, `updater.php`, `migration.php`, `multistore.php`, `perf.php` | export/import, GitHub updater (mock), migrácia z prvej verzie 1.0.0, poistka Multistore, 300 produktov |

Záludnosti:
- Playground pred JSON výstup vypíše `Warning: Constant WP_DEBUG already defined` — testy ho preskočia, ale výstup pluginu pred JSON by test zhodil.
- `--login` odpovie prvej požiadavke návštevníka presmerovaním; cookie `playground_auto_login_already_happened=1` to preskočí.
- Admin stránky sa otvárajú s cookies z `login-cookie.php`; odkazy v HTML majú `&#038;` namiesto `&`.
- `WC_Admin_Meta_Boxes::add_error()` uloží chyby až na `shutdown` inštancie triedy — test ich číta zo statickej vlastnosti.
- Vyhľadávanie Google kategórií sťahuje taxonómiu z google.com (Playground má zapnutú sieť).
