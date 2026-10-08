// Generates languages/nwr-product-feeds.pot from the plugin's gettext calls (no WP-CLI needed).
import { readFileSync, writeFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const ROOT = new URL('../plugin/nwr-product-feeds/', import.meta.url).pathname;
const DOMAIN = 'nwr-product-feeds';
const version = readFileSync(join(ROOT, 'nwr-product-feeds.php'), 'utf8').match(/^const VERSION\s*=\s*'([^']+)'/m)[1];

const files = [];
(function walk(dir) {
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) walk(path);
    else if (name.endsWith('.php')) files.push(path);
  }
})(ROOT);

const STR = String.raw`'((?:[^'\\]|\\.)*)'`;
const SEP = String.raw`\s*,\s*`;
const unquote = (s) => s.replace(/\\'/g, "'").replace(/\\\\/g, '\\');
const po = (s) => '"' + s.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n') + '"';
const patterns = [
  { re: new RegExp(String.raw`\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*${STR}${SEP}'${DOMAIN}'\s*\)`, 'g'), map: (m) => ({ id: m[1] }) },
  { re: new RegExp(String.raw`\b(?:_x|esc_html_x|esc_attr_x)\(\s*${STR}${SEP}${STR}${SEP}'${DOMAIN}'\s*\)`, 'g'), map: (m) => ({ id: m[1], ctx: m[2] }) },
  { re: new RegExp(String.raw`\b_n\(\s*${STR}${SEP}${STR}${SEP}[^,]+${SEP}'${DOMAIN}'\s*\)`, 'g'), map: (m) => ({ id: m[1], plural: m[2] }) },
];

const entries = new Map();
for (const file of files.sort()) {
  const src = readFileSync(file, 'utf8');
  for (const { re, map } of patterns) {
    for (const m of src.matchAll(re)) {
      const found = map(m);
      const line = src.slice(0, m.index).split('\n').length;
      const before = src.slice(Math.max(0, m.index - 300), m.index);
      const note = [...before.matchAll(/\/\*\s*translators:\s*([^*]+?)\s*\*\//g)].pop();
      const key = JSON.stringify([found.ctx ?? '', unquote(found.id)]);
      const entry = entries.get(key) ?? {
        id: unquote(found.id),
        ctx: found.ctx && unquote(found.ctx),
        plural: found.plural && unquote(found.plural),
        refs: [],
        notes: new Set(),
      };
      entry.refs.push(`${relative(ROOT, file)}:${line}`);
      if (note && before.length - note.index - note[0].length < 120) entry.notes.add(note[1]);
      entries.set(key, entry);
    }
  }
}

let out = [
  `# Copyright (C) ${new Date().getFullYear()} Nowera`,
  '# This file is distributed under the GPL-2.0-or-later.',
  'msgid ""',
  'msgstr ""',
  `"Project-Id-Version: Produktové feedy — Google & Meta ${version}\\n"`,
  '"MIME-Version: 1.0\\n"',
  '"Content-Type: text/plain; charset=UTF-8\\n"',
  '"Content-Transfer-Encoding: 8bit\\n"',
  '"Language: sk_SK\\n"',
  `"X-Domain: ${DOMAIN}\\n"`,
  '',
].join('\n');

for (const e of entries.values()) {
  out += '\n';
  for (const n of e.notes) out += `#. translators: ${n}\n`;
  out += `#: ${e.refs.join(' ')}\n`;
  if (e.id.includes('%')) out += '#, php-format\n';
  if (e.ctx) out += `msgctxt ${po(e.ctx)}\n`;
  out += `msgid ${po(e.id)}\n`;
  out += e.plural ? `msgid_plural ${po(e.plural)}\nmsgstr[0] ""\nmsgstr[1] ""\n` : 'msgstr ""\n';
}

writeFileSync(join(ROOT, 'languages', `${DOMAIN}.pot`), out);
console.log(`${entries.size} strings → languages/${DOMAIN}.pot`);
