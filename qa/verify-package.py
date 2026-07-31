#!/usr/bin/env python3
"""Validate archive safety, CRC integrity, and the embedded source manifest."""

from __future__ import annotations

import hashlib
from pathlib import Path, PurePosixPath
import stat
import sys
import zipfile


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def main() -> int:
    if len(sys.argv) != 2:
        print("usage: verify-package.py ARCHIVE", file=sys.stderr)
        return 2
    archive_path = Path(sys.argv[1]).resolve()
    failures: list[str] = []
    with zipfile.ZipFile(archive_path) as archive:
        bad_crc = archive.testzip()
        if bad_crc:
            failures.append(f"CRC failure: {bad_crc}")
        infos = archive.infolist()
        names = [info.filename for info in infos]
        for info in infos:
            item = PurePosixPath(info.filename)
            if item.is_absolute() or ".." in item.parts or not item.parts or item.parts[0] != "sabri-membership-core":
                failures.append(f"unsafe path: {info.filename}")
            mode = (info.external_attr >> 16) & 0o170000
            if mode == stat.S_IFLNK:
                failures.append(f"symlink entry: {info.filename}")
            if ".smc-tmp-" in info.filename or ".erase-" in info.filename or info.filename.endswith((".bak", "~")):
                failures.append(f"runtime remnant: {info.filename}")
        manifest_name = "sabri-membership-core/MANIFEST.sha256"
        if manifest_name not in names:
            failures.append("embedded manifest missing")
        else:
            manifest: dict[str, str] = {}
            for line in archive.read(manifest_name).decode("utf-8").splitlines():
                digest, separator, relative = line.partition("  ")
                if len(digest) != 64 or not separator or not relative:
                    failures.append(f"malformed manifest line: {line}")
                    continue
                manifest[relative] = digest
            packaged = {
                name.removeprefix("sabri-membership-core/"): sha256(archive.read(name))
                for name in names
                if name != manifest_name and not name.endswith("/")
            }
            if manifest != packaged:
                failures.append("embedded manifest does not exactly match packaged files")
    if failures:
        print(f"Package assertions failed: {len(failures)}")
        for failure in failures:
            print(f"- {failure}")
        return 1
    print(f"Archive entries: {len(infos)}")
    print("Unsafe entries: 0")
    print("Symlink entries: 0")
    print("Manifest mismatches: 0")
    print("ZIP CRC failures: 0")
    print(f"Archive SHA-256: {sha256(archive_path.read_bytes())}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
