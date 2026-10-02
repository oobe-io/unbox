#!/bin/sh
# 配布用 zip を dist/ に作る（dev/ や .git は入れない）
set -e
ROOT=$(cd "$(dirname "$0")/.." && pwd)
VERSION=$(sed -n "s/^define( 'UNBOX_VERSION', '\(.*\)' );/\1/p" "$ROOT/unbox.php")
OUT="$ROOT/dist/unbox-$VERSION.zip"
TMP=$(mktemp -d)
mkdir -p "$ROOT/dist" "$TMP/unbox"
rsync -a --exclude .git --exclude .gitignore --exclude dev --exclude dist --exclude .wordpress-org --exclude cli --exclude .gitignore --exclude README.md --exclude SECURITY.md --exclude .DS_Store "$ROOT/" "$TMP/unbox/"
rm -f "$OUT"
(cd "$TMP" && zip -qr "$OUT" unbox)
rm -rf "$TMP"
echo "$OUT"
