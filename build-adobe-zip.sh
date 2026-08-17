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
# (e.g. files that are now excluded) from a previous build. Prior-version zips go
# too, so the only artifact at the repo root is the one just built and there is
# nothing stale to upload to Adobe by mistake.
rm -f "${NAME}"-*.zip

# Exclude dev assets, internal docs, archives, and any built zips.
# docs/ holds internal QA artifacts (review logs, recordings) and must never ship.
# graphify-out/ is gitignored internal knowledge-graph tooling cache — must never ship.
# marketplace/ is Adobe listing collateral (user guide source + PDF, listing copy);
# it is uploaded to the submission separately and is not package content.
# phpunit.xml and Test/Unit/* are unit-test infrastructure (the CI-only manifest,
# its lock, its vendor tree and its phpunit cache all live under Test/Unit/).
# Test/Mftf/ MUST still ship — this excludes Test/Unit/ only.
# composer.lock is deliberately NOT excluded: it shipped in 3.0.10, which passed
# Adobe review, and dropping it would change the released artifact's composition
# without anyone having validated that against Adobe's package checks. Removing
# the stale root lock outright is filed as its own change.
zip -r "$ZIP" . \
  -x "*.git*" \
  -x "*.DS_Store" \
  -x ".claude/*" \
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
  -x "phpunit.xml" \
  -x "Test/Unit/*" \
  -x "${NAME}-*.zip"

echo "Created: $ZIP"
echo "Size:   $(du -h "$ZIP" | cut -f1)"
echo ""
echo "Upload $ZIP to Adobe Commerce Marketplace."
unzip -l "$ZIP" | head -30
