#!/usr/bin/env python3
"""Bundles the catalog/channel classes into one PHP string for a read-only
dry run through Novamira execute-php (bracketed namespaces, no <?php tag).
Usage: tools/dryrun-bundle.py tools/dryrun-driver.php [--rename] > bundle.php
--rename moves everything to Nowera\ProductFeedsDryRun, for a site where the
plugin is already active (its classes would otherwise be used instead)."""
import re, sys, pathlib

RENAME = '--rename' in sys.argv[2:]
NS_FROM, NS_TO = 'Nowera\\ProductFeeds', 'Nowera\\ProductFeedsDryRun'

def ns(code: str) -> str:
    return re.sub(re.escape(NS_FROM) + r'(?![A-Za-z])', lambda m: NS_TO, code) if RENAME else code

ROOT = pathlib.Path(__file__).resolve().parent.parent / 'plugin' / 'nwr-product-feeds' / 'includes'
FILES = [
    'Output/Node.php', 'Channels/Channel.php', 'Channels/Google.php', 'Channels/Meta.php', 'Channels/Registry.php',
    'Settings.php', 'State.php', 'Storage.php', 'Item_Id.php', 'Feeds.php',
    'Catalog/Text.php', 'Catalog/Source.php', 'Catalog/Attributes.php', 'Catalog/Categories.php', 'Catalog/Gtin.php',
    'Catalog/Labels.php', 'Catalog/Product_Types.php', 'Catalog/Batch.php', 'Catalog/Item_Builder.php',
]

def strip_comments(code: str) -> str:
    # Drop /** … */ doc blocks and full-line // comments to keep the payload small.
    code = re.sub(r'/\*.*?\*/', '', code, flags=re.S)
    code = re.sub(r'^\s*//.*$', '', code, flags=re.M)
    code = re.sub(r'\n\s*\n+', '\n', code)
    return code

def method(src: str, name: str) -> str:
    # Source text of one method (signature through its closing brace).
    start = re.search(r'\n\t(?:public|private|protected) (?:static )?function ' + name + r'\(', src).start()
    depth = 0
    for i in range(src.index('{', start), len(src)):
        depth += {'{': 1, '}': -1}.get(src[i], 0)
        if depth == 0:
            return src[start:i + 1]
    raise ValueError(name)

def slim_feeds(src: str) -> str:
    # Only what item building needs; sanitize()/save() stay out of the payload.
    head = src[:src.index('final class Feeds {')]
    consts = '\n'.join(re.findall(r'^\tconst [^;]+;', src, flags=re.M))
    body = '\n'.join([consts, method(src, 'defaults'), method(src, 'normalize'),
                      '\tpublic static function get( string $id ): ?array { return null; }'])
    return head + 'final class Feeds {\n' + body + '\n}\n'

DROP = {
    'Catalog/Product_Types.php': ['choices'],
    'Catalog/Categories.php': ['tree'],
    'Catalog/Attributes.php': ['choices'],
    'Storage.php': ['ensure', 'protect', 'htaccess_public', 'htaccess_private', 'publish', 'delete_feed_files', 'delete_tmp', 'cleanup_tmp', 'unlink_public', 'info'],
}

def drop_methods(src: str, names) -> str:
    for name in names:
        src = src.replace(method(src, name), '')
    return src

VERSION = re.search(r"^const VERSION\s*=\s*'([^']+)';", (ROOT.parent / 'nwr-product-feeds.php').read_text(), flags=re.M).group(1)
parts = [ns("namespace Nowera\\ProductFeeds { const VERSION = '%s'; const PLUGIN_FILE = __FILE__; const SLUG = 'nwr-product-feeds'; }" % VERSION)]
for rel in FILES:
    src = ns((ROOT / rel).read_text())
    if rel == 'Feeds.php':
        src = slim_feeds(src)
    src = drop_methods(src, DROP.get(rel, []))
    src = src.replace('<?php', '', 1)
    m = re.search(r'^namespace\s+([^;]+);', src, flags=re.M)
    space = m.group(1)
    body = src[m.end():]
    body = body.replace("defined( 'ABSPATH' ) || exit;", '')
    uses = '\n'.join(re.findall(r'^use [^;]+;', body, flags=re.M))
    body = re.sub(r'^use [^;]+;\n?', '', body, flags=re.M)
    body = '\n'.join(line.strip() for line in strip_comments(body).splitlines() if line.strip())
    parts.append('namespace %s {\n%s\nif ( ! class_exists( %r, false ) ) {\n%s\n}\n}' % (space, uses, space + '\\' + pathlib.Path(rel).stem, body))
driver = ns(pathlib.Path(sys.argv[1]).read_text().replace('<?php', '', 1))
parts.append('namespace {\n' + driver.strip() + '\n}')
sys.stdout.write('\n'.join(parts))
