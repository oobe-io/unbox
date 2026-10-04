#!/bin/sh
# 配布用 zip を dist/ に作る（dev/ や .git は入れない）
# 翻訳は translate.wordpress.org から配られるので、languages/ も入れない
set -e
ROOT=$(cd "$(dirname "$0")/.." && pwd)
VERSION=$(sed -n "s/^define( 'UNBOX_VERSION', '\(.*\)' );/\1/p" "$ROOT/unbox.php")
SLUG=unbox-by-oobe
OUT="$ROOT/dist/$SLUG-$VERSION.zip"
TMP=$(mktemp -d)
mkdir -p "$ROOT/dist" "$TMP/$SLUG"
rsync -a --exclude .git --exclude .gitignore --exclude dev --exclude dist --exclude .wordpress-org --exclude cli --exclude languages --exclude README.md --exclude SECURITY.md --exclude .DS_Store "$ROOT/" "$TMP/$SLUG/"
rm -f "$OUT"
(cd "$TMP" && zip -qr "$OUT" "$SLUG")
rm -rf "$TMP"
echo "$OUT"
