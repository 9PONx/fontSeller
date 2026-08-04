# Open Font Bundle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the browser-built demo ZIP with one real, public, approximately 90 MiB ZIP containing only fonts whose embedded metadata explicitly permits redistribution, and make every catalog count and download label match that bundle.

**Architecture:** A deterministic Python generator scans `C:\Windows\Fonts`, classifies OpenType license metadata, copies accepted font bytes into a stored ZIP, and generates both machine-readable manifests and `data/fonts.json`. The static JavaScript application continues to authorize and consume local download tokens, then downloads the committed archive directly from GitHub Pages.

**Tech Stack:** Python 3, fontTools, `zipfile`, SHA-256, vanilla JavaScript, Node-based unit tests, Playwright E2E, GitHub Pages.

## Global Constraints

- Consider direct `.ttf` and `.otf` files only.
- Include only SIL Open Font License 1.1, Apache License 2.0, or Ubuntu Font License 1.0 identified by embedded OpenType metadata.
- Reject any otherwise eligible file whose metadata contains `Microsoft supplied font` or `Any other use is prohibited`.
- Preserve accepted font files byte-for-byte and use `ZIP_STORED` so the archive remains near 90 MiB.
- Abort before replacing existing outputs when input validation, collision checks, source stability, ZIP verification, or the strict `< 100 MiB` limit fails.
- Keep the nine web preview fonts, but label them as examples rather than the complete archive.
- Keep existing payment authorization, token expiry, and download-count enforcement unchanged.
- Do not add Windows-supplied or unlicensed fonts merely because this is an educational submission.

---

## File and Responsibility Map

| Path | Responsibility |
|---|---|
| `scripts/build_open_font_bundle.py` | Scan, classify, hash, generate, validate, and atomically publish the catalog and ZIP. |
| `tests/Unit/Fonts/font_bundle_test.py` | Prove the committed archive, catalog, manifests, hashes, licenses, and size limit agree. |
| `downloads/fontseller-open-fonts.zip` | Public static archive downloaded after token authorization. |
| `data/fonts.json` | Browser catalog generated from the same accepted font records. |
| `js/config.js` | Canonical public archive URL and filename. |
| `js/downloads.js` | Token authorization plus blob/text and static-file download helpers. |
| `js/app.js` | Dynamic product counts, source size, preview wording, and success-page action. |
| `index.html` | Static dependencies; removes the unused JSZip CDN. |
| `tests/e2e.test.js` | Browser assertions for catalog population, paid state, and archive filename. |
| `README.md` | Exact generated artifact, license policy, rebuild command, and GitHub Pages limitations. |

---

### Task 1: Bundle Integrity Contract

**Files:**
- Create: `tests/Unit/Fonts/font_bundle_test.py`

**Interfaces:**
- Consumes: `downloads/fontseller-open-fonts.zip` and `data/fonts.json`.
- Produces: a `unittest` release gate for archive/catalog consistency.

- [ ] **Step 1: Write the failing archive integrity test**

Create a test that opens both artifacts, requires at least one catalog row, rejects `CascadiaCode.ttf`, `CascadiaMono.ttf`, and `seguihis.ttf`, and asserts the ZIP is below `100 * 1024 * 1024` bytes. Compare the exact sorted `fonts/<file_name>` member set with the catalog and require every catalog `license_id` to be one of `OFL-1.1`, `Apache-2.0`, or `Ubuntu-Font-1.0`.

For every `manifest.json` record, read its ZIP member and assert:

```python
self.assertEqual(record["file_size"], len(payload))
self.assertEqual(record["sha256"], hashlib.sha256(payload).hexdigest())
self.assertEqual(record["license_id"], catalog_by_name[name]["license_id"])
```

Parse `manifest.csv` with `csv.DictReader` and require its filename set to equal the JSON manifest set.

- [ ] **Step 2: Run the test and confirm the missing archive/schema fails**

Run: `python -m unittest tests.Unit.Fonts.font_bundle_test -v`

Expected: FAIL because the real archive does not exist and the old catalog lacks generated license fields.

- [ ] **Step 3: Commit only after the generator makes this contract pass**

The test remains red until Task 2 generates validated artifacts; do not weaken its assertions to accommodate the old sample bundle.

---

### Task 2: Deterministic Open Font Bundle Generator

**Files:**
- Create: `scripts/build_open_font_bundle.py`
- Generate: `downloads/fontseller-open-fonts.zip`
- Replace: `data/fonts.json`
- Test: `tests/Unit/Fonts/font_bundle_test.py`

**Interfaces:**
- Produces: `classify_license(metadata_text: str) -> tuple[str, str] | None`, `scan_fonts(source: Path) -> list[FontRecord]`, and `build_bundle(source: Path, zip_path: Path, catalog_path: Path) -> None`.
- `FontRecord` contains `file_name`, `display_name`, `extension`, `file_size`, `sha256`, `license_id`, `license_url`, `copyright`, `license_text`, `modified_at`, and source `path`.

- [ ] **Step 1: Add focused failing classifier tests**

Extend the Python test module to import `classify_license`. Assert that explicit OFL, Apache 2.0, and Ubuntu Font License text map to the three exact identifiers, while empty text, generic copyright text, and text containing either conflict marker return `None`.

- [ ] **Step 2: Run the classifier tests and confirm import failure**

Run: `python -m unittest tests.Unit.Fonts.font_bundle_test.FontBundleGeneratorTest -v`

Expected: FAIL because `scripts.build_open_font_bundle` does not exist.

- [ ] **Step 3: Implement metadata classification and stable scanning**

Use `fontTools.ttLib.TTFont(..., lazy=True)` and decode all relevant name-table records. Prefer English full name ID 4, then typographic family/style IDs 16/17, then family/style IDs 1/2, then the filename stem. Sort direct source files by `(name.casefold(), name)`, reject links and non-files, allow only `.ttf`/`.otf`, detect case-insensitive filename collisions, and read each accepted file once while checking size and modification time before and after the read.

Apply conflict markers before allow markers:

```python
CONFLICT_MARKERS = ("microsoft supplied font", "any other use is prohibited")
LICENSE_MARKERS = {
    "OFL-1.1": ("open font license", "openfontlicense.org", "scripts.sil.org/ofl"),
    "Apache-2.0": ("apache license", "apache.org/licenses/license-2.0"),
    "Ubuntu-Font-1.0": ("ubuntu font licence", "ubuntu font license"),
}
```

- [ ] **Step 4: Implement atomic catalog and stored ZIP generation**

Build temporary siblings in the destination directories, write font entries as `fonts/<filename>` with `ZIP_STORED`, then add UTF-8 `manifest.json`, `manifest.csv`, and `README.txt`. Generate catalog IDs from 1 in stable sort order and fields used by the browser plus `sha256`, `license_id`, `license_url`, and `copyright`.

Before `os.replace`, reopen the temporary ZIP, validate every font member's size/hash, validate both manifest sets, validate the temporary JSON catalog, and reject `zip_path.stat().st_size >= 100 * 1024 * 1024`. Replace both final outputs only after all checks pass; remove temporary files on every exception.

- [ ] **Step 5: Generate the committed artifacts**

Run: `python scripts/build_open_font_bundle.py --source C:\Windows\Fonts`

Expected: `470` accepted fonts, approximately `88.53 MiB` source bytes, three restricted conflicts reported as rejected, and a final archive below `100 MiB`.

- [ ] **Step 6: Run the integrity contract**

Run: `python -m unittest tests.Unit.Fonts.font_bundle_test -v`

Expected: PASS with exact agreement among font entries, catalog rows, both manifests, hashes, sizes, and license IDs.

---

### Task 3: Static Download and Dynamic Product Copy

**Files:**
- Modify: `js/config.js`
- Modify: `js/downloads.js`
- Modify: `js/app.js`
- Modify: `index.html`
- Modify: `README.md`
- Test: `tests/Unit/Payment/payments.test.js`

**Interfaces:**
- Consumes: `CONFIG.fullZipUrl`, `CONFIG.fullZipName`, and `Fonts.stats` generated from the new catalog.
- Produces: `Downloads.triggerFileDownload(url: string, filename: string): void` while retaining `Downloads.triggerDownload(blob, filename)` for the text list.

- [ ] **Step 1: Add static assertions that fail on old demo wording**

Add test assertions that configuration exposes `downloads/fontseller-open-fonts.zip`, `js/downloads.js` has no `JSZip` or `buildSampleZip`, and `js/app.js` contains no purchased-archive references to `3,406`, `455MB`, `2.4MB`, or `10 ฟอนต์`.

- [ ] **Step 2: Run tests and confirm failure against current files**

Run: `node tests/Unit/Payment/payments.test.js`

Expected: FAIL because the sample ZIP builder and hard-coded product copy are still present.

- [ ] **Step 3: Replace browser ZIP construction with a static download**

Set:

```javascript
fullZipUrl: 'downloads/fontseller-open-fonts.zip',
fullZipName: 'fontseller-open-fonts.zip',
```

Remove `buildSampleZip()`. Implement `triggerFileDownload` with an `<a>` whose `href` is the configured relative URL and whose `download` is the configured filename. Keep the existing blob helper for `downloadFontListTxt()`.

- [ ] **Step 4: Make all product counts and sizes derive from `Fonts.stats`**

Fix the hero size to display `s.totalSizeMB.toFixed(0)` rather than dividing MiB twice. Label the nine browser-loaded fonts as preview examples. On the success page, show `formatCount(Fonts.stats.packCount)`, `Fonts.stats.totalSizeMB.toFixed(2)`, and `CONFIG.fullZipName`; after successful token authorization and consumption, call `Downloads.triggerFileDownload(CONFIG.fullZipUrl, CONFIG.fullZipName)`.

- [ ] **Step 5: Remove JSZip and document the real public bundle**

Delete the JSZip CDN tags from `index.html` and bump local asset query versions. Rewrite README counts and architecture to state that the current generated bundle contains 470 open-license fonts, approximately 88.53 MiB of source font data, is rebuilt by `python scripts/build_open_font_bundle.py --source C:\Windows\Fonts`, and intentionally excludes installed fonts without explicit redistribution metadata.

- [ ] **Step 6: Run frontend syntax and unit checks**

Run:

```powershell
node --check js/config.js
node --check js/downloads.js
node --check js/app.js
node tests/Unit/Payment/payments.test.js
```

Expected: all syntax checks and all payment/static assertions PASS.

---

### Task 4: Browser Journey Without Downloading 90 MiB in Tests

**Files:**
- Modify: `tests/e2e.test.js`

**Interfaces:**
- Consumes: the generated catalog and `CONFIG.fullZipUrl`.
- Produces: a fast E2E assertion for the real archive filename using request interception.

- [ ] **Step 1: Change catalog assertions to the generated set**

Fetch `data/fonts.json` in the page, assert its length equals the displayed product count, require at least 400 rows, and require the expanded list row count to equal that catalog length. Retain assertions that nine preview samples load separately.

- [ ] **Step 2: Intercept only the large archive response**

Before clicking the paid download button, register a Playwright route matching `**/downloads/fontseller-open-fonts.zip` and fulfill it with a small ZIP-like test body and `Content-Type: application/zip`. Wait for the browser download event and assert `download.suggestedFilename()` equals `fontseller-open-fonts.zip`.

- [ ] **Step 3: Run the browser journey**

Serve the repository on `http://127.0.0.1:8123`, run `node tests/e2e.test.js`, then stop the server.

Expected: catalog, preview, PromptPay recovery/demo success, paid page, and intercepted archive filename all PASS without transferring the committed 90 MiB file.

---

### Task 5: Release and GitHub Pages Verification

**Files:**
- Modify only files listed in Tasks 1-4 plus the approved design and this plan.

**Interfaces:**
- Consumes: all generated and source artifacts.
- Produces: one pushed `main` commit and matching GitHub Pages deployment.

- [ ] **Step 1: Run the complete local release gate**

Run:

```powershell
python -m unittest tests.Unit.Fonts.font_bundle_test -v
node tests/Unit/Payment/payments.test.js
node --check js/config.js
node --check js/fonts.js
node --check js/downloads.js
node --check js/app.js
node --check tests/e2e.test.js
git diff --check
```

Then run the Playwright journey from Task 4. Expected: every command exits zero.

- [ ] **Step 2: Inspect only scoped changes**

Run `git status --short`, `git diff --stat`, and targeted diffs for HTML, JavaScript, README, tests, generator, spec, and plan. Confirm the ZIP is the only large file, remains below 100 MiB, and no unrelated file changed.

- [ ] **Step 3: Commit and push the confirmed GitHub update**

Stage only the scoped paths and commit with `feat: publish open-license font bundle`, then run `git push origin main`.

- [ ] **Step 4: Verify deployed GitHub Pages artifacts**

After deployment, fetch cache-busted `data/fonts.json`, `js/app.js`, `js/downloads.js`, and `downloads/fontseller-open-fonts.zip`. Require the deployed catalog count and SHA-256 to match local, require the scripts to reference the static archive and no longer reference JSZip, and require the deployed archive content length and SHA-256 to match the committed file.
