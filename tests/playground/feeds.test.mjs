// Integration tests for nwr-product-feeds, run against WordPress Playground with
// WooCommerce (see README). Start Playground first, then:
//   node --test tests/playground/feeds.test.mjs
// NWR_PF_PERF=1 adds the 300-product batch test.
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const BASE = process.env.NWR_WP || 'http://127.0.0.1:9420';
const TMP = mkdtempSync(join(tmpdir(), 'nwr-pf-'));

// ------------------------------------------------------------------ helpers

async function php(script, params = {}) {
  const url = new URL(`${BASE}/nwr-tests/${script}`);
  for (const [k, v] of Object.entries(params)) url.searchParams.set(k, typeof v === 'string' ? v : JSON.stringify(v));
  const res = await fetch(url);
  const text = await res.text();
  if (/NWR_FATAL|Fatal error/.test(text)) throw new Error(`${script}: ${text.slice(0, 2000)}`);
  const start = text.indexOf('{');
  if (start < 0) throw new Error(`${script} → ${res.status}: ${text.slice(0, 800)}`);
  // Playground itself prints a WP_DEBUG warning before the output; ours must never appear.
  assert.doesNotMatch(text.slice(0, start), /nwr-product-feeds/, `${script}: plugin output before JSON`);
  const data = JSON.parse(text.slice(start));
  assert.deepEqual(data._notices ?? [], [], `${script}: PHP notices from the plugin`);
  return data;
}

async function download(url) {
  const res = await fetch(url);
  assert.equal(res.status, 200, `GET ${url}`);
  return Buffer.from(await res.arrayBuffer());
}

const decode = (s) =>
  s
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&apos;/g, "'")
    .replace(/&#(\d+);/g, (_, n) => String.fromCharCode(Number(n)))
    .replace(/&amp;/g, '&');

// Playground's --login answers a visitor's first request with a redirect; this cookie skips it.
const VISITOR = { cookie: 'playground_auto_login_already_happened=1' };

/** Items of our RSS output (one field per line, nested nodes indented). */
function parseItems(xml) {
  const items = [];
  let item = null;
  let node = null;
  const add = (obj, key, value) => {
    obj[key] = key in obj ? [].concat(obj[key], [value]) : value;
  };
  for (const line of xml.split('\n')) {
    let m;
    if (line === '\t<item>') item = {};
    else if (line === '\t</item>') {
      items.push(item);
      item = null;
    } else if (!item) continue;
    else if ((m = line.match(/^\t\t<([\w:]+)>(.*)<\/\1>$/))) add(item, m[1], decode(m[2]));
    else if ((m = line.match(/^\t\t<([\w:]+)><\/\1>$/))) add(item, m[1], '');
    else if ((m = line.match(/^\t\t<([\w:]+)>$/))) node = { name: m[1], value: {} };
    else if (node && (m = line.match(/^\t\t\t<([\w:]+)>(.*)<\/\1>$/))) node.value[m[1]] = decode(m[2]);
    else if (node && line.startsWith('\t\t</')) {
      add(item, node.name, node.value);
      node = null;
    } else throw new Error(`Unexpected line in feed: ${JSON.stringify(line)}`);
  }
  return items;
}

const list = (v) => (v === undefined ? [] : [].concat(v));
const byId = (items) => Object.fromEntries(items.map((i) => [i['g:id'], i]));

function parseCsv(text) {
  const rows = [];
  let row = [];
  let cell = '';
  let quoted = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (quoted) {
      if (c === '"' && text[i + 1] === '"') {
        cell += '"';
        i++;
      } else if (c === '"') quoted = false;
      else cell += c;
    } else if (c === '"') quoted = true;
    else if (c === ',') {
      row.push(cell);
      cell = '';
    } else if (c === '\n') {
      row.push(cell);
      rows.push(row);
      row = [];
      cell = '';
    } else cell += c;
  }
  return rows;
}

function xmllint(buffer, name) {
  const file = join(TMP, name);
  writeFileSync(file, buffer);
  execFileSync('xmllint', ['--noout', file], { stdio: 'pipe' });
}

let ids;
let feeds;
let state;
const refresh = async () => (state = await php('state.php'));
const feedState = (key) => state.feeds[feeds[key]];
const fetchFeed = async (key) => {
  await refresh();
  const buffer = await download(feedState(key).file.url);
  return { buffer, text: buffer.toString('utf8'), items: parseItems(buffer.toString('utf8')) };
};
const run = (feed = 'all', extra = {}) => php('run.php', { feed, ...extra });

// -------------------------------------------------------------------- setup

before(async () => {
  for (let i = 0; ; i++) {
    const d = await php('diag.php').catch(() => ({}));
    if (d.woo_class && d.pf_loaded && d.composite) break;
    if (i > 90) throw new Error('Playground with WooCommerce and the plugin never became ready');
    await new Promise((r) => setTimeout(r, 2000));
  }
  ids = await php('seed.php');
  feeds = await php('feeds.php', { fresh: '1' });
  await php('set.php', { test: 'hijack', value: '0' });
  await php('set.php', { test: 'fail', value: '0' });
  await php('set.php', { setting: 'id_template', value: '{id}' });
  const result = await run();
  for (const status of Object.values(result.requested)) assert.equal(status, 'started');
  await refresh();
});

after(async () => {
  await php('set.php', { test: 'hijack', value: '0' }).catch(() => {});
  await php('set.php', { test: 'fail', value: '0' }).catch(() => {});
});

const GOOGLE_IDS = () =>
  ['simple', 'sale', 'oos', 'elementor_a', 'elementor_b', 'special', 'yoast', 'meta_only_excluded', 'classic_modra', 'classic_ruzova', 'rast_1', 'rast_2', 'rast_any', 'webp', 'invalid_gtin']
    .map((k) => String(ids[k]))
    .sort();

// ------------------------------------------------------------ storage & file

test('the feed folder is protected and holds no leftovers', async () => {
  const d = await php('diag.php');
  assert.ok(d.dir_files.includes('index.php'));
  assert.ok(d.dir_files.includes('.htaccess'));
  assert.match(d.htaccess, /^# nwr-product-feeds\nOptions -Indexes\n/);
  assert.match(d.tmp_htaccess, /Require all denied/);
  assert.deepEqual(d.tmp_files.filter((f) => f.endsWith('.part')), [], 'no temp files after finished runs');
});

test('every feed is a static file with a random token in its URL', async () => {
  for (const [key, ext] of Object.entries({ google: 'xml', meta: 'xml', csv: 'csv', tsv: 'tsv' })) {
    const f = feedState(key);
    assert.equal(f.run.status, 'idle', `${key} finished`);
    assert.ok(f.file.exists, `${key} file exists`);
    assert.match(f.file.url, new RegExp(`/wp-content/uploads/nwr-feeds/[a-f0-9]{16}-${f.feed.slug}\\.${ext}$`));
    await download(f.file.url);
  }
});

test('XML feeds are well-formed UTF-8 without BOM and start with <?xml', async () => {
  for (const key of ['google', 'meta']) {
    const { buffer } = await fetchFeed(key);
    assert.equal(buffer.subarray(0, 5).toString(), '<?xml', key);
    assert.notDeepEqual([...buffer.subarray(0, 3)], [0xef, 0xbb, 0xbf], `${key} has no BOM`);
    new TextDecoder('utf-8', { fatal: true }).decode(buffer);
    xmllint(buffer, `${key}.xml`);
  }
});

// ----------------------------------------------------------- items and IDs

test('Google feed: simple products and variations, never variable parents', async () => {
  const { items } = await fetchFeed('google');
  const got = items.map((i) => i['g:id']).sort();
  assert.deepEqual(got, GOOGLE_IDS());
  assert.equal(new Set(got).size, got.length, 'IDs are unique');
  for (const parent of ['classic', 'rast', 'variable_empty']) assert.ok(!got.includes(String(ids[parent])), `${parent} is not an item`);
  const g = byId(items);
  for (const v of ['classic_modra', 'classic_ruzova']) assert.equal(g[ids[v]]['g:item_group_id'], String(ids.classic));
  for (const v of ['rast_1', 'rast_2', 'rast_any']) assert.equal(g[ids[v]]['g:item_group_id'], String(ids.rast));
  assert.equal(g[ids.simple]['g:item_group_id'], undefined);
  assert.equal(g[ids.classic_modra]['g:item_group_title'], 'Demo Classic');
});

test('a hidden variable product keeps its variations in the feed', async () => {
  const { items } = await fetchFeed('google');
  const g = byId(items);
  assert.ok(g[ids.classic_modra] && g[ids.classic_ruzova]);
  assert.ok(!g[ids.hidden], 'a hidden simple product is left out');
});

test('a variation without its own SKU never gets the parent SKU as ID or MPN', async () => {
  const { items } = await fetchFeed('google');
  const g = byId(items);
  assert.ok(!items.some((i) => ['DM-CL', 'DM-RAST'].includes(i['g:id'])));
  assert.equal(g[ids.classic_ruzova]['g:mpn'], undefined);
  assert.equal(g[ids.classic_modra]['g:mpn'], 'DM-CL-M');
  assert.equal(g[ids.simple]['g:mpn'], 'DM-OSU-1');
});

test('both feeds carry the fields their platform requires', async () => {
  for (const key of ['google', 'meta']) {
    const { text, items } = await fetchFeed(key);
    assert.doesNotMatch(text, /<([\w:]+)>\s*<\/\1>/, `${key}: no empty elements`);
    for (const item of items) assert.ok(Object.values(item).every((v) => v !== ''), `${key}: ${item['g:id']} has no empty field`);
  }
  const google = (await fetchFeed('google')).items;
  for (const item of google) {
    for (const f of ['g:id', 'g:title', 'g:description', 'g:link', 'g:image_link', 'g:availability', 'g:price', 'g:condition']) {
      assert.ok(item[f], `${item['g:id']} has ${f}`);
    }
    assert.ok(item['g:brand'] || item['g:identifier_exists'] === 'no', `${item['g:id']} has brand or identifier_exists`);
    assert.match(item['g:price'], /^\d+\.\d{2} EUR$/);
    assert.ok(item['g:title'].length <= 150);
  }
  const meta = (await fetchFeed('meta')).items;
  for (const item of meta) {
    for (const f of ['g:id', 'g:title', 'g:description', 'g:availability', 'g:condition', 'g:price', 'g:link', 'g:image_link', 'g:brand', 'g:status']) {
      assert.ok(item[f], `${item['g:id']} has ${f}`);
    }
  }
});

test('Meta feed: its own availability words and status, every field with g:', async () => {
  const { items } = await fetchFeed('meta');
  const m = byId(items);
  assert.deepEqual(
    items.map((i) => i['g:id']).sort(),
    GOOGLE_IDS().filter((id) => id !== String(ids.meta_only_excluded)),
    'the product excluded from this feed only is missing',
  );
  for (const item of items) {
    for (const key of Object.keys(item)) assert.match(key, /^g:/, `${item['g:id']}: ${key} has the prefix`);
  }
  assert.deepEqual([...new Set(items.map((i) => i['g:availability']))].sort(), ['available for order', 'in stock', 'out of stock']);
  assert.equal(m[ids.oos]['g:availability'], 'out of stock');
  assert.equal(m[ids.rast_2]['g:availability'], 'available for order', 'backorder');
  assert.ok(items.every((i) => i['g:status'] === 'active'));
  assert.equal(m[ids.rast_1]['g:quantity_to_sell_on_facebook'], '5');
  assert.equal(m[ids.oos]['g:quantity_to_sell_on_facebook'], '0');
  assert.equal(m[ids.simple]['g:quantity_to_sell_on_facebook'], undefined, 'no stock tracking, no quantity');
  assert.deepEqual(m[ids.rast_1]['g:additional_variant_attribute'], { 'g:label': 'Nosnosť', 'g:value': '10 kg' });
  assert.equal(m[ids.simple]['g:sale_price'], undefined, 'no sale, no sale_price element');
  assert.equal(m[ids.sale]['g:sale_price'], '49.90 EUR');
  assert.match(m[ids.sale]['g:sale_price_effective_date'], /^\d{4}-\d\d-\d\dT\d\d:\d\d[+-]\d\d:\d\d\/\d{4}-\d\d-\d\dT\d\d:\d\d[+-]\d\d:\d\d$/);
  assert.equal(m[ids.special]['g:fb_product_category'], '12');
  assert.deepEqual(list(m[ids.simple]['g:internal_label']), ['cat_doplnky', 'tag_novinka']);
  assert.ok(!items.some((i) => list(i['g:internal_label']).includes('cat_uncategorized')));
  assert.ok(!items.some((i) => i['g:link'].includes('utm_')), 'no UTM configured for Meta');
  for (const v of ['rast_1', 'rast_2', 'rast_any']) assert.equal(m[ids[v]]['g:brand'], 'DEMO', `${v}: the feed's default brand reaches variations`);
});

test('Meta feed: goods on order with tracked stock (0 pieces) send no quantity', async () => {
  const on = await php('touch.php', { product: 'rast_2', manage: '1', qty: '0', backorders: 'yes' });
  assert.equal(on.stock_status, 'onbackorder');
  try {
    await run(feeds.meta);
    const m = byId((await fetchFeed('meta')).items);
    assert.equal(m[ids.rast_2]['g:availability'], 'available for order');
    assert.equal(m[ids.rast_2]['g:quantity_to_sell_on_facebook'], undefined);
    assert.equal(m[ids.rast_1]['g:quantity_to_sell_on_facebook'], '5');

    await php('set.php', { feed: feeds.meta, field: 'backorder', value: 'in_stock' });
    await run(feeds.meta);
    const s = byId((await fetchFeed('meta')).items);
    assert.equal(s[ids.rast_2]['g:availability'], 'in stock', 'feed option: backorder = in stock');
    assert.equal(s[ids.rast_2]['g:quantity_to_sell_on_facebook'], undefined, '0 pieces would contradict "in stock"');
  } finally {
    await php('set.php', { feed: feeds.meta, field: 'backorder', value: 'backorder' });
    const off = await php('touch.php', { product: 'rast_2', manage: '0', backorders: 'no', status: 'onbackorder' });
    assert.equal(off.stock_status, 'onbackorder');
    await php('jobs.php', { force: 'nwr_pf_dirty' });
    await run();
  }
});

test('Google feed: prices, sales, availability and identifiers', async () => {
  const g = byId((await fetchFeed('google')).items);
  const sale = g[ids.sale];
  assert.equal(sale['g:price'], '59.90 EUR');
  assert.equal(sale['g:sale_price'], '49.90 EUR');
  assert.match(sale['g:sale_price_effective_date'], /^\d{4}-\d\d-\d\dT\d\d:\d\d[+-]\d{4}\/\d{4}-\d\d-\d\dT\d\d:\d\d[+-]\d{4}$/);
  assert.equal(g[ids.classic_ruzova]['g:sale_price'], '79.00 EUR');
  assert.equal(g[ids.classic_ruzova]['g:sale_price_effective_date'], undefined, 'sale without dates');
  assert.equal(g[ids.simple]['g:sale_price'], undefined);
  assert.equal(g[ids.oos]['g:availability'], 'out_of_stock');
  assert.equal(g[ids.rast_2]['g:availability'], 'backorder');
  assert.match(g[ids.rast_2]['g:availability_date'], /^\d{4}-\d\d-\d\dT\d\d:\d\d[+-]\d{4}$/);
  assert.equal(g[ids.simple]['g:gtin'], '4006381333931');
  assert.equal(g[ids.simple]['g:identifier_exists'], undefined);
  assert.equal(g[ids.sale]['g:identifier_exists'], 'no');
  assert.equal(g[ids.invalid_gtin]['g:gtin'], undefined, 'invalid GTIN is not sent');
  assert.equal(g[ids.classic_modra]['g:gtin'], '4006381333900');
  assert.equal(g[ids.classic_ruzova]['g:gtin'], undefined, 'no GTIN borrowed from the parent by default');
});

test('titles, descriptions and links are clean text', async () => {
  const google = byId((await fetchFeed('google')).items);
  assert.equal(google[ids.classic_modra]['g:title'], 'Demo Classic – Modrá');
  assert.equal(google[ids.rast_1]['g:title'], 'Rastúci Demo – Modrá, 10 kg');
  assert.equal(google[ids.rast_any]['g:title'], 'Rastúci Demo – 15 kg');
  assert.equal(google[ids.rast_any]['g:color'], undefined, 'an "any" color is not replaced by the parent list');
  assert.equal(google[ids.rast_1]['g:color'], 'Modrá');
  assert.equal(google[ids.classic_modra]['g:material'], 'Merino', 'non-variation attribute from the parent');
  assert.equal(google[ids.special]['g:title'], 'Vak "Classic" & Plus 😀');
  const desc = google[ids.simple]['g:description'];
  assert.equal(desc, 'Mäkká osuška & uterák pre deti 100 % bavlna 70 × 140 cm');
  assert.ok(!/[<>]|\[vc_/.test(desc));
  const special = google[ids.special]['g:description'];
  assert.ok(!special.includes('\u000b'), 'control characters removed');
  assert.ok(special.includes('[2 ks]'), 'plain brackets survive');
  assert.ok(!special.includes('neznamy_shortcode'));
  assert.equal(google[ids.elementor_a]['g:description'], 'Elementor bez krátkeho popisu', 'Elementor dump ignored, title as fallback');
  assert.equal(google[ids.elementor_b]['g:description'], 'Krátky popis produktu, ktorý postavili v Elementore.');
  assert.equal(google[ids.rast_any]['g:description'], 'Popis variácie pre ľubovoľnú farbu.', 'variation description first');
  assert.match(google[ids.classic_modra]['g:link'], /attribute_pa_farba=modra/);
  assert.match(google[ids.simple]['g:link'], /utm_source=google&utm_medium=product%20feed$/);
  assert.ok(!Object.values(google).some((i) => /add-to-cart/.test(i['g:link'])));
  for (const item of Object.values(google)) assert.match(item['g:link'], /^[\x21-\x7e]+$/, 'ASCII link');
});

test('categories: Yoast primary, parent mapping, no default category', async () => {
  const g = byId((await fetchFeed('google')).items);
  assert.equal(g[ids.yoast]['g:product_type'], 'Deky & prikrývky');
  assert.equal(g[ids.classic_modra]['g:product_type'], 'Detské vaky > Zimné');
  assert.equal(g[ids.classic_modra]['g:google_product_category'], '537', 'subcategory inherits the mapping');
  assert.equal(g[ids.elementor_a]['g:product_type'], undefined, 'Uncategorized is not a product type');
  assert.equal(g[ids.simple]['g:google_product_category'], undefined);
});

test('images: variation image first, then its gallery, then the parent', async () => {
  const g = byId((await fetchFeed('google')).items);
  const modra = g[ids.classic_modra];
  assert.match(modra['g:image_link'], /classic-modra\.jpg$/);
  assert.deepEqual(list(modra['g:additional_image_link']).map((u) => u.split('/').pop()), ['vg-extra.jpg', 'classic.jpg', 'classic-2.jpg']);
  assert.match(g[ids.classic_ruzova]['g:image_link'], /\/classic\.jpg$/);
  assert.deepEqual(list(g[ids.simple]['g:additional_image_link']).map((u) => u.split('/').pop()), ['osuska-2.jpg', 'osuska-3.jpg']);
});

test('custom labels follow the feed rules', async () => {
  const g = byId((await fetchFeed('google')).items);
  const modra = g[ids.classic_modra];
  assert.equal(modra['g:custom_label_0'], 'Detské vaky');
  assert.equal(modra['g:custom_label_1'], 'bez-akcie');
  assert.equal(modra['g:custom_label_2'], '60+');
  assert.equal(modra['g:custom_label_3'], undefined);
  assert.equal(modra['g:custom_label_4'], 'test');
  assert.equal(g[ids.rast_1]['g:custom_label_3'], '10 kg');
  assert.equal(g[ids.classic_ruzova]['g:custom_label_1'], 'akcia');
  assert.equal(g[ids.simple]['g:custom_label_2'], '0-30');
});

// ----------------------------------------------------------------- report

test('the report names every left-out product with its reason', async () => {
  await refresh();
  const report = feedState('google').report;
  const skipped = Object.fromEntries(Object.entries(report.skipped).map(([code, e]) => [code, e.sample.map((s) => s[0]).sort((a, b) => a - b)]));
  assert.deepEqual(skipped, {
    hidden: [ids.hidden],
    no_price: [ids.noprice],
    no_image: [ids.noimage],
    password: [ids.password],
    not_published: [ids.draft],
    excluded: [ids.excluded],
    variation_disabled: [ids.classic_siva_off],
    no_variations: [ids.variable_empty],
    type: [ids.composite, ids.composite_zero, ids.composite_dyn, ids.giftcard, ids.external, ids.grouped].sort((a, b) => a - b),
  });
  assert.equal(report.items, 15);
  assert.equal(report.counts.simple, 10);
  assert.equal(report.counts.variations, 5);
  assert.equal(report.counts.out_of_stock, 1);
  assert.equal(report.counts.skipped, 14);
  const issues = (code) => (report.issues[code]?.sample ?? []).map((s) => s[0]);
  assert.deepEqual(issues('invalid_gtin'), [ids.invalid_gtin]);
  assert.deepEqual(issues('any_attribute'), [ids.rast_any]);
  assert.deepEqual(issues('builder_description'), [ids.elementor_a]);
  assert.deepEqual(issues('availability_date_guessed'), [ids.rast_2]);
  assert.ok(issues('variation_no_image').includes(ids.classic_ruzova));
  const brandDefault = report.issues.brand_default.sample;
  for (const v of ['rast_1', 'rast_2', 'rast_any']) {
    assert.deepEqual(brandDefault.find((s) => s[0] === ids[v]), [ids[v], ids.rast, 'DEMO'], `${v} got the default brand`);
  }
  assert.ok(!issues('brand_default').includes(ids.simple), 'a product with a brand term keeps it');
  assert.equal(report.issues.missing_brand, undefined);
  const meta = feedState('meta').report;
  assert.deepEqual(meta.issues.image_format.sample.map((s) => s[0]), [ids.webp]);
  assert.deepEqual(meta.skipped.excluded.sample.map((s) => s[0]).sort((a, b) => a - b), [ids.excluded, ids.meta_only_excluded]);
});

test('the feed matches an independent SQL count of the catalog', async () => {
  const sql = await php('expect-sql.php');
  const { items } = await fetchFeed('google');
  const expected = [...sql.simple, ...sql.variations].map(String).sort();
  // The SQL knows nothing about per-feed exclusions of other feeds; the Google test feed has none.
  assert.deepEqual(items.map((i) => i['g:id']).sort(), expected);
  assert.equal(feedState('google').report.census.variations, 5);
});

test('a theme that rewrites product queries does not change the feed', async () => {
  const before = (await fetchFeed('google')).items.map((i) => i['g:id']).sort();
  await php('set.php', { test: 'hijack', value: '1' });
  try {
    const probe = await php('expect.php');
    assert.ok(probe.wp_query.types.product_variation > 0, 'the simulated theme injects variations');
    assert.ok(!probe.wp_query.ids.includes(ids.rast), 'the simulated theme hides variable parents');
    await run(feeds.google);
    const after = (await fetchFeed('google')).items.map((i) => i['g:id']).sort();
    assert.deepEqual(after, before);
  } finally {
    await php('set.php', { test: 'hijack', value: '0' });
  }
});

// -------------------------------------------------------------- ID template

test('nwr_pf_item_id() returns exactly the feed IDs', async () => {
  const map = await php('item-ids.php');
  const g = byId((await fetchFeed('google')).items);
  for (const key of ['simple', 'sale', 'classic_modra', 'classic_ruzova', 'rast_1']) {
    assert.ok(g[map[key].item], `${key}: ${map[key].item} is in the feed`);
  }
  assert.equal(map.classic.item, g[ids.classic_modra]['g:item_group_id'], 'parent ID = item_group_id');
  assert.equal(map.classic_ruzova.sku, 'DM-CL', 'WooCommerce reports the inherited SKU…');
  assert.equal(map.classic_ruzova.item, String(ids.classic_ruzova), '…but the item ID is the variation');
});

test('template {sku} uses only own SKUs and stays unique', async () => {
  await php('set.php', { setting: 'id_template', value: '{sku}' });
  try {
    await run(feeds.google);
    const { items } = await fetchFeed('google');
    const g = byId(items);
    const map = await php('item-ids.php');
    assert.ok(g['DM-OSU-1'], 'simple product by SKU');
    assert.ok(g['DM-CL-M'], 'variation with its own SKU');
    assert.ok(g[String(ids.classic_ruzova)], 'variation without SKU falls back to its ID');
    assert.equal(g['DM-CL-M']['g:item_group_id'], 'DM-CL');
    assert.equal(g[String(ids.rast_1)]['g:item_group_id'], 'DM-RAST');
    assert.equal(new Set(items.map((i) => i['g:id'])).size, items.length);
    for (const key of ['simple', 'classic_modra', 'classic_ruzova', 'rast_1']) assert.ok(g[map[key].item], `${key} tracked as ${map[key].item}`);
  } finally {
    await php('set.php', { setting: 'id_template', value: '{id}' });
  }
  await run(feeds.google);
});

test('a feed-level template affects only that feed, not tracking', async () => {
  await php('set.php', { feed: feeds.google, field: 'id_template', value: 'wc_{id}' });
  try {
    await run(feeds.google);
    const g = byId((await fetchFeed('google')).items);
    assert.ok(g[`wc_${ids.simple}`]);
    assert.equal(g[`wc_${ids.classic_modra}`]['g:item_group_id'], `wc_${ids.classic}`);
    const map = await php('item-ids.php');
    assert.equal(map.simple.item, String(ids.simple), 'tracking keeps the shared template');
    assert.equal(map._google_scoped_simple, `wc_${ids.simple}`);
    const meta = byId((await fetchFeed('meta')).items);
    assert.ok(meta[String(ids.simple)], 'other feeds unchanged');
  } finally {
    await php('set.php', { feed: feeds.google, field: 'id_template', value: '' });
  }
  await run(feeds.google);
});

// ------------------------------------------------------------ CSV and TSV

test('CSV feed: category, tag and price filters, fixed-price composite, shipping', async () => {
  const { text } = await fetchFeed('csv');
  assert.ok(!text.startsWith('\ufeff'));
  const rows = parseCsv(text);
  const [header, ...data] = rows;
  assert.equal(header[0], 'id');
  assert.ok(header.includes('shipping') && !header.includes('g:id'));
  for (const row of data) assert.equal(row.length, header.length);
  const col = (row, name) => row[header.indexOf(name)];
  assert.deepEqual(data.map((r) => r[0]).sort(), [String(ids.yoast), String(ids.composite)].sort());
  assert.equal(col(data.find((r) => r[0] === String(ids.composite)), 'shipping'), 'SK::Kuriér:3.90 EUR');
  const report = feedState('csv').report;
  assert.deepEqual(report.skipped.filter_tag.sample.map((s) => s[0]), [ids.sale]);
  assert.deepEqual(report.skipped.no_price.sample.map((s) => s[0]), [ids.composite_zero]);
  assert.deepEqual(report.skipped.dynamic_price.sample.map((s) => s[0]), [ids.composite_dyn]);
  assert.ok(report.skipped.filter_category.sample.some((s) => s[0] === ids.simple));
});

test('TSV feed: prices without VAT and no sold-out items', async () => {
  const { text } = await fetchFeed('tsv');
  const [header, ...rows] = text.trimEnd().split('\n').map((l) => l.split('\t'));
  for (const row of rows) assert.equal(row.length, header.length);
  const byRow = Object.fromEntries(rows.map((r) => [r[0], Object.fromEntries(header.map((h, i) => [h, r[i]]))]));
  assert.equal(byRow[ids.simple].price, '20.24 EUR', '24.90 / 1.23');
  assert.equal(byRow[ids.sale].sale_price, '40.57 EUR');
  assert.ok(!byRow[ids.oos], 'sold-out filtered out in this feed');
  assert.equal(Object.keys(byRow).length, 9);
});

// ------------------------------------------------------------ robustness

test('a failed run keeps the last good file', async () => {
  await refresh();
  const before = feedState('google').file.md5;
  await php('set.php', { test: 'fail', value: '1' });
  try {
    await run(feeds.google);
    await refresh();
    const f = feedState('google');
    assert.equal(f.run.status, 'failed');
    assert.match(f.run.error, /Simulovaná chyba/);
    assert.equal(f.file.md5, before, 'public file untouched');
    assert.match(f.report.last_error, /Simulovaná chyba/);
    assert.ok(state.log.some((l) => l.level === 'error' && l.feed === feeds.google));
    const d = await php('diag.php');
    assert.deepEqual(d.tmp_files.filter((x) => x.endsWith('.part')), []);
  } finally {
    await php('set.php', { test: 'fail', value: '0' });
  }
  await run(feeds.google);
  await refresh();
  assert.equal(feedState('google').run.status, 'idle');
  assert.equal(feedState('google').report.last_error, '');
});

test('a much smaller file waits for confirmation (drop guard)', async () => {
  const before = feedState('google').file.md5;
  const simple = ['simple', 'sale', 'oos', 'elementor_a', 'elementor_b', 'special', 'yoast', 'meta_only_excluded', 'webp', 'invalid_gtin'].map((k) => ids[k]);
  await php('set.php', { feed: feeds.google, field: 'exclude_ids', value: simple.join(',') });
  try {
    await run(feeds.google);
    await refresh();
    let f = feedState('google');
    assert.equal(f.run.status, 'guarded');
    assert.equal(f.run.pending.items, 5);
    assert.equal(f.run.pending.previous, 15);
    assert.equal(f.file.md5, before, 'not published');
    const published = await php('guard.php', { do: 'publish', feed: feeds.google });
    assert.equal(published.ok, true);
    assert.notEqual(published.file.md5, before);
    assert.equal((await fetchFeed('google')).items.length, 5);
  } finally {
    await php('set.php', { feed: feeds.google, field: 'exclude_ids', value: '' });
  }
  await run(feeds.google);
  assert.equal((await fetchFeed('google')).items.length, 15, 'growing back needs no confirmation');
});

test('a request during a run is queued as a rerun, not run in parallel', async () => {
  const first = await run(feeds.google, { jobs: '0' });
  assert.equal(first.requested[feeds.google], 'started');
  const second = await run(feeds.google, { jobs: '0' });
  assert.equal(second.requested[feeds.google], 'queued');
  await refresh();
  assert.equal(feedState('google').run.rerun, true);
  await php('jobs.php');
  await refresh();
  assert.equal(feedState('google').run.status, 'idle');
  assert.ok(state.pending.some((p) => p.hook === 'nwr_pf_rerun' && p.args[0] === feeds.google));
  const rerun = await php('jobs.php', { force: 'nwr_pf_rerun' });
  assert.ok(rerun.jobs.some((j) => j.hook === 'nwr_pf_batch'));
  await refresh();
  assert.equal(feedState('google').run.trigger, 'rerun');
});

test('product changes queue one delayed regeneration', async () => {
  await php('jobs.php', { force: 'nwr_pf_dirty' });
  await refresh();
  assert.ok(!state.pending.some((p) => p.hook === 'nwr_pf_dirty'));

  const one = await php('touch.php', { product: 'simple', price: '25.90' });
  assert.equal(one.pending_in.length, 1);
  assert.ok(one.pending_in[0] > 540 && one.pending_in[0] <= 600, `delay ${one.pending_in[0]} s`);
  const two = await php('touch.php', { product: 'sale', price: '61.90' });
  assert.equal(two.pending_in.length, 1, 'a second change reuses the pending run');

  await php('jobs.php', { force: 'nwr_pf_dirty' });
  const g = byId((await fetchFeed('google')).items);
  assert.equal(g[ids.simple]['g:price'], '25.90 EUR');
  assert.equal(feedState('google').run.trigger, 'change');

  try {
    await php('touch.php', { product: 'simple', price: '24.90' });
    await php('touch.php', { product: 'sale', price: '59.90' });
    const stock = await php('touch.php', { product: 'rast_1', stock: '4' });
    assert.equal(stock.pending_in.length, 1, 'a stock change (checkout) counts too');
    await php('jobs.php', { force: 'nwr_pf_dirty' });
    const again = byId((await fetchFeed('meta')).items);
    assert.equal(again[ids.rast_1]['g:quantity_to_sell_on_facebook'], '4');
    assert.equal(again[ids.simple]['g:price'], '24.90 EUR');
  } finally {
    // Seed data for the other tests (seed.php does not reset prices or stock).
    await php('touch.php', { product: 'simple', price: '24.90' });
    await php('touch.php', { product: 'sale', price: '59.90' });
    await php('touch.php', { product: 'rast_1', stock: '5' });
    await php('jobs.php', { force: 'nwr_pf_dirty' });
  }
});

test('a plugin update refreshes the feeds within the change delay', async () => {
  const u = await php('upgrade.php');
  assert.equal(u.version, u.plugin, 'version stored');
  assert.equal(u.pending_in.length, 1, 'one regeneration queued');
  assert.ok(u.pending_in[0] > 540 && u.pending_in[0] <= 600, `delay ${u.pending_in[0]} s`);
  await php('jobs.php', { force: 'nwr_pf_dirty' });
  await refresh();
  assert.equal(feedState('meta').run.trigger, 'change');
  assert.ok(!state.pending.some((p) => p.hook === 'nwr_pf_dirty'));
});

test('the live sample builds items without touching the run state', async () => {
  const s = await php('sample.php', { feed: 'google', n: '3' });
  assert.equal(s.count, 3);
  assert.ok(s.body.startsWith('<?xml'));
  assert.equal(parseItems(s.body).length, 3);
  assert.equal(s.unchanged, true);
});

// ------------------------------------------------------------------ admin

async function login() {
  const { cookies } = await php('login-cookie.php');
  return Object.entries(cookies).map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('; ');
}

async function admin(path, cookie, init = {}) {
  const res = await fetch(`${BASE}/wp-admin/${path}`, { redirect: 'manual', ...init, headers: { cookie, ...(init.headers || {}) } });
  const html = await res.text();
  if (res.status === 200) {
    assert.doesNotMatch(html, /(Warning|Notice|Deprecated|Fatal error)<\/b>:.*nwr-product-feeds/, `${path}: PHP message`);
    assert.doesNotMatch(html, /There has been a critical error/, path);
  }
  return { res, html };
}

test('admin screens render', async () => {
  const cookie = await login();
  const base = 'admin.php?page=nwr-product-feeds';

  const listPage = await admin(base, cookie);
  assert.equal(listPage.res.status, 200);
  assert.match(listPage.html, /Produktové feedy/);
  assert.match(listPage.html, /Pregenerovať teraz/);
  assert.match(listPage.html, new RegExp(feedState('google').file.url.replace(/[.?]/g, '\\$&')));
  assert.match(listPage.html, /WP-Cron je vypnutý/);

  for (const view of [`&view=edit&feed=${feeds.google}`, `&view=edit&feed=${feeds.meta}`, '&view=new&channel=meta', `&view=report&feed=${feeds.google}`, `&view=report&feed=${feeds.meta}&sample=1&n=2`, '&view=settings']) {
    const page = await admin(base + view, cookie);
    assert.equal(page.res.status, 200, view);
    assert.match(page.html, /nwr-pf/, view);
  }
  const report = await admin(`${base}&view=report&feed=${feeds.google}`, cookie);
  assert.match(report.html, /Vynechané produkty/);
  assert.match(report.html, /Bez ceny/);
  assert.match(report.html, /Neplatný GTIN/);
  assert.match(report.html, /&lt;g:id&gt;/, 'preview of the published file');
  assert.match(report.html, /Značka z nastavenia feedu/);
  assert.doesNotMatch(report.html, /nwr-pf-hint/, 'the feed has a default brand');
  const csvReport = await admin(`${base}&view=report&feed=${feeds.csv}`, cookie);
  assert.match(csvReport.html, /Chýba značka/);
  assert.match(csvReport.html, /class="nwr-pf-hint"[^>]*>Tip: ak predávate vlastnú značku/);
  assert.match(csvReport.html, /#nwr-pf-brand_default/);

  const edit = await admin(`${base}&view=edit&feed=${feeds.meta}`, cookie);
  assert.match(edit.html, /internal_label/);
  assert.match(edit.html, /nwr-pf-gpc/);

  const product = await admin(`post.php?post=${ids.classic}&action=edit`, cookie);
  assert.equal(product.res.status, 200);
  assert.match(product.html, /nwr_pf_product_data/);
  assert.match(product.html, /name="nwr_pf_panel"/);

  const status = await fetch(`${BASE}/wp-admin/admin-ajax.php?action=nwr_pf_status`, { headers: { cookie } });
  assert.equal(status.status, 403, 'AJAX status needs a nonce');
});

test('admin actions go through nonces and capabilities', async () => {
  const cookie = await login();
  const list = await admin('admin.php?page=nwr-product-feeds', cookie);
  const href = list.html.match(new RegExp(`href="([^"]*action=nwr_pf_run_feed[^"]*feed=${feeds.meta}[^"]*)"`))?.[1];
  assert.ok(href, 'run link present');
  const url = decode(href);
  const started = await fetch(url, { redirect: 'manual', headers: { cookie } });
  assert.equal(started.status, 302);
  assert.match(started.headers.get('location'), /nwr_pf_notice=started/);
  await refresh();
  assert.equal(feedState('meta').run.status, 'running');
  await php('jobs.php');
  await refresh();
  assert.equal(feedState('meta').run.status, 'idle');

  const forged = await fetch(url.replace(/_wpnonce=[^&]+/, '_wpnonce=bad'), { redirect: 'manual', headers: { cookie } });
  assert.notEqual(forged.status, 302, 'bad nonce is refused');
  const anonymous = await fetch(url, { redirect: 'manual', headers: VISITOR });
  assert.doesNotMatch(anonymous.headers.get('location') || '', /nwr_pf_notice=started/, 'visitors cannot start a run');
  await refresh();
  assert.equal(feedState('meta').run.status, 'idle');

  // Save the feed form like a browser would.
  const edit = await admin(`admin.php?page=nwr-product-feeds&view=edit&feed=${feeds.tsv}`, cookie);
  const nonce = edit.html.match(/name="_wpnonce" value="([^"]+)"/)[1];
  const form = new URLSearchParams({ action: 'nwr_pf_save_feed', feed: feeds.tsv, _wpnonce: nonce, 'feed_data[name]': 'Meta TSV bez DPH', 'feed_data[interval]': '120', 'feed_data[enabled]': '1' });
  const saved = await fetch(`${BASE}/wp-admin/admin-post.php`, { method: 'POST', redirect: 'manual', headers: { cookie, 'content-type': 'application/x-www-form-urlencoded' }, body: form });
  assert.equal(saved.status, 302);
  await refresh();
  assert.equal(feedState('tsv').feed.interval, 120);
  const schedule = state.pending.find((p) => p.hook === 'nwr_pf_generate' && p.args[0] === feeds.tsv);
  assert.ok(schedule && schedule.recurring, 'schedule follows the interval');
  await php('set.php', { feed: feeds.tsv, field: 'interval', value: '60' });
});

test('the Google category picker searches the taxonomy', async () => {
  const cookie = await login();
  const edit = await admin(`admin.php?page=nwr-product-feeds&view=edit&feed=${feeds.google}`, cookie);
  const nonce = edit.html.match(/var nwrPf = \{[^;]*"nonce":"([a-f0-9]+)"/)?.[1];
  assert.ok(nonce, 'nonce localized for admin.js');
  const search = async (q) => {
    const res = await fetch(`${BASE}/wp-admin/admin-ajax.php?action=nwr_pf_gpc_search&_ajax_nonce=${nonce}&q=${encodeURIComponent(q)}`, { headers: { cookie } });
    return res.json();
  };
  const exact = await search("537");
  assert.equal(exact.success, true, JSON.stringify(exact).slice(0, 300));
  assert.equal(exact.data.length, 1);
  assert.match(exact.data[0].label, /^537 – /);
  const byName = await search('baby toddler');
  assert.ok(byName.data.length > 0, 'search by words');
  const denied = await fetch(`${BASE}/wp-admin/admin-ajax.php?action=nwr_pf_gpc_search&_ajax_nonce=bad&q=537`, { headers: { cookie } });
  assert.equal(denied.status, 403);
  // With the list downloaded, the editor shows the path next to a stored ID.
  const again = await admin(`admin.php?page=nwr-product-feeds&view=edit&feed=${feeds.google}`, cookie);
  assert.match(again.html, /value="537 – [^"]+"/);
});

test('the product tab saves only when it was part of the form', async () => {
  const r = await php('panel.php');
  assert.ok(r.tabs.includes('nwr_pf_feeds'));
  assert.match(r.panel_html, /ID vo feede/);
  assert.match(r.panel_html, new RegExp(`<code>${ids.classic}</code>`));
  assert.match(r.variation_html, /nwr_pf_var\[0\]\[_nwr_pf_title\]/);
  assert.deepEqual(r.after_save, { title: 'Osuška pre feed tučná', gtin: '4006381333931', gpc: '537', label2: 'leto', gender: 'unisex', feeds: '' });
  assert.equal(r.invalid_gtin_kept, '4006381333931', 'an invalid GTIN does not overwrite');
  assert.ok(JSON.stringify(r.meta_box_errors).includes('1234567890123'));
  assert.equal(r.without_marker_title, 'Osuška pre feed', 'a form without the tab changes nothing');
  assert.deepEqual(r.variation_saved, { mpn: 'VAR-MPN', exclude: 'yes' });
  assert.deepEqual(r.variation_cleared, { mpn: '', exclude: '' });
});

// ----------------------------------------------------------- integrations

test('export and import copy feeds between shops', async () => {
  const r = await php('transfer.php');
  assert.equal(r.export_format, 'nwr-product-feeds');
  assert.equal(r.export_has_tokens, false);
  assert.equal(r.result.feeds, 4);
  assert.ok(r.result.unmatched.includes('product_cat:neexistuje'));
  const google = r.imported.find((f) => f.name === 'Google test');
  assert.ok(google.token_differs);
  assert.deepEqual(google.gpc_map, { [ids.cats.vaky]: '537' });
  assert.equal(google.labels[2].param, '30,60');
  const csv = r.imported.find((f) => f.name === 'Google CSV filtre');
  assert.deepEqual(csv.include_cats, [ids.cats.deky]);
  assert.match(r.bad_import, /nie je export/);
});

test('updates come from GitHub releases', async () => {
  const r = await php('updater.php');
  assert.equal(r.update_uri, 'https://github.com/noweradigital-hub/nwr-product-feeds');
  assert.equal(r.update.version, '9.9.9');
  assert.match(r.update.package, /nwr-product-feeds-9\.9\.9\.zip$/);
  assert.equal(r.info_name, 'Produktové feedy — Google & Meta');
});

test('feeds from version 1.0.0 are migrated and their old URL redirects', async () => {
  const r = await php('migration.php');
  try {
    assert.equal(r.feed.channel, 'meta');
    assert.deepEqual(r.feed.types, ['simple', 'variable']);
    assert.equal(r.feed.brand_default, 'JUST TO BE');
    assert.equal(r.feed.gpc_default, '');
    assert.deepEqual(r.feed.gpc_map, { [ids.cats.vaky]: '537' });
    assert.deepEqual(r.feed.labels.slice(0, 3).map((l) => l.rule), ['category_top', 'stock', 'price_band']);
    assert.match(r.feed.token, /^[a-f0-9]{16}$/);
    assert.deepEqual(r.legacy, { 'meta-katalog': 'fold123' });
    assert.equal(r.transient, false);
    await run('fold123');
    await refresh();
    const url = state.feeds.fold123.file.url;
    for (const path of ['/produkty-feed/meta-katalog.xml', '/?nwr_pf_feed=meta-katalog']) {
      const res = await fetch(BASE + path, { redirect: 'manual', headers: VISITOR });
      assert.equal(res.status, 301, path);
      assert.equal(res.headers.get('location'), url);
    }
    const other = await fetch(`${BASE}/produkty-feed/neexistuje.xml`, { redirect: 'manual', headers: VISITOR });
    assert.doesNotMatch(other.headers.get('location') || '', /nwr-feeds/, 'unknown slugs are left to WordPress');
  } finally {
    await php('cleanup-migration.php');
  }
});

test('WooCommerce Multistore never copies the plugin meta', async () => {
  const r = await php('multistore.php');
  assert.deepEqual(Object.keys(r.master.meta).sort(), ['_global_unique_id', '_nwr_pf_exclude', '_sku']);
  assert.deepEqual(r.master._custom_metadata['site-2'], { farba: 'modra' });
  assert.deepEqual(r.master.variations[0].meta, [{ key: '_price', value: '9' }]);
  assert.deepEqual(r.child, {});
});

// ------------------------------------------------------------ performance

test('a large catalog is generated in batches', { skip: !process.env.NWR_PF_PERF }, async () => {
  const perf = await php('perf.php', { n: '300' });
  try {
    const result = await run(perf.feed);
    const batches = result.jobs.filter((j) => j.hook === 'nwr_pf_batch' && j.args[0] === perf.feed);
    assert.ok(batches.length >= 6, `${batches.length} batches`);
    await refresh();
    const f = state.feeds[perf.feed];
    assert.equal(f.report.items, 300);
    assert.equal(f.run.status, 'idle');
    console.log(`perf: ${f.report.items} items, ${batches.length} batches, ${f.report.duration} s, peak ${(result.memory / 1048576).toFixed(0)} MB`);
  } finally {
    await php('perf.php', { cleanup: '1' });
  }
});
