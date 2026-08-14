#!/usr/bin/env bash
#
# Build a zip of the plugin for Adobe Commerce Marketplace upload.
# Run from the repository root: ./build-adobe-zip.sh
#
set -e

NAME="pstk-paystack-magento2-module"
VERSION=$(grep -E '"version"' composer.json | sed 's/.*: *"\(.*\)".*/\1/')
ZIP="${NAME}-${VERSION}.zip"

# Always build fresh — `zip -r` updates in place and would keep stale entries
# (e.g. files that are now excluded) from a previous build.
rm -f "$ZIP"

# Exclude dev assets, internal docs, archives, and any built zips.
# docs/ holds internal QA artifacts (review logs, recordings) and must never ship.
# graphify-out/ is gitignored internal knowledge-graph tooling cache — must never ship.
# marketplace/ is Adobe listing collateral (user guide source + PDF, listing copy);
# it is uploaded to the submission separately and is not package content.
zip -r "$ZIP" . \
  -x "*.git*" \
  -x "*.DS_Store" \
  -x "dev/*" \
  -x "marketplace/*" \
  -x "dev-ee/*" \
  -x "dev-repro/*" \
  -x "vendor/*" \
  -x ".env" \
  -x "auth.json" \
  -x "CLAUDE.md" \
  -x "docs/*" \
  -x "graphify-out/*" \
  -x "node_modules/*" \
  -x "build-adobe-zip.sh" \
  -x "${NAME}-*.zip"

echo "Created: $ZIP"
echo "Size:   $(du -h "$ZIP" | cut -f1)"
echo ""
echo "Upload $ZIP to Adobe Commerce Marketplace."
unzip -l "$ZIP" | head -30
