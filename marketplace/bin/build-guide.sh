#!/usr/bin/env bash
#
# Build the Adobe Marketplace documentation PDFs from ../src/*.md.
# Run from anywhere: ./marketplace/bin/build-guide.sh
#
# Builds every document by default, or just the ones named:
#   ./marketplace/bin/build-guide.sh user-guide reference-manual
#
# The generated PDFs are committed alongside their sources, so a missing
# browser never stands between someone and an upload-ready file. Regenerate
# whenever you change a file in ../src or guide.css.
#
# Deliberately NOT wired into build-adobe-zip.sh: these are listing collateral,
# not package content, and coupling them would make every package build require
# a browser.
set -e

BIN="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MARKETPLACE="$(dirname "$BIN")"
ROOT="$(dirname "$MARKETPLACE")"

SRC_DIR="$MARKETPLACE/src"
PDF_DIR="$MARKETPLACE/pdf"
CSS="$BIN/guide.css"

# Every document uploaded to the submission. Adobe's slots are User Guide,
# Installation Guide and Reference Manual; the three must stay distinct, since
# the review guidelines reject duplicate documents.
ALL_DOCS=(installation-guide user-guide reference-manual)

mkdir -p "$PDF_DIR"

# Single source of truth for the version — same extraction the package build
# uses, so the guides can never drift into being a fourth place it is recorded.
VERSION=$(grep -E '"version"' "$ROOT/composer.json" | sed 's/.*: *"\(.*\)".*/\1/')
if [ -z "$VERSION" ]; then
  echo "ERROR: could not read version from $ROOT/composer.json" >&2
  exit 1
fi

if ! command -v python3 >/dev/null 2>&1; then
  echo "ERROR: python3 is required to build the guides (stdlib only, no packages)." >&2
  echo "       On macOS: xcode-select --install" >&2
  exit 1
fi

# Chromium-family browsers can print HTML to PDF headlessly. Probe rather than
# hardcode a path, so this works off one machine. Override with CHROME=/path.
find_browser() {
  if [ -n "${CHROME:-}" ]; then
    echo "$CHROME"
    return 0
  fi
  local candidates=(
    "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
    "/Applications/Chromium.app/Contents/MacOS/Chromium"
    "/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge"
    "/Applications/Brave Browser.app/Contents/MacOS/Brave Browser"
  )
  local path
  for path in "${candidates[@]}"; do
    [ -x "$path" ] && { echo "$path"; return 0; }
  done
  for path in google-chrome google-chrome-stable chromium chromium-browser microsoft-edge; do
    command -v "$path" >/dev/null 2>&1 && { command -v "$path"; return 0; }
  done
  return 1
}

BROWSER="$(find_browser)" || {
  echo "ERROR: no Chromium-family browser found; cannot render the PDFs." >&2
  echo "       Install Google Chrome, or point at an existing binary:" >&2
  echo "         CHROME=/path/to/chrome ./marketplace/bin/build-guide.sh" >&2
  echo "       The committed PDFs remain valid until they are regenerated." >&2
  exit 1
}

if [ "$#" -gt 0 ]; then
  DOCS=("$@")
else
  DOCS=("${ALL_DOCS[@]}")
fi

# Title case a slug for the output filename: user-guide -> User-Guide
title_case() {
  echo "$1" | awk -F- '{for(i=1;i<=NF;i++) printf "%s%s", toupper(substr($i,1,1)) substr($i,2), (i<NF?"-":"")}'
}

echo "Version: $VERSION"
echo "Browser: $BROWSER"
echo ""

for doc in "${DOCS[@]}"; do
  src="$SRC_DIR/$doc.md"
  [ -f "$src" ] || { echo "ERROR: no such document: $src" >&2; exit 1; }

  pdf="$PDF_DIR/Paystack-Payments-for-Magento-2-$(title_case "$doc").pdf"
  tmp_html="$(mktemp -t paystack-doc).html"

  python3 "$BIN/md2html.py" "$src" "$tmp_html" "$CSS" "$VERSION"

  "$BROWSER" \
    --headless \
    --disable-gpu \
    --no-sandbox \
    --no-pdf-header-footer \
    --print-to-pdf="$pdf" \
    "file://$tmp_html" >/dev/null 2>&1

  rm -f "$tmp_html"

  [ -s "$pdf" ] || { echo "ERROR: $doc produced no PDF." >&2; exit 1; }
  echo "  $(basename "$pdf")  ($(du -h "$pdf" | cut -f1))"
done

echo ""
echo "Written to $PDF_DIR"
echo "Upload these to the documentation slots of the Adobe Marketplace submission."
