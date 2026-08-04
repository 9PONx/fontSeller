#!/usr/bin/env python3
"""Build the public FontSeller bundle from explicitly redistributable fonts."""

from __future__ import annotations

import argparse
import csv
import hashlib
import io
import json
import os
import sys
import tempfile
import uuid
import zipfile
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path

from fontTools.ttLib import TTFont


MAX_ARCHIVE_BYTES = 100 * 1024 * 1024
ALLOWED_EXTENSIONS = {".ttf", ".otf"}
SFNT_SIGNATURES = {b"\x00\x01\x00\x00", b"OTTO", b"true", b"typ1"}
CONFLICT_MARKERS = ("microsoft supplied font", "any other use is prohibited")
LICENSE_RULES = (
    (
        "OFL-1.1",
        "https://openfontlicense.org/",
        ("open font license", "openfontlicense.org", "scripts.sil.org/ofl"),
    ),
    (
        "Apache-2.0",
        "https://www.apache.org/licenses/LICENSE-2.0",
        ("apache license", "apache.org/licenses/license-2.0"),
    ),
    (
        "Ubuntu-Font-1.0",
        "https://ubuntu.com/legal/font-licence",
        ("ubuntu font licence", "ubuntu font license"),
    ),
)


@dataclass(frozen=True)
class FontRecord:
    path: Path
    payload: bytes
    file_name: str
    display_name: str
    extension: str
    file_size: int
    sha256: str
    license_id: str
    license_url: str
    copyright: str
    license_text: str
    modified_at: str


@dataclass(frozen=True)
class ScanResult:
    accepted: list[FontRecord]
    restricted: list[str]
    unlicensed_count: int
    invalid_format_count: int


def is_supported_sfnt(header: bytes) -> bool:
    """Return whether the first four bytes identify a TrueType/OpenType SFNT."""
    return bytes(header[:4]) in SFNT_SIGNATURES


def detect_allowlisted_license(metadata_text: str) -> tuple[str, str] | None:
    """Detect an allowlisted license without applying conflicting restrictions."""
    normalized = " ".join(str(metadata_text).casefold().split())
    for license_id, license_url, markers in LICENSE_RULES:
        if any(marker in normalized for marker in markers):
            return license_id, license_url
    return None


def classify_license(metadata_text: str) -> tuple[str, str] | None:
    """Return the explicit allowlisted license, with restrictions taking priority."""
    normalized = " ".join(str(metadata_text).casefold().split())
    if not normalized or any(marker in normalized for marker in CONFLICT_MARKERS):
        return None
    return detect_allowlisted_license(normalized)


def _decode_name_records(font: TTFont) -> list[tuple[int, int, int, str]]:
    if "name" not in font:
        return []
    decoded: list[tuple[int, int, int, str]] = []
    for record in font["name"].names:
        try:
            value = " ".join(record.toUnicode().replace("\x00", " ").split())
        except (UnicodeDecodeError, AttributeError):
            continue
        if value:
            decoded.append((record.nameID, record.platformID, record.langID, value))
    return decoded


def _preferred_name(records: list[tuple[int, int, int, str]], name_id: int) -> str:
    candidates = [record for record in records if record[0] == name_id]
    if not candidates:
        return ""

    def score(record: tuple[int, int, int, str]) -> tuple[int, int, str]:
        _, platform_id, lang_id, value = record
        english = lang_id in {0, 0x0409}
        windows = platform_id == 3
        return (0 if english else 1, 0 if windows else 1, value.casefold())

    return min(candidates, key=score)[3]


def _display_name(records: list[tuple[int, int, int, str]], fallback: str) -> str:
    full_name = _preferred_name(records, 4)
    if full_name:
        return full_name
    family = _preferred_name(records, 16) or _preferred_name(records, 1)
    style = _preferred_name(records, 17) or _preferred_name(records, 2)
    combined = " ".join(part for part in (family, style) if part).strip()
    return combined or fallback


def _metadata_value(records: list[tuple[int, int, int, str]], name_id: int) -> str:
    values: list[str] = []
    seen: set[str] = set()
    for current_id, _, _, value in records:
        if current_id == name_id and value not in seen:
            seen.add(value)
            values.append(value)
    return " | ".join(values)


def scan_fonts(source: Path) -> ScanResult:
    source = source.resolve(strict=True)
    if not source.is_dir():
        raise ValueError(f"font source is not a directory: {source}")

    candidates = sorted(
        (
            path
            for path in source.iterdir()
            if path.suffix.casefold() in ALLOWED_EXTENSIONS
            and path.is_file()
            and not path.is_symlink()
        ),
        key=lambda path: (path.name.casefold(), path.name),
    )
    if not candidates:
        raise ValueError(f"font source contains no TTF/OTF files: {source}")

    folded_names: dict[str, str] = {}
    accepted: list[FontRecord] = []
    restricted: list[str] = []
    unlicensed_count = 0
    invalid_format_count = 0

    for path in candidates:
        with path.open("rb") as source_file:
            if not is_supported_sfnt(source_file.read(4)):
                invalid_format_count += 1
                continue

        folded = path.name.casefold()
        if folded in folded_names:
            raise ValueError(
                f"case-insensitive filename collision: {folded_names[folded]} and {path.name}"
            )
        folded_names[folded] = path.name

        before = path.stat()
        try:
            with TTFont(path, lazy=True) as font:
                names = _decode_name_records(font)
        except Exception as exc:
            raise ValueError(f"cannot parse font metadata: {path.name}: {exc}") from exc

        metadata_text = "\n".join(value for _, _, _, value in names)
        has_conflict = any(marker in metadata_text.casefold() for marker in CONFLICT_MARKERS)
        allowlisted_result = detect_allowlisted_license(metadata_text)
        license_result = classify_license(metadata_text)
        if license_result is None:
            if has_conflict and allowlisted_result is not None:
                restricted.append(path.name)
            else:
                unlicensed_count += 1
            continue

        payload = path.read_bytes()
        after = path.stat()
        if (
            before.st_size != after.st_size
            or before.st_mtime_ns != after.st_mtime_ns
            or len(payload) != before.st_size
        ):
            raise RuntimeError(f"font changed while being read: {path.name}")

        license_id, canonical_license_url = license_result
        embedded_url = _metadata_value(names, 14)
        accepted.append(
            FontRecord(
                path=path,
                payload=payload,
                file_name=path.name,
                display_name=_display_name(names, path.stem),
                extension=path.suffix[1:].casefold(),
                file_size=len(payload),
                sha256=hashlib.sha256(payload).hexdigest(),
                license_id=license_id,
                license_url=embedded_url or canonical_license_url,
                copyright=_metadata_value(names, 0),
                license_text=_metadata_value(names, 13),
                modified_at=datetime.fromtimestamp(
                    after.st_mtime, tz=timezone.utc
                ).strftime("%Y-%m-%d %H:%M:%S"),
            )
        )

    if not accepted:
        raise ValueError("no fonts contain an explicit allowlisted redistribution license")
    return ScanResult(accepted, restricted, unlicensed_count, invalid_format_count)


def _manifest_rows(records: list[FontRecord]) -> list[dict[str, object]]:
    return [
        {
            "file_name": record.file_name,
            "display_name": record.display_name,
            "file_size": record.file_size,
            "sha256": record.sha256,
            "license_id": record.license_id,
            "copyright": record.copyright,
            "license_url": record.license_url,
        }
        for record in records
    ]


def _catalog_rows(records: list[FontRecord]) -> list[dict[str, object]]:
    rows: list[dict[str, object]] = []
    for index, record in enumerate(records, start=1):
        rows.append(
            {
                "id": index,
                "file_name": record.file_name,
                "display_name": record.display_name,
                "extension": record.extension,
                "file_size": record.file_size,
                "modified_at": record.modified_at,
                "preview_status": "supported",
                "last_error": None,
                "is_active": True,
                "created_at": record.modified_at,
                "updated_at": record.modified_at,
                "sha256": record.sha256,
                "license_id": record.license_id,
                "license_url": record.license_url,
                "copyright": record.copyright,
            }
        )
    return rows


def _zip_info(name: str) -> zipfile.ZipInfo:
    info = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
    info.compress_type = zipfile.ZIP_STORED
    info.create_system = 3
    info.external_attr = 0o100644 << 16
    return info


def _build_readme(records: list[FontRecord]) -> str:
    source_bytes = sum(record.file_size for record in records)
    return (
        "FontSeller Open Font Bundle\n"
        "===========================\n\n"
        f"Fonts: {len(records)}\n"
        f"Source font bytes: {source_bytes}\n\n"
        "This educational bundle includes only TTF/OTF files whose embedded "
        "OpenType metadata explicitly identifies SIL Open Font License 1.1, "
        "Apache License 2.0, or Ubuntu Font License 1.0. Files with conflicting "
        "Microsoft redistribution restrictions are excluded.\n\n"
        "Each font remains byte-for-byte unchanged and retains its embedded "
        "copyright and license metadata. See manifest.json or manifest.csv for "
        "the recorded license and SHA-256 of each file.\n"
    )


def _write_zip(path: Path, records: list[FontRecord]) -> None:
    manifest = _manifest_rows(records)
    csv_buffer = io.StringIO(newline="")
    writer = csv.DictWriter(csv_buffer, fieldnames=list(manifest[0]))
    writer.writeheader()
    writer.writerows(manifest)

    with zipfile.ZipFile(path, "w", compression=zipfile.ZIP_STORED, allowZip64=False) as bundle:
        for record in records:
            bundle.writestr(_zip_info(f"fonts/{record.file_name}"), record.payload)
        bundle.writestr(
            _zip_info("manifest.json"),
            json.dumps(manifest, ensure_ascii=False, indent=2).encode("utf-8") + b"\n",
        )
        bundle.writestr(_zip_info("manifest.csv"), csv_buffer.getvalue().encode("utf-8"))
        bundle.writestr(_zip_info("README.txt"), _build_readme(records).encode("utf-8"))


def _write_catalog(path: Path, records: list[FontRecord]) -> None:
    path.write_text(
        json.dumps(_catalog_rows(records), ensure_ascii=False, indent=4) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def _verify_outputs(zip_path: Path, catalog_path: Path, records: list[FontRecord]) -> None:
    if zip_path.stat().st_size >= MAX_ARCHIVE_BYTES:
        raise ValueError(
            f"archive is {zip_path.stat().st_size} bytes; GitHub requires less than {MAX_ARCHIVE_BYTES}"
        )

    catalog = json.loads(catalog_path.read_text(encoding="utf-8"))
    expected = {record.file_name: record for record in records}
    if {row["file_name"] for row in catalog} != set(expected):
        raise RuntimeError("generated catalog does not match accepted fonts")

    with zipfile.ZipFile(zip_path) as bundle:
        if bundle.testzip() is not None:
            raise RuntimeError("generated ZIP failed CRC validation")
        font_members = {
            name.removeprefix("fonts/")
            for name in bundle.namelist()
            if name.startswith("fonts/")
        }
        if font_members != set(expected):
            raise RuntimeError("generated ZIP font members do not match accepted fonts")
        manifest = json.loads(bundle.read("manifest.json").decode("utf-8"))
        if {row["file_name"] for row in manifest} != set(expected):
            raise RuntimeError("generated JSON manifest does not match accepted fonts")
        csv_rows = list(
            csv.DictReader(io.StringIO(bundle.read("manifest.csv").decode("utf-8")))
        )
        if {row["file_name"] for row in csv_rows} != set(expected):
            raise RuntimeError("generated CSV manifest does not match accepted fonts")
        for name, record in expected.items():
            payload = bundle.read(f"fonts/{name}")
            if len(payload) != record.file_size or hashlib.sha256(payload).hexdigest() != record.sha256:
                raise RuntimeError(f"generated ZIP content mismatch: {name}")


def _temporary_sibling(destination: Path) -> Path:
    destination.parent.mkdir(parents=True, exist_ok=True)
    handle = tempfile.NamedTemporaryFile(
        prefix=f".{destination.name}.", suffix=".tmp", dir=destination.parent, delete=False
    )
    handle.close()
    return Path(handle.name)


def _publish_pair(temp_zip: Path, zip_path: Path, temp_catalog: Path, catalog_path: Path) -> None:
    destinations = ((temp_zip, zip_path), (temp_catalog, catalog_path))
    backups: dict[Path, Path] = {}
    installed: list[Path] = []
    try:
        for _, destination in destinations:
            if destination.exists():
                backup = destination.with_name(f".{destination.name}.{uuid.uuid4().hex}.bak")
                os.replace(destination, backup)
                backups[destination] = backup
        for temporary, destination in destinations:
            os.replace(temporary, destination)
            installed.append(destination)
    except Exception:
        for destination in reversed(installed):
            if destination.exists():
                destination.unlink()
        for destination, backup in backups.items():
            if backup.exists():
                os.replace(backup, destination)
        raise
    else:
        for backup in backups.values():
            backup.unlink(missing_ok=True)


def build_bundle(source: Path, zip_path: Path, catalog_path: Path) -> ScanResult:
    result = scan_fonts(source)
    temp_zip = _temporary_sibling(zip_path)
    temp_catalog = _temporary_sibling(catalog_path)
    try:
        _write_zip(temp_zip, result.accepted)
        _write_catalog(temp_catalog, result.accepted)
        _verify_outputs(temp_zip, temp_catalog, result.accepted)
        _publish_pair(temp_zip, zip_path, temp_catalog, catalog_path)
    finally:
        temp_zip.unlink(missing_ok=True)
        temp_catalog.unlink(missing_ok=True)
    return result


def parse_args(argv: list[str]) -> argparse.Namespace:
    root = Path(__file__).resolve().parents[1]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, default=Path(r"C:\Windows\Fonts"))
    parser.add_argument(
        "--archive", type=Path, default=root / "downloads" / "fontseller-open-fonts.zip"
    )
    parser.add_argument("--catalog", type=Path, default=root / "data" / "fonts.json")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(sys.argv[1:] if argv is None else argv)
    result = build_bundle(args.source, args.archive, args.catalog)
    source_bytes = sum(record.file_size for record in result.accepted)
    print(f"accepted={len(result.accepted)}")
    print(f"source_mib={source_bytes / 1048576:.2f}")
    print(f"archive_mib={args.archive.stat().st_size / 1048576:.2f}")
    print(f"restricted={','.join(result.restricted) or '-'}")
    print(f"unlicensed={result.unlicensed_count}")
    print(f"invalid_format={result.invalid_format_count}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
