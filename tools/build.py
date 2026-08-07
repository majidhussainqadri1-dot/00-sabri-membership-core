#!/usr/bin/env python3
"""Create a deterministic WordPress plugin archive and checksum receipts."""
from __future__ import annotations
import hashlib
import os
from pathlib import Path
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "source" / "sabri-membership-core"
DIST = ROOT / "dist"
ENTRYPOINT = PLUGIN / "sabri-membership-core.php"
FIXED_TIME = (2026, 8, 7, 15, 0, 0)


def release_version() -> str:
    source = ENTRYPOINT.read_text(encoding="utf-8")
    header = re.search(r"(?m)^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$", source)
    runtime = re.search(r"define\(\s*'SMC_VERSION'\s*,\s*'([0-9]+\.[0-9]+\.[0-9]+)'\s*\)", source)
    if not header or not runtime:
        raise SystemExit("Plugin release version is missing from the header or SMC_VERSION constant.")
    if header.group(1) != runtime.group(1):
        raise SystemExit(f"Plugin header/runtime version mismatch: {header.group(1)} != {runtime.group(1)}")
    return runtime.group(1)


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
    lines = [f"{digest(path)}  {path.relative_to(PLUGIN).as_posix()}" for path in files]
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
    archive.writestr(
        info,
        path.read_bytes(),
        compress_type=zipfile.ZIP_DEFLATED,
        compresslevel=9,
    )


def main() -> None:
    version = release_version()
    archive_path = DIST / f"00-sabri-membership-core-{version}.zip"
    DIST.mkdir(parents=True, exist_ok=True)
    files = source_files()
    manifest = write_manifest(files)
    files = sorted(files + [manifest], key=lambda path: path.relative_to(PLUGIN).as_posix())
    temp = archive_path.with_suffix(".zip.tmp")
    with zipfile.ZipFile(temp, "w", allowZip64=False) as archive:
        for path in files:
            add_file(archive, path)
    os.replace(temp, archive_path)
    checksum = f"{digest(archive_path)}  {archive_path.name}\n"
    (DIST / "CHECKSUMS.sha256").write_text(checksum, encoding="utf-8", newline="\n")
    print(checksum.strip())


if __name__ == "__main__":
    main()
