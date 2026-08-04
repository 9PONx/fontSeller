# Open Font Bundle Design

## Goal

Replace the browser-generated sample archive with a real downloadable ZIP built from redistributable fonts installed in `C:\Windows\Fonts`. The ZIP, catalog, visible font list, counts, sizes, README, and tests must describe the same file set.

## Inclusion Policy

- Consider ordinary `.ttf` and `.otf` files only.
- Include a file only when its OpenType name metadata explicitly identifies SIL Open Font License 1.1, Apache License 2.0, or Ubuntu Font License 1.0.
- Reject a file when its metadata also contains a conflicting restriction such as `Microsoft supplied font` or `Any other use is prohibited`.
- Do not infer redistribution permission from the filename, vendor, installation directory, or embedding flags.
- Preserve every accepted font byte-for-byte.

On the current machine this policy accepts 470 files totaling 88.53 MiB before ZIP container overhead and rejects `CascadiaCode.ttf`, `CascadiaMono.ttf`, and `seguihis.ttf` because their metadata contains Microsoft redistribution restrictions.

## Generated Artifacts

`scripts/build_open_font_bundle.py` scans the source folder and atomically generates:

- `downloads/fontseller-open-fonts.zip`, using stored ZIP entries so the archive remains close to 89 MiB and below GitHub's 100 MiB per-file limit.
- `data/fonts.json`, containing one active catalog row per ZIP font.
- `manifest.csv` and `manifest.json` inside the ZIP, containing filename, display name, byte size, SHA-256, license identifier, copyright, and license URL.
- `README.txt` inside the ZIP explaining the selection policy and that each font retains its embedded license metadata.

The generator fails without replacing existing artifacts if the source is unreadable, no fonts qualify, filenames collide, an accepted file changes while being read, output verification fails, or the final ZIP reaches 100 MiB.

## Catalog and UI

- Display names come from the preferred English OpenType full-name record, with family/style and filename fallbacks.
- `Fonts.computeStats()` remains the single runtime source for the font count, type totals, names, and source-byte total.
- Remove hard-coded references to 3,406 fonts, 455 MB, the 2.4 MB sample, and ten sample fonts wherever they describe the purchased archive.
- Keep the nine bundled browser-preview fonts, but label them clearly as preview examples rather than the downloadable archive contents.
- The success page reports the dynamic count and approximate source size and downloads `downloads/fontseller-open-fonts.zip` after existing token authorization/consumption.
- Remove JSZip because the browser no longer constructs an archive.

## Verification

- A bundle-integrity test verifies that ZIP font members and `data/fonts.json` match exactly, hashes and sizes match, every row has an allowed license, restricted conflict files are absent, manifests cover every font, and the archive is below 100 MiB.
- Payment unit tests remain green.
- Browser E2E asserts the filtered catalog count, listing population, paid success view, and download filename while intercepting the large archive response to keep the test fast.
- GitHub Pages verification checks that the deployed catalog, UI scripts, and downloadable archive match the committed artifacts.

## Distribution Note

This is an educational, public bundle of fonts whose own metadata grants redistribution. It must not include Windows-supplied fonts or other installed fonts without explicit redistribution terms.
