"""Build a clean release zip of guardkids-wp.

Why this script exists (memory): Compress-Archive and CreateFromDirectory on
Windows write backslashes inside the zip, which breaks WordPress plugin
extraction. zipfile in Python lets us control entry names directly and use
forward slashes natively.
"""
from __future__ import annotations

import os
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PLUGIN_SLUG = "guardkids-wp"

EXCLUDE_DIRS = {
    ".git",
    ".github",
    ".phpunit.cache",
    "vendor",
    "tests",
    "docs",
    "scripts",
    "tools",
    "node_modules",
}

EXCLUDE_FILES_ROOT = {
    "composer.lock",
    "phpunit.xml.dist",
    "phpunit-integration.xml.dist",
    "docker-compose.test.yml",
    ".gitignore",
}

SPA_KEEP_ONLY = {"dist"}
SPA_NAMES = {"app-parent", "app-child"}


def should_skip_dir(rel_parts: tuple[str, ...]) -> bool:
    if not rel_parts:
        return False
    if rel_parts[0] in EXCLUDE_DIRS:
        return True
    if (
        len(rel_parts) >= 3
        and rel_parts[0] == "public"
        and rel_parts[1] in SPA_NAMES
        and rel_parts[2] not in SPA_KEEP_ONLY
    ):
        return True
    return False


def should_skip_file(rel_parts: tuple[str, ...]) -> bool:
    if not rel_parts:
        return True
    if len(rel_parts) == 1 and rel_parts[0] in EXCLUDE_FILES_ROOT:
        return True
    if (
        len(rel_parts) == 3
        and rel_parts[0] == "public"
        and rel_parts[1] in SPA_NAMES
    ):
        return True
    return False


def build_zip(out_path: Path) -> tuple[int, int]:
    file_count = 0
    total_bytes = 0
    with zipfile.ZipFile(out_path, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for dirpath, dirnames, filenames in os.walk(ROOT):
            rel_dir = Path(dirpath).relative_to(ROOT)
            rel_parts = tuple(rel_dir.parts)
            dirnames[:] = [
                d for d in dirnames if not should_skip_dir(rel_parts + (d,))
            ]
            for fname in filenames:
                fparts = rel_parts + (fname,)
                if should_skip_file(fparts):
                    continue
                src = Path(dirpath) / fname
                arcname = "/".join((PLUGIN_SLUG, *fparts))
                zf.write(src, arcname)
                file_count += 1
                total_bytes += src.stat().st_size
    return file_count, total_bytes


def main() -> int:
    out_dir = Path(sys.argv[1]) if len(sys.argv) > 1 else ROOT.parent / "guardkids-wp-release"
    out_dir.mkdir(parents=True, exist_ok=True)

    # Lê o campo `Version:` do header do plugin — a versão canônica que o
    # WordPress reconhece. NÃO usar a constante GUARDKIDS_VERSION: ela já ficou
    # dessincronizada do header e nomeou zips errados.
    version = "unknown"
    plugin_file = ROOT / "guardkids.php"
    for line in plugin_file.read_text(encoding="utf-8").splitlines():
        stripped = line.lstrip(" *\t")
        if stripped.startswith("Version:"):
            version = stripped.split(":", 1)[1].strip()
            break

    out_path = out_dir / f"{PLUGIN_SLUG}-{version}.zip"
    file_count, total_bytes = build_zip(out_path)
    zip_size = out_path.stat().st_size

    print(f"out:    {out_path}")
    print(f"files:  {file_count}")
    print(f"src:    {total_bytes/1024:.1f} KB raw")
    print(f"zip:    {zip_size/1024:.1f} KB compressed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
