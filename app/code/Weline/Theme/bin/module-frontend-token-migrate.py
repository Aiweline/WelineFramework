#!/usr/bin/env python3
"""Role-aware module frontend token migration (contract: 默认主题消费合同).

Differences vs widget-theme-token-migrate.py (deliberate):
  - NEVER invents tokens: only maps to tokens that already exist in the Theme disk.
    (Theme doc forbids `--token-size-*` / `--color-literal-*` dumping grounds.)
  - Role-aware: the CSS property decides which token family is legal
    (color: -> text/ink tokens, background: -> bg/surface tokens, ...).
  - Conservative: exact value (or <=0.5px nearest within the same family) only;
    everything else is reported as `review`, not written.
  - Strips `var(--token, literal)` fallbacks (documented as required).
  - Writes nothing unless --apply.

Usage:
  python3 module-frontend-token-migrate.py --module Weline_Compare            # dry run
  python3 module-frontend-token-migrate.py --module Weline_Compare --apply
  python3 module-frontend-token-migrate.py --all --report tmp/migrate.json
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import re
from collections import Counter, defaultdict
from pathlib import Path

HERE = Path(__file__).resolve().parent
THEME_ROOT = Path(__file__).resolve().parents[1]
THEME_FE = THEME_ROOT / "view/theme/frontend"


def _load(name: str, filename: str):
    spec = importlib.util.spec_from_file_location(name, HERE / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


audit = _load("mfa_audit", "module-frontend-theme-audit.py")
migrate = _load("wtt_migrate", "widget-theme-token-migrate.py")

RE_DECL_START = re.compile(r"[;{}]")
RE_PROP = re.compile(r"\s*([-a-zA-Z][-a-zA-Z0-9]*)\s*:")
RE_RGB_VALUE = re.compile(r"\b(?:rgba?|hsla?)\s*\(([^)]*)\)", re.I)
RE_ZERO_SIZE = re.compile(r"(?<![\w.-])0(?:px|rem|em)\b")
RE_BOX_SHADOW = re.compile(r"(-webkit-)?box-shadow\s*:\s*([^;{}]+);", re.I)

# property -> allowed token role families
PROP_ROLES: dict[str, set[str]] = {
    "color": {"text"},
    "-webkit-text-fill-color": {"text"},
    "caret-color": {"text"},
    "background": {"bg"},
    "background-color": {"bg"},
    "background-image": {"bg", "other"},
    "border": {"border", "border-width"},
    "border-top": {"border", "border-width"},
    "border-right": {"border", "border-width"},
    "border-bottom": {"border", "border-width"},
    "border-left": {"border", "border-width"},
    "border-color": {"border"},
    "border-top-color": {"border"},
    "border-right-color": {"border"},
    "border-bottom-color": {"border"},
    "border-left-color": {"border"},
    "outline": {"border", "border-width"},
    "outline-color": {"border"},
    "box-shadow": {"shadow"},
    "text-shadow": {"shadow"},
    "font-size": {"font-size"},
    "line-height": {"line-height", "space"},
    "letter-spacing": {"space"},
    "font": {"font-size"},
    "border-radius": {"radius"},
    "border-top-left-radius": {"radius"},
    "border-top-right-radius": {"radius"},
    "border-bottom-left-radius": {"radius"},
    "border-bottom-right-radius": {"radius"},
    "border-width": {"border-width"},
    "border-top-width": {"border-width"},
    "border-right-width": {"border-width"},
    "border-bottom-width": {"border-width"},
    "border-left-width": {"border-width"},
    "margin": {"space"},
    "margin-top": {"space"},
    "margin-right": {"space"},
    "margin-bottom": {"space"},
    "margin-left": {"space"},
    "margin-inline": {"space"},
    "margin-inline-start": {"space"},
    "margin-inline-end": {"space"},
    "margin-block": {"space"},
    "margin-block-start": {"space"},
    "margin-block-end": {"space"},
    "padding": {"space"},
    "padding-top": {"space"},
    "padding-right": {"space"},
    "padding-bottom": {"space"},
    "padding-left": {"space"},
    "padding-inline": {"space"},
    "padding-inline-start": {"space"},
    "padding-inline-end": {"space"},
    "padding-block": {"space"},
    "padding-block-start": {"space"},
    "padding-block-end": {"space"},
    "gap": {"space"},
    "row-gap": {"space"},
    "column-gap": {"space"},
    "grid-gap": {"space"},
    "min-height": {"control-height"},
}
SIZE_FAMILY = {"font-size", "line-height", "space", "radius", "border-width", "shadow", "control-height"}


def role_of_token(name: str) -> str:
    n = name.lower()
    if "radius" in n:
        return "radius"
    if "control-height" in n:
        return "control-height"
    if "shadow" in n:
        return "shadow"
    if "line-height" in n or "leading" in n:
        return "line-height"
    if "font-size" in n:
        return "font-size"
    if "border-width" in n:
        return "border-width"
    if any(k in n for k in ("space", "spacing", "gap", "gutter")):
        return "space"
    if any(k in n for k in ("bg-", "background", "surface", "canvas", "paper", "overlay")) or n.endswith("-bg"):
        return "bg"
    if "border" in n or "divider" in n or n.endswith("-line"):
        return "border"
    if any(k in n for k in ("text", "ink", "foreground", "-fg", "heading", "muted", "link",
                            "danger", "success", "warning", "info", "primary", "accent", "neutral")):
        return "text"
    return "other"


PREFERRED_PREFIX = {
    "color": ("--color-", "--weline-chrome-", "--weline-theme-"),
    "bg": ("--color-bg-", "--color-surface", "--color-canvas", "--weline-theme-"),
    "text": ("--color-text", "--color-link", "--weline-chrome-body-text", "--weline-theme-text"),
    "border": ("--color-border", "--weline-theme-border"),
    "space": ("--weline-space-", "--weline-layout-spacing-", "--weline-theme-", "--spacing-"),
    "radius": ("--weline-theme-radius-", "--radius-", "--border-radius-"),
    "font-size": ("--weline-font-size-", "--weline-layout-font-size-", "--font-size-"),
    "line-height": ("--weline-line-height", "--line-height-", "--leading-"),
    "border-width": ("--border-width-", "--weline-theme-border-width"),
    "control-height": ("--weline-theme-control-height", "--control-height"),
    "shadow": ("--weline-shadow", "--weline-theme-shadow", "--shadow-"),
}
# infrastructure / component-scoped / dumping-ground names: never auto-pick for modules
EXCLUDE_TOKEN_RE = re.compile(
    r"^--(?:token-|color-literal-|auth-|size-|weline-skeleton|_)|rgba-|[0-9]px$|demo|probe|sample|test")
# context-dependent families: only correct next to a matching tone/overlay context, never auto-map
CONTEXT_ONLY_RE = re.compile(r"^--(?:color-on-|color-overlay-|weline-theme-on-|weline-component-|amz-|weline-chrome-)")


def token_rank(name: str, role: str) -> tuple[int, int, str]:
    for i, prefix in enumerate(PREFERRED_PREFIX.get(role, ())):
        if name.startswith(prefix):
            return (i, len(name), name)
    return (99, len(name), name)


def build_index() -> tuple[dict[str, list[str]], dict[str, list[str]], dict[str, str]]:
    raw_decls: dict[str, str] = {}
    index_files = list((THEME_FE / "variables").glob("*.css")) + list((THEME_FE / "colors").rglob("*.css"))
    bridge = THEME_FE / "assets/css/theme.css"
    if bridge.is_file():
        index_files.append(bridge)
    for path in index_files:
        src = path.read_text(encoding="utf-8", errors="replace")
        for m in re.finditer(r"(--[a-zA-Z0-9_-]+)\s*:\s*([^;{}]+);", src):
            raw_decls.setdefault(m.group(1), m.group(2).strip())

    def resolve(name: str, depth: int = 0) -> str | None:
        if depth > 8:
            return None
        val = raw_decls.get(name)
        if val is None:
            return None
        vm = re.fullmatch(r"var\(\s*(--[a-zA-Z0-9_-]+)\s*(?:,\s*([^)]*))?\)", val.strip())
        if vm:
            return resolve(vm.group(1), depth + 1) or (vm.group(2).strip() if vm.group(2) else None)
        return val

    colors: dict[str, list[str]] = defaultdict(list)
    sizes: dict[str, list[str]] = defaultdict(list)
    resolved: dict[str, str] = {}
    for name in raw_decls:
        if EXCLUDE_TOKEN_RE.search(name):
            continue
        value = resolve(name)
        if not value:
            continue
        resolved[name] = value.strip()
        value = value.strip()
        hm = re.fullmatch(r"#([0-9a-fA-F]{3,8})", value)
        if hm:
            colors[migrate.norm_hex(value)].append(name)
            continue
        vm = re.fullmatch(r"(\d+(?:\.\d+)?)(px|rem|em)", value.lower())
        if vm:
            sizes[value.lower()].append(name)
    return colors, sizes, resolved


def rgb_to_hex(text: str) -> str | None:
    parts = [p.strip() for p in text.split(",")]
    if len(parts) < 3:
        return None
    try:
        r, g, b = (int(round(float(p))) for p in parts[:3])
    except ValueError:
        return None
    return f"#{r:02x}{g:02x}{b:02x}"


def px_of(value: str) -> float | None:
    m = re.fullmatch(r"(\d+(?:\.\d+)?)(px|rem|em)", value.strip().lower())
    if not m:
        return None
    n = float(m.group(1))
    return n * 16.0 if m.group(2) in ("rem", "em") else n


def decl_property(text: str, pos: int) -> str:
    start = max(text.rfind(";", 0, pos), text.rfind("{", 0, pos), text.rfind("}", 0, pos))
    chunk = text[start + 1:pos]
    m = RE_PROP.match(chunk)
    return m.group(1).lower() if m else ""


def hex_to_rgb(value: str) -> tuple[int, int, int] | None:
    if not value.startswith("#"):
        return None
    h = value.lstrip("#")
    if len(h) == 3:
        h = "".join(c * 2 for c in h)
    if len(h) < 6:
        return None
    return int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16)


def saturation(value: str) -> float:
    rgb = hex_to_rgb(value)
    if not rgb:
        return 1.0
    mx, mn = max(rgb), min(rgb)
    return 0.0 if mx == 0 else (mx - mn) / mx


def rgb_distance(a: str, b: str) -> float:
    ra, rb = hex_to_rgb(a), hex_to_rgb(b)
    if not ra or not rb:
        return 999
    return max(abs(x - y) for x, y in zip(ra, rb))


# 语义短名单：自动吸收只在这些「通用语义 Token」里选，避免落到 *-product-label-* / *-text-disabled 这类专名。
STATUS_TOKENS = (
    "--color-success", "--color-warning", "--color-danger", "--color-info",
    "--color-primary", "--color-link", "--color-accent",
)
TEXT_PREF = (
    "--color-text", "--color-text-primary", "--color-heading", "--color-text-secondary",
    "--color-text-muted", "--color-text-faint", "--weline-theme-text", "--weline-theme-text-muted",
    "--weline-chrome-body-text", "--color-link",
) + STATUS_TOKENS
BG_PREF = (
    "--color-bg-primary", "--color-bg-secondary", "--color-bg-tertiary", "--color-bg-muted",
    "--color-bg-panel", "--color-bg-canvas", "--color-surface", "--color-bg-slate",
    "--color-bg-dark", "--color-bg-dark-secondary",
) + STATUS_TOKENS
BORDER_PREF = (
    "--color-border", "--color-border-default", "--color-border-light", "--color-border-faint",
    "--color-border-soft", "--color-border-muted", "--color-border-subtle",
)

HUE_FAMILIES = {
    "blue":   ((185, 265), ("link", "info", "primary", "accent", "blue", "azure", "sky")),
    "green":  ((80, 170),  ("success", "green", "positive")),
    "red":    ((330, 20),  ("danger", "error", "red", "negative")),
    "orange": ((20, 60),   ("warning", "orange", "amber", "brand", "primary")),
    "purple": ((265, 330), ("accent", "purple", "violet", "brand")),
}


def lightness_of(value: str) -> float | None:
    rgb = hex_to_rgb(value)
    if not rgb:
        return None
    import colorsys
    _h, l, _s = colorsys.rgb_to_hls(*(c / 255 for c in rgb))
    return l


def hue_of(value: str) -> float | None:
    rgb = hex_to_rgb(value)
    if not rgb:
        return None
    import colorsys
    h, _l, _s = colorsys.rgb_to_hls(*(c / 255 for c in rgb))
    return h * 360


def hue_family(hue: float) -> str | None:
    for name, (span, _keys) in HUE_FAMILIES.items():
        lo, hi = span
        if lo > hi:
            if hue >= lo or hue <= hi:
                return name
        elif lo <= hue <= hi:
            return name
    return None


def pick_hue_token(value_hex: str, prop: str, resolved: dict[str, str]) -> str | None:
    allowed = PROP_ROLES.get(prop)
    if not allowed or not (allowed & {"text", "bg", "border"}):
        return None
    if saturation(value_hex) < 0.25:
        return None
    fam = hue_family(hue_of(value_hex) or 0)
    if fam is None:
        return None
    keys = HUE_FAMILIES[fam][1]
    src_l = lightness_of(value_hex)
    if src_l is None:
        return None
    if "text" in allowed:
        # 文字优先落 Theme 文字族：按明度就近，而不是按色相跑到 *-info-active 之类状态色。
        text_best: tuple[float, str] | None = None
        for name, raw in resolved.items():
            if CONTEXT_ONLY_RE.search(name) or EXCLUDE_TOKEN_RE.search(name):
                continue
            if role_of_token(name) != "text":
                continue
            low = name.lower()
            if name not in TEXT_PREF:
                continue
            if not raw.startswith("#"):
                continue
            cand_l = lightness_of(raw)
            if cand_l is None:
                continue
            delta = abs(cand_l - src_l)
            if delta <= 0.15 and (text_best is None or delta < text_best[0]):
                text_best = (delta, name)
        if text_best:
            return text_best[1]
    best: tuple[float, str] | None = None
    for name, raw in resolved.items():
        if CONTEXT_ONLY_RE.search(name) or EXCLUDE_TOKEN_RE.search(name):
            continue
        role = role_of_token(name)
        if name in STATUS_TOKENS and (allowed & {"text", "bg", "border"}):
            role = sorted(allowed & {"text", "bg", "border"})[0]
        if role not in allowed or role not in ("text", "bg", "border"):
            continue
        low = name.lower()
        if not any(k in low for k in keys):
            continue
        # 语义护栏：文字不得落到 *-bg-* / *-surface；背景不得落到 text/ink；
        # 且明度差必须有限，避免「深底→浅底」这种反向映射。
        if role == "text" and name not in TEXT_PREF:
            continue
        if role == "bg" and name not in BG_PREF:
            continue
        if role == "border" and name not in BORDER_PREF:
            continue
        if role == "text" and any(x in low for x in ("-bg-", "-surface", "-canvas", "-panel")):
            continue
        if role == "bg" and any(x in low for x in ("text", "ink", "-fg", "foreground")):
            continue
        cand_l = lightness_of(raw) if raw.startswith("#") else None
        if cand_l is None or abs(cand_l - src_l) > 0.22:
            continue
        cand = raw if raw.startswith("#") else None
        if not cand:
            continue
        ch = hue_of(cand)
        if ch is None or hue_family(ch) != fam:
            continue
        dist = rgb_distance(value_hex, cand)
        if best is None or dist < best[0]:
            best = (dist, name)
    return best[1] if best else None


NEUTRAL_MAX_SAT = 0.18
NEUTRAL_MAX_DIST = 22


def pick_neutral_token(value_hex: str, prop: str, resolved: dict[str, str]) -> str | None:
    allowed = PROP_ROLES.get(prop)
    if not allowed or saturation(value_hex) > NEUTRAL_MAX_SAT:
        return None
    best: tuple[float, str] | None = None
    for name, raw in resolved.items():
        if CONTEXT_ONLY_RE.search(name) or EXCLUDE_TOKEN_RE.search(name):
            continue
        role = role_of_token(name)
        if name in STATUS_TOKENS and (allowed & {"text", "bg", "border"}):
            role = sorted(allowed & {"text", "bg", "border"})[0]
        if role not in allowed or role not in ("text", "bg", "border"):
            continue
        if role == "text" and name not in TEXT_PREF:
            continue
        if role == "bg" and name not in BG_PREF:
            continue
        if role == "border" and name not in BORDER_PREF:
            continue
        cand_hex = raw if raw.startswith("#") else rgb_to_hex(re.sub(r"^rgba?\(([^)]*)\)$", r"\1", raw)) if raw.startswith("rgb") else None
        if not cand_hex or saturation(cand_hex) > NEUTRAL_MAX_SAT:
            continue
        dist = rgb_distance(value_hex, cand_hex)
        if dist > NEUTRAL_MAX_DIST:
            continue
        rank = token_rank(name, role)
        if best is None or (dist, rank) < (best[0], token_rank(best[1], role)):
            best = (dist, name)
    return best[1] if best else None


def pick_token_for_color(value_hex: str, prop: str, colors: dict[str, list[str]],
                         resolved: dict[str, str] | None = None) -> str | None:
    allowed = PROP_ROLES.get(prop)
    if allowed is None:
        return None
    candidates = colors.get(value_hex, [])
    preferred = migrate.COLOR_OVERRIDES.get(value_hex)
    if preferred in candidates and role_of_token(preferred) in allowed:
        return preferred
    candidates = [c for c in candidates if not CONTEXT_ONLY_RE.search(c)]
    scored = [(0, *token_rank(c, role_of_token(c))) for c in candidates if role_of_token(c) in allowed]
    if scored:
        scored.sort()
        return scored[0][3]
    if resolved is not None:
        neutral = pick_neutral_token(value_hex, prop, resolved)
        if neutral:
            return neutral
        return pick_hue_token(value_hex, prop, resolved)
    return None


def pick_token_for_size(value: str, prop: str, sizes: dict[str, list[str]]) -> tuple[str | None, str]:
    allowed = PROP_ROLES.get(prop)
    if not allowed or not (allowed & SIZE_FAMILY):
        return None, "property-not-tokenized"
    if prop in ("box-shadow", "text-shadow"):
        return None, "shadow-composite"  # composite shadow needs a shadow token, not a length
    if allowed == {"control-height"}:
        pxv = px_of(value.lower())
        if pxv is None or pxv > 64:
            return None, "layout-height"  # 大高度是布局尺寸，不是控件高
    value = value.lower()
    # pill radius: any very large radius means "full"
    if allowed == {"radius"}:
        pxv = px_of(value)
        if pxv is not None and pxv >= 100:
            full = [n for n in sizes.get("999px", []) + sizes.get("9999px", [])
                    if "full" in n.lower()]
            full += [n for names in sizes.values() for n in names
                     if role_of_token(n) == "radius" and n.lower().endswith("-full")]
            if full:
                return sorted(set(full), key=lambda c: (len(c), c))[0], "pill-radius"
    preferred = migrate.SIZE_OVERRIDES.get(value)
    if preferred and role_of_token(preferred) in allowed:
        return preferred, "exact-override"
    candidates = [c for c in sizes.get(value, []) if role_of_token(c) in allowed]
    if candidates:
        candidates.sort(key=lambda c: token_rank(c, role_of_token(c)))
        return candidates[0], "exact"
    px = px_of(value)
    if px is None or px == 0:
        return None, "not-size"
    # 「取最近主刻度」(theme-storefront-token-consumption.md §映射原则 1)：
    # 字号 1px、间距/圆角/描边 2px 内允许吸附到最近刻度；偏差记录在 review，便于人工复核。
    if "font-size" in allowed or "line-height" in allowed:
        tol = 2.0
    elif "control-height" in allowed:
        tol = 4.0
    else:
        tol = 3.0
    best: tuple[float, str] | None = None
    for cand_value, names in sizes.items():
        cpx = px_of(cand_value)
        if cpx is None:
            continue
        for name in names:
            if role_of_token(name) not in allowed:
                continue
            delta = abs(cpx - px)
            if delta <= tol and (best is None or delta < best[0]):
                best = (delta, name)
    if best:
        return best[1], f"nearest-{best[0]:g}px"
    return None, "no-near-token"


def transform(body: str, colors, sizes, stats: Counter, review: list[dict], src: str, resolved=None) -> str:
    body = audit.RE_DATA_URL.sub("url()", body)
    # 1) strip literal fallbacks in var()
    def strip_fallback(m: re.Match) -> str:
        token, fallback = m.group(1), m.group(2)
        # Never strip inverse paper pins / chrome body text pins
        # (theme-css-variables-only.md §8: Token 化扫除不得剥掉这些钉).
        if re.search(r"--[A-Za-z0-9_-]*(?:chrome-body-text|amz-drawer|paper|_paper)", token, re.I):
            return m.group(0)
        if re.fullmatch(r"#(?:0f1111|565959|666666)", fallback.strip(), re.I):
            return m.group(0)
        stats["var_fallback_stripped"] += 1
        return f"var({token})"

    body, n = audit.RE_VAR_FALLBACK.subn(lambda m: strip_fallback(m), body)
    # 2) colors
    def repl_hex(m: re.Match) -> str:
        prop = decl_property(body, m.start())
        token = pick_token_for_color(migrate.norm_hex(m.group()), prop, colors, resolved)
        if not token:
            review.append({"src": src, "kind": "color", "value": m.group(), "prop": prop})
            return m.group()
        stats["color_mapped"] += 1
        if token not in colors.get(migrate.norm_hex(m.group()), []):
            review.append({"src": src, "kind": "color-absorbed", "value": m.group(),
                           "prop": prop, "token": token})
        return f"var({token})"

    body = audit.RE_HEX.sub(repl_hex, body)

    def repl_rgb(m: re.Match) -> str:
        prop = decl_property(body, m.start())
        parts = [p for p in m.group(1).split(",")]
        if len(parts) >= 4:
            try:
                if float(parts[3].strip()) < 1.0:
                    review.append({"src": src, "kind": "color-alpha", "value": "rgba(" + m.group(1) + ")", "prop": prop})
                    return m.group()
            except ValueError:
                return m.group()
        hexed = rgb_to_hex(m.group(1))
        if not hexed:
            return m.group()
        token = pick_token_for_color(hexed, prop, colors, resolved)
        if not token:
            review.append({"src": src, "kind": "color", "value": "rgba(" + m.group(1) + ")", "prop": prop})
            return m.group()
        stats["color_mapped"] += 1
        return f"var({token})"

    body = RE_RGB_VALUE.sub(repl_rgb, body)
    # 3) composite shadows -> theme shadow tokens (focus ring keeps its own token)
    def fix_shadow(m: re.Match) -> str:
        prefix, value = m.group(1) or "", m.group(2)
        if "var(--weline-shadow" in value or "var(--focus-ring" in value:
            return m.group(0)
        low = value.lower()
        if re.match(r"\s*0\s+0\s+0\b", low):
            stats["shadow_mapped"] += 1
            return f"{prefix}box-shadow: var(--focus-ring);"
        if not re.search(r"\d(?:px|rem|em)", low):
            return m.group(0)
        layers = low.count(",") + 1
        token = "--weline-shadow-lg" if layers > 1 else "--weline-shadow-md"
        stats["shadow_mapped"] += 1
        return f"{prefix}box-shadow: var({token});"

    body = RE_BOX_SHADOW.sub(fix_shadow, body)
    # 4) sizes
    def repl_size(m: re.Match) -> str:
        prop = decl_property(body, m.start())
        token, why = pick_token_for_size(m.group(), prop, sizes)
        if not token:
            if why != "property-not-tokenized":
                review.append({"src": src, "kind": "size", "value": m.group(), "prop": prop})
            return m.group()
        if why.startswith("nearest-"):
            review.append({"src": src, "kind": "size-absorbed", "value": m.group(),
                           "prop": prop, "token": token, "delta": why})
        stats["size_mapped"] += 1
        return f"var({token})"

    body = audit.RE_SIZE.sub(repl_size, body)
    return body


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--module", default=None)
    ap.add_argument("--all", action="store_true")
    ap.add_argument("--apply", action="store_true")
    ap.add_argument("--include-theme-widgets", action="store_true",
                    help="also migrate Theme-owned widget CSS (existing guard debt)")
    ap.add_argument("--report", default=str(HERE.parent / "test/fixtures/module-frontend-migrate-report.json"))
    args = ap.parse_args()
    if not args.module and not args.all:
        ap.error("pass --module Weline_Xxx or --all")

    colors, sizes, resolved = build_index()
    stats: Counter = Counter()
    review: list[dict] = []
    changed_files: list[dict] = []

    work: list[tuple[Path, str]] = list(audit.iter_scope_files(True, args.module))
    if args.include_theme_widgets:
        widget_dir = THEME_ROOT / "view/statics/css/widgets"
        if widget_dir.is_dir():
            work += [(p, "Weline/Theme") for p in sorted(widget_dir.glob("*.css"))]

    for path, mod in work:
        suffix = path.suffix.lower()
        raw = path.read_text(encoding="utf-8", errors="replace")
        rel = str(path.relative_to(audit.ROOT))
        out = raw
        file_stats: Counter = Counter()
        if suffix in audit.CSS_EXT:
            out = transform(raw, colors, sizes, file_stats, review, rel, resolved)
        elif suffix in audit.MARKUP_EXT:
            pieces = []
            last = 0
            for m in audit.RE_STYLE_BLOCK.finditer(raw):
                pieces.append(raw[last:m.start()])
                css = transform(m.group(1), colors, sizes, file_stats, review, rel + "#<style>", resolved)
                pieces.append(m.group(0)[:m.start(1) - m.start(0)] + css + "</style>")
                last = m.end()
            pieces.append(raw[last:])
            out = "".join(pieces)
        else:
            continue
        if out != raw:
            stats.update(file_stats)
            changed_files.append({"path": rel, "stats": dict(file_stats)})
            if args.apply:
                path.write_text(out, encoding="utf-8")

    report = {
        "schema": "module-frontend-token-migrate.v1",
        "applied": bool(args.apply),
        "module": args.module or "ALL",
        "changed_files": len(changed_files),
        "stats": dict(stats),
        "review": review[:2000],
        "review_total": len(review),
        "review_by_kind": dict(Counter(r["kind"] for r in review)),
        "review_by_prop": dict(Counter(f'{r["kind"]}:{r["prop"]}' for r in review).most_common(30)),
        "review_by_value": dict(Counter(f'{r["kind"]}:{r["value"]}' for r in review).most_common(40)),
        "files": changed_files,
        "theme_token_count": len(resolved),
    }
    Path(args.report).write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"applied={args.apply} files={len(changed_files)} stats={dict(stats)}")
    print(f"review={len(review)} by_kind={report['review_by_kind']}")
    print(f"report: {args.report}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
