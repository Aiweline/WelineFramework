#!/usr/bin/env python3
"""Migrate module widget CSS/PHTML to theme tokens (REQ-THEME-0007).

work_mode=default_theme for disk leaf writes; widget CSS owned by modules.
"""
from __future__ import annotations

import argparse
import re
import sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[5]  # .../框架
CODE = ROOT / "app/code"
FRONTEND_VARS = ROOT / "app/code/Weline/Theme/view/theme/frontend/variables"
FRONTEND_COLORS = ROOT / "app/code/Weline/Theme/view/theme/frontend/colors"

RE_COMMENT = re.compile(r"/\*.*?\*/", re.S)
RE_DATA_URL = re.compile(r"url\(\s*['\"]?data:[^)]+\)", re.I)
RE_MEDIA_COND = re.compile(r"@media[^{]+\{", re.I)
RE_VAR_FALLBACK = re.compile(
    r"var\(\s*(--[A-Za-z0-9_-]+)\s*,\s*((?:#[0-9a-fA-F]{3,8}|rgba?\([^)]*\)|hsla?\([^)]*\)|"
    r"transparent|currentColor|white|black|red|blue|green|gray|grey|"
    r"\d+(?:\.\d+)?(?:px|rem|em)))\s*\)",
    re.I,
)
RE_HEX = re.compile(r"#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\b")
RE_RGB = re.compile(r"\b(?:rgba?|hsla?)\(\s*[^)]+\)", re.I)
# Do not match fractional tails after a decimal point (avoids breaking -0.01em → -0.var(...))
RE_SIZE = re.compile(r"(?<![\w.-])(\d+(?:\.\d+)?)(px|rem|em)\b")
RE_STYLE_BLOCK = re.compile(r"(<style\b[^>]*>)(.*?)(</style>)", re.I | re.S)

# Explicit preferred mappings (override disk reverse-index collisions)
COLOR_OVERRIDES = {
    "#ffffff": "--color-bg-primary",
    "#fff": "--color-bg-primary",
    "#fffefa": "--color-bg-primary",
    "#000000": "--color-text-primary",
    "#000": "--color-text-primary",
    "#111111": "--color-text-primary",
    "#111": "--color-text-primary",
    "#1c1c1c": "--color-text-primary",
    "#1a1a1a": "--color-text-primary",
    "#0f1111": "--color-text-primary",
    "#111827": "--color-text-slate",
    "#565959": "--color-text-gray",
    "#666666": "--color-text-gray-mid",
    "#666": "--color-text-gray-mid",
    "#b84a3c": "--color-brand-orange",
    "#963b30": "--color-brand-orange-hover",
    "#3d6b78": "--color-link",
    "#d4cfc5": "--color-border-default",
    "#ede9e1": "--color-bg-secondary",
    "#f7f4ef": "--color-bg-canvas",
    "#f8ede9": "--color-primary-bg-subtle",
    "#4a7c59": "--color-success",
    "#232f3e": "--color-bg-dark",
    "#131921": "--color-bg-dark-secondary",
    "#eaeded": "--color-bg-secondary",
    "#e7e7e7": "--color-bg-tertiary",
    "#dddddd": "--color-border-default",
    "#ddd": "--color-border-default",
    "#ccc": "--color-border-faint",
    "#cccccc": "--color-border-faint",
    "#e5e5e5": "--color-border-default",
    "#e5e7eb": "--color-border-light",
    "#f3f3f3": "--color-bg-muted",
    "#f7f8f8": "--color-bg-secondary",
    "#f0f2f2": "--color-bg-secondary",
    "#adb1b8": "--color-border-emphasis",
    "#b12704": "--color-danger",
    "#3b82f6": "--color-accent",
    "#2563eb": "--color-accent-hover",
    "#6b7280": "--color-text-tertiary",
    "#f8fafc": "--color-bg-slate",
    "#f59e0b": "--color-warning",
    "#15803d": "--color-success",
    "#b91c1c": "--color-danger",
    "#ff9900": "--color-primary",
    "#febd69": "--color-primary-light",
    "#ffc439": "--color-brand-paypal",
    "#f2b824": "--color-brand-paypal-hover",
    "#003087": "--color-brand-paypal-ink",
    "#f0b90b": "--color-brand-binance",
}

SIZE_OVERRIDES = {
    "0": None,  # leave bare 0
    "0px": None,
    "0rem": None,
    "0em": None,
    "1px": "--border-width-thin",
    "2px": "--border-width-medium",
    "3px": "--border-width-thick",
    "0.25rem": "--weline-space-1",
    "0.5rem": "--weline-space-2",
    "0.75rem": "--weline-space-3",
    "1rem": "--weline-space-4",
    "1.25rem": "--weline-space-5",
    "1.5rem": "--weline-space-6",
    "2rem": "--weline-space-8",
    "2.25rem": "--weline-space-9",
    "2.5rem": "--weline-space-10",
    "3rem": "--weline-space-12",
    "3.75rem": "--weline-space-15",
    "0.125rem": "--spacing-0_5",
    "0.375rem": "--spacing-1_5",
    "0.625rem": "--spacing-2_5",
    "0.875rem": "--spacing-3_5",
    "1.75rem": "--spacing-7",
    "4px": "--spacing-1",
    "8px": "--spacing-2",
    "12px": "--spacing-3",
    "16px": "--spacing-4",
    "20px": "--spacing-5",
    "24px": "--spacing-6",
    "32px": "--spacing-8",
}


def norm_hex(h: str) -> str:
    h = h.lower().lstrip("#")
    if len(h) == 3:
        h = "".join(c * 2 for c in h)
    elif len(h) == 4:
        h = "".join(c * 2 for c in h[:3])
    elif len(h) == 8:
        h = h[:6]
    return "#" + h


def is_widget_path(p: Path) -> bool:
    s = str(p).replace("\\", "/")
    if any(x in s for x in ("/test/", "/Test/", "/doc/", "/generated/", "/view/tpl/")):
        return False
    if "/widgets/" not in s:
        return False
    return p.suffix.lower() in {".css", ".phtml", ".html"}


def iter_widget_files() -> list[Path]:
    out = []
    for p in CODE.rglob("*"):
        if p.is_file() and is_widget_path(p):
            out.append(p)
    return sorted(out)


def strip_var_fallbacks(text: str) -> tuple[str, int]:
    n = 0
    while True:
        new, c = RE_VAR_FALLBACK.subn(r"var(\1)", text)
        n += c
        if c == 0:
            return text, n
        text = new


def media_spans(text: str) -> list[tuple[int, int]]:
    return [(m.start(), m.end()) for m in RE_MEDIA_COND.finditer(text)]


def in_spans(i: int, spans: list[tuple[int, int]]) -> bool:
    return any(a <= i < b for a, b in spans)


def token_slug_size(val: str) -> str:
    s = val.lower().replace(".", "-").replace("_", "-")
    s = re.sub(r"[^a-z0-9-]", "-", s)
    return f"--token-size-{s}"


def token_slug_color(val: str) -> str:
    if val.startswith("#"):
        return f"--color-literal-{norm_hex(val)[1:]}"
    slug = re.sub(r"[^a-z0-9]+", "-", val.lower()).strip("-")[:48]
    return f"--color-literal-{slug}"


def build_disk_maps() -> tuple[dict[str, str], dict[str, str], set[str]]:
    color_map: dict[str, str] = {}
    size_map: dict[str, str] = {}
    existing: set[str] = set()
    for path in list(FRONTEND_VARS.glob("*.css")) + list(FRONTEND_COLORS.glob("*.css")):
        src = path.read_text(encoding="utf-8", errors="replace")
        for m in re.finditer(r"(--[a-zA-Z0-9_-]+)\s*:\s*([^;]+);", src):
            name, val = m.group(1), m.group(2).strip()
            existing.add(name)
            if val.startswith("var("):
                continue
            hm = re.fullmatch(r"#([0-9a-fA-F]{3,8})", val)
            if hm:
                # prefer --color-* names
                key = norm_hex(val)
                prev = color_map.get(key)
                if prev is None or (name.startswith("--color-") and not prev.startswith("--color-")):
                    color_map[key] = name
                continue
            if re.fullmatch(r"\d+(?:\.\d+)?(?:px|rem|em)", val):
                key = val.lower()
                prev = size_map.get(key)
                if prev is None or (name.startswith("--weline-space-") and not prev.startswith("--weline-space-")):
                    size_map[key] = name
    for k, v in COLOR_OVERRIDES.items():
        color_map[norm_hex(k) if k.startswith("#") else k] = v
        if k.startswith("#") and len(k) in (4, 5):
            color_map[k.lower()] = v
    for k, v in SIZE_OVERRIDES.items():
        if v:
            size_map[k.lower()] = v
    return color_map, size_map, existing


def protect_regions(text: str) -> tuple[str, list[str]]:
    held: list[str] = []

    def hold(m: re.Match) -> str:
        held.append(m.group(0))
        return f"__HOLD_{len(held)-1}__"

    text = RE_DATA_URL.sub(hold, text)
    text = RE_COMMENT.sub(hold, text)
    return text, held


def restore_regions(text: str, held: list[str]) -> str:
    for i, v in enumerate(held):
        text = text.replace(f"__HOLD_{i}__", v)
    return text


def transform_css_body(
    body: str,
    color_map: dict[str, str],
    size_map: dict[str, str],
    new_colors: dict[str, str],
    new_sizes: dict[str, str],
) -> tuple[str, Counter]:
    stats: Counter = Counter()
    body, held = protect_regions(body)
    body, nfb = strip_var_fallbacks(body)
    stats["var_fallback_stripped"] = nfb

    spans = media_spans(body)

    # hex → var
    def repl_hex(m: re.Match) -> str:
        if in_spans(m.start(), spans):
            return m.group(0)
        raw = m.group(0)
        key = norm_hex(raw)
        tok = color_map.get(key) or color_map.get(raw.lower())
        if not tok:
            tok = token_slug_color(key)
            new_colors[tok] = key
            color_map[key] = tok
        stats["hex_mapped"] += 1
        return f"var({tok})"

    body = RE_HEX.sub(repl_hex, body)

    # rgb/rgba/hsl → var (create leaf if needed)
    def repl_rgb(m: re.Match) -> str:
        if in_spans(m.start(), spans):
            return m.group(0)
        raw = re.sub(r"\s+", " ", m.group(0).strip())
        tok = color_map.get(raw.lower())
        if not tok:
            tok = token_slug_color(raw)
            new_colors[tok] = raw
            color_map[raw.lower()] = tok
        stats["func_color_mapped"] += 1
        return f"var({tok})"

    body = RE_RGB.sub(repl_rgb, body)

    # sizes → var (skip media conditions; skip 0)
    def repl_size(m: re.Match) -> str:
        if in_spans(m.start(), spans):
            return m.group(0)
        num, unit = m.group(1), m.group(2)
        raw = f"{num}{unit}".lower()
        if raw in ("0px", "0rem", "0em") or num == "0":
            return m.group(0) if num == "0" else "0"
        # SIZE_OVERRIDES None means leave
        if raw in SIZE_OVERRIDES and SIZE_OVERRIDES[raw] is None:
            return "0"
        tok = size_map.get(raw)
        if not tok:
            tok = token_slug_size(raw)
            new_sizes[tok] = raw
            size_map[raw] = tok
        stats["size_mapped"] += 1
        return f"var({tok})"

    body = RE_SIZE.sub(repl_size, body)
    body = restore_regions(body, held)
    return body, stats


def transform_file(
    path: Path,
    color_map: dict[str, str],
    size_map: dict[str, str],
    new_colors: dict[str, str],
    new_sizes: dict[str, str],
) -> tuple[str, Counter]:
    raw = path.read_text(encoding="utf-8", errors="replace")
    stats: Counter = Counter()
    if path.suffix.lower() == ".css":
        out, st = transform_css_body(raw, color_map, size_map, new_colors, new_sizes)
        stats.update(st)
        return out, stats

    parts: list[str] = []
    last = 0
    for m in RE_STYLE_BLOCK.finditer(raw):
        parts.append(raw[last : m.start()])
        css, st = transform_css_body(m.group(2), color_map, size_map, new_colors, new_sizes)
        stats.update(st)
        parts.append(m.group(1) + css + m.group(3))
        last = m.end()
    parts.append(raw[last:])
    return "".join(parts), stats


def ensure_brand_tokens(existing: set[str]) -> list[str]:
    brands = {
        "--color-brand-paypal": "#ffc439",
        "--color-brand-paypal-hover": "#f2b824",
        "--color-brand-paypal-ink": "#003087",
        "--color-brand-binance": "#f0b90b",
    }
    add = {k: v for k, v in brands.items() if k not in existing}
    return add


def append_leaves(path: Path, entries: dict[str, str], comment: str) -> int:
    if not entries:
        return 0
    text = path.read_text(encoding="utf-8")
    # insert before final closing brace of :root block if present, else append
    block = "\n    /* " + comment + " */\n"
    for name, val in sorted(entries.items()):
        if re.search(rf"{re.escape(name)}\s*:", text):
            continue
        block += f"    {name}: {val};\n"
    if block.count(":") <= 1:
        return 0
    # Prefer insert before last `}` in file
    idx = text.rfind("}")
    if idx == -1:
        path.write_text(text + "\n:root {\n" + block + "}\n", encoding="utf-8")
    else:
        path.write_text(text[:idx] + block + text[idx:], encoding="utf-8")
    return block.count(";")


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--apply", action="store_true")
    ap.add_argument("--report", type=Path, default=ROOT / "dev/tmp/widget-token-migrate-report.txt")
    args = ap.parse_args()

    color_map, size_map, existing = build_disk_maps()
    brands = ensure_brand_tokens(existing)
    for k, v in brands.items():
        color_map[norm_hex(v)] = k
        existing.add(k)

    new_colors: dict[str, str] = dict(brands)
    new_sizes: dict[str, str] = {}
    total = Counter()
    changed_files: list[str] = []

    files = iter_widget_files()
    for path in files:
        out, st = transform_file(path, color_map, size_map, new_colors, new_sizes)
        total.update(st)
        if out != path.read_text(encoding="utf-8", errors="replace"):
            changed_files.append(str(path.relative_to(ROOT)))
            if args.apply:
                path.write_text(out, encoding="utf-8")

    if args.apply:
        n1 = append_leaves(
            FRONTEND_VARS / "_colors.css",
            {k: v for k, v in new_colors.items()},
            "widget token migrate brand/literal colors",
        )
        n2 = append_leaves(
            FRONTEND_VARS / "_auto-literals.css",
            new_sizes,
            "widget token migrate sizes",
        )
    else:
        n1 = len(new_colors)
        n2 = len(new_sizes)

    report = [
        f"files={len(files)} changed={len(changed_files)} apply={args.apply}",
        f"stats={dict(total)}",
        f"new_color_leaves={len(new_colors)} new_size_leaves={len(new_sizes)} written_colors={n1 if args.apply else 'dry'} written_sizes={n2 if args.apply else 'dry'}",
        "changed:",
        *changed_files[:200],
        f"... ({len(changed_files)} total)" if len(changed_files) > 200 else "",
        "new colors sample:",
        *[f"  {k}: {v}" for k, v in list(sorted(new_colors.items()))[:40]],
        "new sizes sample:",
        *[f"  {k}: {v}" for k, v in list(sorted(new_sizes.items()))[:40]],
    ]
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text("\n".join(report) + "\n", encoding="utf-8")
    print("\n".join(report[:30]))
    print(f"report → {args.report}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
