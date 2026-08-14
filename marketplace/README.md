# Adobe Marketplace listing collateral

Source for everything uploaded to the Adobe Commerce Marketplace submission
that is **not** the extension package itself. Edit here, regenerate, upload —
never edit an uploaded artifact in the portal and let this drift.

Excluded from the Marketplace zip by `build-adobe-zip.sh` (`-x "marketplace/*"`):
this is listing collateral, not package content.

## Layout

```
marketplace/
├── src/                  Markdown sources — edit these
│   ├── installation-guide.md
│   ├── user-guide.md
│   └── reference-manual.md
├── pdf/                  Generated PDFs — upload these, committed
├── bin/                  Tooling
│   ├── build-guide.sh    Regenerates the PDFs
│   ├── md2html.py        Markdown → HTML (stdlib only)
│   └── guide.css         Print styling
├── long-description.md   Record of the listing's Long Description copy
└── README.md             This file
```

## Regenerating the PDFs

```bash
./marketplace/bin/build-guide.sh                    # all three
./marketplace/bin/build-guide.sh user-guide         # just one
```

Requires `python3` (stdlib only) and any Chromium-family browser. The script
probes for Chrome, Chromium, Edge and Brave; override with
`CHROME=/path/to/binary ./marketplace/bin/build-guide.sh`.

The generated PDFs are committed deliberately, so a missing browser never
stands between someone and an upload-ready file. Commit regenerated PDFs
along with whatever source change prompted them.

## The three documents

Adobe's documentation slots are User Guide, Installation Guide and Reference
Manual, and the marketing review guidelines say **"do not upload duplicates of
the documents"** while also requiring that documentation **"cover all features
of the product"**. The split exists to satisfy both at once:

| Document | Scope |
|---|---|
| Installation Guide | Getting the extension in place: requirements, Composer and manual install, verifying, upgrading, uninstalling |
| User Guide | Configuring and operating it: admin fields, integration types, test mode, webhooks, order lifecycle, troubleshooting |
| Reference Manual | Technical facts: config paths, routes, REST endpoint, events, webhook signature, CSP hosts, DI scoping, compatibility |

Keep them distinct. If you find yourself repeating a section across two
documents, it belongs in one and should be cross-referenced from the other.

## Conventions that exist for a reason

**The version is injected from `composer.json`, not written in the sources.**
`build-guide.sh` extracts it the same way `build-adobe-zip.sh` does. CLAUDE.md
already tracks the version in three places that must be kept in sync — do not
make this a fourth. No source file in `src/` contains a version string.

**Titles use the "<product> for Magento" format.** Adobe's marketing review
rejected submission `fc2xb678ho` in August 2026 for a heading that led with the
Magento trademark ("Paystack Magento 2 Module"). Every document title reads
"Paystack Payments for Magento 2" with the document type as its subtitle. The
published guidelines also accept "for Adobe Commerce" / "for Magento Open
Source"; the "for Magento 2" wording matches the worked examples in the
reviewer's own rejection notice.

**No Adobe or Magento logos.** Prohibited by the guidelines. Adobe partner
badges are permitted if ever needed.

**The guides are merchant-facing and deliberately not `README.md`.** The repo
README stays developer-facing and carries the Docker development environment
and contribution sections, which do not belong in Marketplace documentation.
The two are separate documents; a change to one does not automatically belong
in the other.

**Long Description bullets must be re-entered with the Marketplace editor's own
bullet button.** Pasting `-` or `•` characters produces text that looks like a
list but is not one, which is what the August 2026 review rejected.
`long-description.md` is the record of the copy, not a paste source.
