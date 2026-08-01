#!/usr/bin/env python3
"""Create a deterministic WordPress plugin archive and checksum receipts."""

from __future__ import annotations

import hashlib
import os
from pathlib import Path
import zipfile

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "source" / "sabri-membership-core"
DIST = ROOT / "dist"
VERSION = "1.2.3"
ARCHIVE = DIST / f"00-sabri-membership-core-{VERSION}.zip"
FIXED_TIME = (2026, 8, 2, 0, 0, 0)


def digest(path: Path) -> str:
    hasher = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            hasher.update(chunk)
    return hasher.hexdigest()


def source_files() -> list[Path]:
    return sorted(
        (
            path
            for path in PLUGIN.rglob("*")
            if path.is_file()
            and not path.is_symlink()
            and path.name != "MANIFEST.sha256"
            and "__pycache__" not in path.parts
        ),
        key=lambda path: path.relative_to(PLUGIN).as_posix(),
    )


def write_manifest(files: list[Path]) -> Path:
    manifest = PLUGIN / "MANIFEST.sha256"
    lines = [
        f"{digest(path)}  {path.relative_to(PLUGIN).as_posix()}"
        for path in files
    ]
    content = ("\n".join(lines) + "\n").encode("utf-8")
    if not manifest.exists() or manifest.read_bytes() != content:
        manifest.write_bytes(content)
    os.chmod(manifest, 0o644)
    return manifest


def add_file(archive: zipfile.ZipFile, path: Path) -> None:
    relative = Path("sabri-membership-core") / path.relative_to(PLUGIN)
    info = zipfile.ZipInfo(relative.as_posix(), FIXED_TIME)
    info.compress_type = zipfile.ZIP_DEFLATED
    info.create_system = 3
    info.external_attr = 0o100644 << 16
    archive.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)


def main() -> None:
    DIST.mkdir(parents=True, exist_ok=True)
    files = source_files()
    manifest = write_manifest(files)
    files = sorted(files + [manifest], key=lambda path: path.relative_to(PLUGIN).as_posix())
    temp = ARCHIVE.with_suffix(".zip.tmp")
    with zipfile.ZipFile(temp, "w", allowZip64=False) as archive:
        for path in files:
            add_file(archive, path)
    os.replace(temp, ARCHIVE)
    checksum = f"{digest(ARCHIVE)}  {ARCHIVE.name}\n"
    (DIST / "CHECKSUMS.sha256").write_text(checksum, encoding="utf-8", newline="\n")
    print(checksum.strip())


if __name__ == "__main__":
    main()
