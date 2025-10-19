#!/usr/bin/env bash
set -euo pipefail

SLUG="meteotemplate-receiver"
SRC_DIR="src"
DIST_DIR="dist"

# Extract version from plugin header
VERSION="$(grep -Po '^\s*\*\s*Version:\s*\K[0-9.]+' "$SRC_DIR/meteotemplate-receiver.php" | head -n1)"
if [[ -z "${VERSION:-}" ]]; then
  echo "Could not determine version from $SRC_DIR/meteotemplate-receiver.php"
  exit 1
fi

echo "Building $SLUG v$VERSION"

# Staging directory
STAGE_DIR="$(mktemp -d)"
trap 'rm -rf "$STAGE_DIR"' EXIT

mkdir -p "$DIST_DIR"

# Create plugin folder structure inside staging
mkdir -p "$STAGE_DIR/$SLUG"
cp "$SRC_DIR/meteotemplate-receiver.php" "$STAGE_DIR/$SLUG/"
cp "$SRC_DIR/mt-block.js" "$STAGE_DIR/$SLUG/"
cp "$SRC_DIR/readme.txt" "$STAGE_DIR/$SLUG/"

# Create zip
ZIP_PATH="$DIST_DIR/${SLUG}-${VERSION}.zip"
rm -f "$ZIP_PATH"
(
  cd "$STAGE_DIR"
  zip -r "$ZIP_PATH" "$SLUG" > /dev/null
)
mv "$STAGE_DIR/$ZIP_PATH" "$DIST_DIR/" 2>/dev/null || true

echo "Built: $DIST_DIR/${SLUG}-${VERSION}.zip"
