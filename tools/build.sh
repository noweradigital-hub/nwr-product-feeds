#!/usr/bin/env bash
# Builds dist/nwr-product-feeds-<version>.zip — installable through
# Plugins → Add New → Upload, and the asset a GitHub release needs.
#   tools/build.sh            lint + .pot + zip
#   tools/build.sh --no-lint  skip the (slow) PHP lint
set -euo pipefail
cd "$(dirname "$0")/.."

PLUGIN=plugin/nwr-product-feeds
VERSION=$(sed -n "s/^const VERSION *= *'\(.*\)';/\1/p" "$PLUGIN/nwr-product-feeds.php")
HEADER=$(sed -n 's/^ \* Version: *//p' "$PLUGIN/nwr-product-feeds.php")
STABLE=$(sed -n 's/^Stable tag: *//p' "$PLUGIN/readme.txt")
if [[ "$VERSION" != "$HEADER" || "$VERSION" != "$STABLE" ]]; then
	echo "Version mismatch: const $VERSION, header $HEADER, readme $STABLE" >&2
	exit 1
fi

if [[ "${1:-}" != "--no-lint" ]]; then
	echo "Lint (PHP 8.1)…"
	while IFS= read -r file; do
		out=$(PHP=8.1 npx --yes @php-wasm/cli -l "$file" 2>&1 | tail -1)
		[[ "$out" == No\ syntax\ errors* ]] || { echo "$file: $out" >&2; exit 1; }
	done < <(find "$PLUGIN" -name '*.php' | sort)
fi

node tools/make-pot.mjs

mkdir -p dist
ZIP="dist/nwr-product-feeds-$VERSION.zip"
rm -f "$ZIP"
(cd plugin && zip -rqX "../$ZIP" nwr-product-feeds -x '*.DS_Store')
unzip -l "$ZIP" | tail -1
shasum -a 256 "$ZIP"
