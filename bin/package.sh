#!/bin/sh
set -eu

REPO_ROOT=$(cd "$(dirname "$0")/.." && pwd)
VERSION=$(cat "$REPO_ROOT/VERSION")
DIST_DIR="$REPO_ROOT/dist"
OUTPUT="$DIST_DIR/seo-watch-v${VERSION}-release.zip"
CHECKSUMS="$DIST_DIR/seo-watch-v${VERSION}-checksums.sha256"

mkdir -p "$DIST_DIR"
rm -f "$OUTPUT" "$CHECKSUMS"

tempdir=$(mktemp -d)
trap 'rm -rf "$tempdir"' EXIT

mkdir -p "$tempdir/seo-watch"

rsync -a --exclude='.git' --exclude='.github' --exclude='.agents' --exclude='.codex' --exclude='dist' \
    --exclude='tests' --exclude='node_modules' --exclude='vendor' \
    --exclude='config/local.php' --exclude='logs' --exclude='cache' --exclude='*.log' --exclude='*.tmp' \
    --exclude='seo-watch-v*-release.zip' --exclude='seo-watch-v*-checksums.sha256' \
    --exclude='PR_BODY.md' --exclude='RELEASE_NOTES.md' \
    --exclude='bin/.htaccess' \
    "$REPO_ROOT"/ "$tempdir/seo-watch"

cd "$tempdir"
zip -r "$OUTPUT" seo-watch >/dev/null
(cd "$DIST_DIR" && sha256sum "$(basename "$OUTPUT")" >"$(basename "$CHECKSUMS")")
