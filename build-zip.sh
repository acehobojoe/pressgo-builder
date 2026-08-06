#!/bin/bash
#
# Build a clean ZIP for WordPress.org plugin submission.
# Usage: ./build-zip.sh
#

set -e

PLUGIN_SLUG="pressgo-builder"
PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
RUNTIME_DIR="${PRESSGO_RUNTIME_ASSET_DIR:-$PLUGIN_DIR}"
BUILD_DIR="$(mktemp -d "${TMPDIR:-/tmp}/${PLUGIN_SLUG}-build.XXXXXX")"
ZIP_FILE="${PLUGIN_DIR}/${PLUGIN_SLUG}.zip"
trap 'rm -rf "$BUILD_DIR"' EXIT

# These files are tracked in git and REQUIRED at runtime — guard against an
# empty or corrupted asset that would silently build a broken zip. Fail loudly.
for req in brain.json config-schema.json includes/prompts/config-schema.json includes/prompts/system-prompt.txt; do
    if [ ! -s "$RUNTIME_DIR/$req" ]; then
        echo "FATAL: required runtime file missing or empty: $req" >&2
        echo "Set PRESSGO_RUNTIME_ASSET_DIR to the matching private runtime bundle." >&2
        exit 1
    fi
done
( cd "$RUNTIME_DIR" && shasum -a 256 -c "$PLUGIN_DIR/runtime-files.sha256" )

echo "Building ${PLUGIN_SLUG}.zip..."

# Clean previous build.
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/${PLUGIN_SLUG}"

# Copy plugin files, excluding dev/build artifacts.
rsync -av --exclude-from=- "$PLUGIN_DIR/" "$BUILD_DIR/${PLUGIN_SLUG}/" <<'EOF'
node_modules
.git
.github
.gitignore
.DS_Store
test
tests
CLAUDE.md
package.json
package-lock.json
build-zip.sh
*.zip
.claude
.env
.env.*
.svn-credentials
*.credentials
*.mjs
release-email-*.md
docs
phpunit.xml
phpcs.xml
.phpcs.xml
.editorconfig
.distignore
mcp-server
includes/class-pressgo-updater.php
wordpress-org-assets
brain-widget-schemas.json
README.md
runtime-files.sha256
EOF

# Copy the checksum-verified private runtime bundle over the checkout copy.
while read -r _hash req; do
    mkdir -p "$BUILD_DIR/${PLUGIN_SLUG}/$(dirname "$req")"
    cp "$RUNTIME_DIR/$req" "$BUILD_DIR/${PLUGIN_SLUG}/$req"
done < "$PLUGIN_DIR/runtime-files.sha256"

# Remove any leftover .DS_Store files.
find "$BUILD_DIR" -name ".DS_Store" -delete

# Normalize timestamps and file order so identical inputs produce identical ZIPs.
find "$BUILD_DIR/${PLUGIN_SLUG}" -exec touch -t 202001010000 {} +

# Build the ZIP.
rm -f "$ZIP_FILE"
cd "$BUILD_DIR"
find "$PLUGIN_SLUG" -print | LC_ALL=C sort | zip -X -q "$ZIP_FILE" -@

echo ""
echo "Done! Created: ${ZIP_FILE}"
echo ""
echo "Next steps:"
echo "  1. Validate readme: https://wordpress.org/plugins/developers/readme-validator/"
echo "  2. Submit:          https://wordpress.org/plugins/developers/add/"
