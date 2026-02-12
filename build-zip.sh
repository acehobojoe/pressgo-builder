#!/bin/bash
#
# Build a clean ZIP for WordPress.org plugin submission.
# Usage: ./build-zip.sh
#

set -e

PLUGIN_SLUG="pressgo"
PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
BUILD_DIR="/tmp/${PLUGIN_SLUG}-build"
ZIP_FILE="${PLUGIN_DIR}/${PLUGIN_SLUG}.zip"

echo "Building ${PLUGIN_SLUG}.zip..."

# Clean previous build.
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/${PLUGIN_SLUG}"

# Copy plugin files, excluding dev/build artifacts.
rsync -av --exclude-from=- "$PLUGIN_DIR/" "$BUILD_DIR/${PLUGIN_SLUG}/" <<'EOF'
node_modules
.git
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
phpunit.xml
phpcs.xml
.phpcs.xml
.editorconfig
EOF

# Remove any leftover .DS_Store files.
find "$BUILD_DIR" -name ".DS_Store" -delete

# Build the ZIP.
rm -f "$ZIP_FILE"
cd "$BUILD_DIR"
zip -r "$ZIP_FILE" "$PLUGIN_SLUG"

# Clean up.
rm -rf "$BUILD_DIR"

echo ""
echo "Done! Created: ${ZIP_FILE}"
echo ""
echo "Next steps:"
echo "  1. Validate readme: https://wordpress.org/plugins/developers/readme-validator/"
echo "  2. Submit:          https://wordpress.org/plugins/developers/add/"
