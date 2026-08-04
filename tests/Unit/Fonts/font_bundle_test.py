import csv
import hashlib
import io
import json
import unittest
import zipfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[3]
ARCHIVE = ROOT / "downloads" / "fontseller-open-fonts.zip"
CATALOG = ROOT / "data" / "fonts.json"
ALLOWED_LICENSES = {"OFL-1.1", "Apache-2.0", "Ubuntu-Font-1.0"}
RESTRICTED_FILES = {"CascadiaCode.ttf", "CascadiaMono.ttf", "seguihis.ttf"}


class FontBundleGeneratorTest(unittest.TestCase):
    def test_only_real_sfnt_signatures_are_scanned_as_fonts(self):
        """Catches random or non-font files that merely have a .ttf/.otf suffix."""
        from scripts.build_open_font_bundle import is_supported_sfnt

        for header in (b"\x00\x01\x00\x00", b"OTTO", b"true", b"typ1"):
            with self.subTest(header=header):
                self.assertTrue(is_supported_sfnt(header))
        self.assertFalse(is_supported_sfnt(b"\xbb\x73\x88\x67"))
        self.assertFalse(is_supported_sfnt(b""))

    def test_explicit_redistribution_licenses_are_classified(self):
        """Catches loss of any license family that the bundle promises to include."""
        from scripts.build_open_font_bundle import classify_license

        cases = {
            "Licensed under the SIL Open Font License, Version 1.1.": "OFL-1.1",
            "Licensed under the Apache License, Version 2.0.": "Apache-2.0",
            "Licensed under the Ubuntu Font Licence 1.0.": "Ubuntu-Font-1.0",
        }
        for metadata, expected in cases.items():
            with self.subTest(expected=expected):
                result = classify_license(metadata)
                self.assertIsNotNone(result)
                self.assertEqual(expected, result[0])

    def test_restrictions_override_an_embedded_open_license_reference(self):
        """Catches accidental redistribution of Windows-supplied restricted fonts."""
        from scripts.build_open_font_bundle import classify_license, detect_allowlisted_license

        metadata = (
            "Licensed under the SIL Open Font License 1.1. "
            "This is a Microsoft supplied font. Any other use is prohibited."
        )
        self.assertEqual("OFL-1.1", detect_allowlisted_license(metadata)[0])
        self.assertIsNone(classify_license(metadata))

    def test_generic_or_missing_license_metadata_is_rejected(self):
        """Catches permission being inferred from copyright or filename alone."""
        from scripts.build_open_font_bundle import classify_license

        for metadata in ("", "Copyright 2026 Example Foundry. All rights reserved."):
            with self.subTest(metadata=metadata):
                self.assertIsNone(classify_license(metadata))


class FontBundleIntegrityTest(unittest.TestCase):
    def test_archive_catalog_and_manifests_describe_identical_fonts(self):
        """Catches a published ZIP whose bytes or metadata diverge from the catalog."""
        self.assertTrue(ARCHIVE.is_file(), f"missing generated archive: {ARCHIVE}")
        self.assertLess(ARCHIVE.stat().st_size, 100 * 1024 * 1024)

        catalog = json.loads(CATALOG.read_text(encoding="utf-8"))
        self.assertGreater(len(catalog), 0)
        catalog_by_name = {row["file_name"]: row for row in catalog}
        self.assertEqual(len(catalog), len(catalog_by_name), "duplicate catalog filename")
        self.assertTrue(RESTRICTED_FILES.isdisjoint(catalog_by_name))
        self.assertTrue(
            all(row["license_id"] in ALLOWED_LICENSES for row in catalog),
            "catalog contains a license outside the redistribution allowlist",
        )

        with zipfile.ZipFile(ARCHIVE) as bundle:
            font_members = sorted(
                name for name in bundle.namelist() if name.startswith("fonts/")
            )
            expected_members = sorted(f"fonts/{name}" for name in catalog_by_name)
            self.assertEqual(expected_members, font_members)
            self.assertEqual(None, bundle.testzip(), "ZIP CRC validation failed")

            manifest = json.loads(bundle.read("manifest.json").decode("utf-8"))
            manifest_by_name = {row["file_name"]: row for row in manifest}
            self.assertEqual(set(catalog_by_name), set(manifest_by_name))

            csv_rows = list(
                csv.DictReader(io.StringIO(bundle.read("manifest.csv").decode("utf-8-sig")))
            )
            self.assertEqual(set(catalog_by_name), {row["file_name"] for row in csv_rows})

            for name, record in manifest_by_name.items():
                payload = bundle.read(f"fonts/{name}")
                self.assertEqual(record["file_size"], len(payload), name)
                self.assertEqual(record["sha256"], hashlib.sha256(payload).hexdigest(), name)
                self.assertEqual(record["license_id"], catalog_by_name[name]["license_id"], name)
                self.assertEqual(record["file_size"], catalog_by_name[name]["file_size"], name)


if __name__ == "__main__":
    unittest.main()
