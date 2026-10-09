#!/usr/bin/env python3
"""Audit module widget CSS/PHTML for theme token hardcoding (REQ-THEME-0007)."""
from __future__ import annotations

import argparse
import json
import re
import sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[5]
CODE = ROOT / "app/code"

RE_HEX = re.compile(r"#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\b")
RE_RGB = re.compile(r"\b(?:rgb|rgba|hsl|hsla)\s*\(", re.I)
RE_SIZE = re.compile(r"(?<![\w.-])(\d+(?:\.\d+)?)(px|rem|em)\b")
RE_VAR_FALLBACK_COLOR = re.compile(
    r"var\(\s*--[^,)]+\s*,\s*(?:#[0-9a-fA-F]{3,8}|rgba?\([^)]*\)|hsla?\([^)]*\)|"
    r"white|black|red|transparent)\s*\)",
    re.I,
)
RE_VAR_FALLBACK_SIZE = re.compile(
    r"var\(\s*--[^,)]+\s*,\s*\d+(?:\.\d+)?(?:px|rem|em)\s*\)",
    re.I,
)
RE_INLINE_BAD = re.compile(
    r"""style\s*=\s*(["'])(?:(?!\1).)*(?:#[0-9a-fA-F]{3,8}|\d+(?:\.\d+)?px|rgba?\([^)]*\))(?:(?!\1).)*\1""",
    re.I | re.S,
)
RE_STYLE_BLOCK = re.compile(r"<style\b[^>]*>(.*?)</style>", re.I | re.S)
RE_COMMENT_CSS = re.compile(r"/\*.*?\*/", re.S)
RE_COMMENT_HTML = re.compile(r"<!--.*?-->", re.S)
RE_DATA_URL = re.compile(r"url\(\s*['\"]?data:[^)]+\)", re.I)
RE_MEDIA = re.compile(r"@media[^{]+\{", re.I)
RE_ZERO = re.compile(r"(?<![\w-])0(?:px|rem|em)\b")
RE_CORRUPT = re.compile(r"-0\.var\(--")


def is_widget_path(p: Path) -> bool:
    s = str(p).replace("\\", "/")
    if any(x in s for x in ("/generated/", "/view/tpl/", "/doc/", "/Test/", "/test/")):
        return False
    return "/widgets/" in s and p.suffix.lower() in {".css", ".phtml", ".html"}


def strip_noise(text: str, is_css: bool) -> str:
    text = RE_DATA_URL.sub("url()", text)
    text = RE_COMMENT_CSS.sub("", text)
    if not is_css:
        text = RE_COMMENT_HTML.sub("", text)
    return text


def scan_file(path: Path) -> list[dict]:
    raw = path.read_text(encoding="utf-8", errors="replace")
    hits: list[dict] = []
    is_css = path.suffix.lower() == ".css"
    surfaces: list[tuple[str, str]] = (
        [("file", raw)]
        if is_css
        else [("style_block", m.group(1)) for m in RE_STYLE_BLOCK.finditer(raw)]
        + [("markup", raw)]
    )
    for kind, body in surfaces:
        cleaned = strip_noise(body, is_css or kind == "style_block")
        media = [(m.start(), m.end()) for m in RE_MEDIA.finditer(cleaned)]

        def in_media(i: int) -> bool:
            return any(a <= i < b for a, b in media)

        if kind in ("file", "style_block"):
            if RE_CORRUPT.search(cleaned):
                hits.append({"code": "migrator_corrupt", "match": "-0.var(--", "line": 1})
            for m in RE_HEX.finditer(cleaned):
                hits.append({"code": "hard_color_hex", "match": m.group(), "line": cleaned.count("\n", 0, m.start()) + 1})
            for m in RE_RGB.finditer(cleaned):
                hits.append({"code": "hard_color_func", "match": cleaned[m.start() : m.start() + 48], "line": cleaned.count("\n", 0, m.start()) + 1})
            for m in RE_SIZE.finditer(cleaned):
                if RE_ZERO.fullmatch(m.group()) or in_media(m.start()):
                    continue
                hits.append({"code": "hard_size", "match": m.group(), "line": cleaned.count("\n", 0, m.start()) + 1})
            for m in RE_VAR_FALLBACK_COLOR.finditer(cleaned):
                hits.append({"code": "var_fallback_color", "match": m.group()[:90], "line": cleaned.count("\n", 0, m.start()) + 1})
            for m in RE_VAR_FALLBACK_SIZE.finditer(cleaned):
                hits.append({"code": "var_fallback_size", "match": m.group()[:90], "line": cleaned.count("\n", 0, m.start()) + 1})
        if kind == "markup":
            for m in RE_INLINE_BAD.finditer(cleaned):
                hits.append({"code": "inline_style_literal", "match": m.group()[:120], "line": cleaned.count("\n", 0, m.start()) + 1})
    return hits


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--json", action="store_true")
    args = ap.parse_args()
    by_code: Counter = Counter()
    bad = []
    scanned = 0
    for p in sorted(CODE.rglob("*")):
        if not (p.is_file() and is_widget_path(p)):
            continue
        scanned += 1
        hits = scan_file(p)
        if hits:
            by_code.update(h["code"] for h in hits)
            bad.append({"path": str(p.relative_to(ROOT)), "hit_count": len(hits), "hits": hits[:40]})
    report = {
        "schema": "widget-theme-token-audit.v1",
        "ok": len(bad) == 0,
        "files_scanned": scanned,
        "bad_files": len(bad),
        "by_code": dict(by_code),
        "files": bad,
    }
    if args.json:
        print(json.dumps(report, ensure_ascii=False, indent=2))
    else:
        print(f"scanned={scanned} bad_files={len(bad)} by_code={dict(by_code)} ok={report['ok']}")
        for f in bad[:30]:
            print(f"  {f['hit_count']:4d} {f['path']}")
    return 0 if report["ok"] else 1


if __name__ == "__main__":
    sys.exit(main())
