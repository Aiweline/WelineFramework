#!/usr/bin/env python3
"""Module frontend surfaces vs the default-theme consumption contract (read-only).

Contract: 模块前端页面修改标准（默认主题消费合同）
  - visual literals must be theme tokens (colors / spacing / font-size / radius / shadow / control-height)
  - no inline styles, no inline executable <script>, no on*=, no direct <script src> for module JS
  - module JS registers in view/statics/frontend/weline.modules.js (Weline.declare / data-weline-load)
  - width: .w-container or min(100%, var(--weline-layout-content-max-width))
  - i18n via <lang>/@lang, not __()
  - no module-private palette/token definitions, no !important

Scope (S1/S2): app/code/{Vendor}/{Module}/view/{templates,theme,statics}/** frontend surfaces.
Excluded: backend area (S3), prototypes (S4), vendor app shells (S5), tests/generated/doc/vendor,
          and Weline_Theme itself (the provider is guarded by Theme's own suites).

Allow-listed exceptions (per Theme docs, not "debt"):
  - inverse paper ink pins: #0f1111 / #565959 / #666666 bound to --weline-chrome-body-text* / --amz-drawer-text / *paper-text
  - @media breakpoint literals
  - !important on [hidden] { display: none } and inside @media (prefers-reduced-motion: reduce)

Usage:
  python3 module-frontend-theme-audit.py                 # human summary
  python3 module-frontend-theme-audit.py --json          # full JSON
  python3 module-frontend-theme-audit.py --module Weline_Compare
  python3 module-frontend-theme-audit.py --baseline app/code/Weline/Theme/test/fixtures/module-frontend-theme-baseline.json
  python3 module-frontend-theme-audit.py --write-baseline <path>
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[5]  # .../框架
CODE = ROOT / "app" / "code"
# Theme providers, not consumers: the default theme + design-theme packs.
THEME_PROVIDERS = ("Weline/Theme", "Weline/ThemeFancy", "Weline/BackendThemeUpzet")

EXCLUDE_SUBSTR = (
    "/backend/", "/Backend/", "/admin/", "/Admin/", "/BackendThemeUpzet/",
    "/view/tpl/", "/generated/", "/Test/", "/test/", "/tests/", "/e2e/", "/doc/",
    "/node_modules/", "/vendor/", "/libs/", "/tinymce/", "/bootstrap",
    "/theme-editor/", "/visual-editor/", "/editor-mode/", "/widget-config/",
    "/prototype/", "/prototypes/", "/DeveloperWorkspace/", "/account-login",
    "/account-recovery/", "/backend-theme/", "/backend-menu/", "/datatable-form/",
)
EXCLUDE_BASENAME_PREFIX = ("prototype", "prototype-")
# D1: 后台管理 UI 不属前台合同范围。部分后台样式没有 /backend/ 路径段，
# 但文件名明确是 admin/backend/console 面板 —— 按名排除。
EXCLUDE_BASENAME_CONTAINS = ("admin", "backend", "backend-", "-console", "wls-")
EXCLUDE_BASENAME = (
    "elfinder.html", "elfinder.legacy.html", "index.html",  # S5 vendor app shells
    "test.phtml", "test.html",  # dev/test pages
    "mail-html-preview-accept.html",  # 邮件预览样例内容（非页面）
)

RE_HEX = re.compile(r"#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\b")
RE_RGB = re.compile(r"\b(?:rgb|rgba|hsl|hsla)\s*\(", re.I)
# em 为相对字号单位（随字体自适应），不属于绝对设计字面量；text-shadow 的模糊半径无对应刻度。
RE_SIZE = re.compile(r"(?<![\w.-])(\d+(?:\.\d+)?)(px|rem)\b")
RE_VAR_FALLBACK = re.compile(
    r"var\(\s*(--[A-Za-z0-9_-]+)\s*,\s*((?:#[0-9a-fA-F]{3,8}|rgba?\([^)]*\)|hsla?\([^)]*\)|"
    r"transparent|currentColor|white|black|red|blue|green|gray|grey|"
    r"\d+(?:\.\d+)?(?:px|rem|em)))\s*\)",
    re.I,
)
RE_VAR_FALLBACK_COLOR = re.compile(
    r"var\(\s*--[^,)]+\s*,\s*(?:#[0-9a-fA-F]{3,8}|rgba?\([^)]*\)|hsla?\([^)]*\)|"
    r"white|black|red|transparent)\s*\)", re.I)
RE_VAR_FALLBACK_SIZE = re.compile(
    r"var\(\s*--[^,)]+\s*,\s*\d+(?:\.\d+)?(?:px|rem|em)\s*\)", re.I)
RE_IMPORTANT = re.compile(r"!\s*important", re.I)
RE_MAXWIDTH_PX = re.compile(r"(?:max-)?(?:inline-)?(?:width|size)\s*:\s*(?:min\(\s*100%\s*,\s*)?(\d{3,4})px", re.I)
RE_PRIVATE_TOKEN = re.compile(r"(--[a-z0-9-]+)\s*:\s*(#[0-9a-fA-F]{3,8}|rgba?\([^)]*\))", re.I)
RE_INLINE_STYLE = re.compile(r"""(?<![\w-])style\s*=\s*(["'])(?:(?!\1).)*\1""", re.I | re.S)
RE_INLINE_STYLE_VALUE = re.compile(r"""(?<![\w-])style\s*=\s*(["'])((?:(?!\1).)*)\1""", re.I | re.S)
# Theme foundation 自带的组件参数通道：style="--w-gap:var(--weline-space-2);--w-mb:0"
# 见 Theme/view/ui/css/foundation.css 的 [style*="--w-mb:"] { ... } 规则。
# 只放行「纯 --w-* 参数（值必须是 var() 引用 / 整数 / 0）」，出现任何视觉字面量仍算违规。
RE_W_PARAM_ONLY = re.compile(r"^\s*--w-[a-z0-9-]+\s*:\s*(?:var\([^)]*\)|\d+(?:\.\d+)?|0)\s*$", re.I)


RE_ANY_PARAM_DECL = re.compile(
    r"^\s*--[a-zA-Z0-9-]+\s*:\s*(?:var\(.+?\)|\d+(?:\.\d+)?%?|0|\s*<\?.*?\?>\s*%?|\s)+$", re.I | re.S)


def _is_w_param_style(value: str) -> bool:
    """整条 style 都是自定义属性参数（Theme 组件参数通道 / 动态数据通道），不含视觉字面量。"""
    parts = [p for p in value.split(";") if p.strip()]
    if not parts:
        return False
    if all(RE_W_PARAM_ONLY.match(p) for p in parts):
        return True
    # 允许任意 --custom 参数：值必须是 var(...) / 数字 / 0 / PHP 动态输出
    return all(RE_ANY_PARAM_DECL.match(p) for p in parts)
RE_STYLE_BLOCK = re.compile(r"<style\b[^>]*>(.*?)</style>", re.I | re.S)
RE_SCRIPT_BLOCK = re.compile(r"<script\b([^>]*)>(.*?)</script\s*>", re.I | re.S)
RE_ON_ATTR = re.compile(
    r"""\s(?:onclick|onchange|oninput|onsubmit|onload|onerror|onkeyup|onkeydown|onblur|onfocus|"""
    r"""onmouseover|onmouseout|onscroll|onselect|onreset|ontoggle)\s*=\s*(["'])""", re.I)
# 硬规则 template_lang_not_php_i18n：前台 .phtml「HTML 内禁止 <?= __() ?>」。
# `<?php ?>` 块内对动态数据做 __($key) 属数据侧翻译，不在本规则范围。
RE_PHP_I18N = re.compile(r"(?<![\w>$])__\(\s*['\"]")
RE_PHP_I18N_ANY = re.compile(r"(?<![\w>$])__\(")
RE_ECHO_SEG = re.compile(r"<\?=\s*(.*?)\?>", re.S)
RE_RAW_SHELL = re.compile(
    r"""class\s*=\s*(["'])(?:(?!\1).)*?(?<![\w-])(?:container|container-fluid|breadcrumb|site-header|site-footer)(?![\w-])""",
    re.I | re.S)
# bare bootstrap-style page container in CSS is a self-built shell regardless of width
RE_BARE_CONTAINER_SEL = re.compile(r"(?:^|[\s,>+~])\.container(?:-fluid)?\s*$", re.I)
RE_FETCH = re.compile(r"\bfetch\s*\(\s*[`'\"]", re.I)
RE_XHR = re.compile(r"new\s+XMLHttpRequest|\.ajax\s*\(\s*\$?\(", re.I)
RE_OBSERVE = re.compile(
    r"new\s+MutationObserver\s*\([^)]*\)[\s\S]{0,400}?\.observe\s*\(\s*"
    r"(?:document(?:\.documentElement|\.body)?)\b", re.I)
RE_WELINE_USE = re.compile(r"Weline\s*\.\s*(?:declare|load|dom|Api|mount|use)\b")
RE_DATA_WELINE = re.compile(r"data-weline-(?:load|declare|mount)\s*=", re.I)
# Token-domain properties only (contract: 颜色/间距/字号/圆角/阴影/控件高).
# Layout dimensions (width/height/top/left/transform/...) are NOT token-scoped.
SIZE_TOKEN_PROPS = {
    "margin", "margin-top", "margin-right", "margin-bottom", "margin-left",
    "margin-inline", "margin-inline-start", "margin-inline-end",
    "margin-block", "margin-block-start", "margin-block-end",
    "padding", "padding-top", "padding-right", "padding-bottom", "padding-left",
    "padding-inline", "padding-inline-start", "padding-inline-end",
    "padding-block", "padding-block-start", "padding-block-end",
    "gap", "row-gap", "column-gap", "grid-gap",
    "font-size", "line-height", "letter-spacing", "word-spacing",
    "border-radius", "border-top-left-radius", "border-top-right-radius",
    "border-bottom-left-radius", "border-bottom-right-radius",
    "border-width", "border-top-width", "border-right-width",
    "border-bottom-width", "border-left-width", "outline-width",
    "box-shadow", "min-height", "font",
}
RE_PROP_FOR_SIZE = re.compile(r"\s*([-a-zA-Z][-a-zA-Z0-9]*)\s*:")

RE_BAKE_CHANNEL = re.compile(r"data-weline-widget-asset|data-weline-source-position", re.I)
RE_COMMENT_CSS = re.compile(r"/\*.*?\*/", re.S)
RE_COMMENT_HTML = re.compile(r"<!--.*?-->", re.S)
RE_DATA_URL = re.compile(r"url\(\s*['\"]?data:[^)]+\)", re.I)
RE_MEDIA = re.compile(r"@media[^{]+\{", re.I)
RE_REDUCED_MOTION = re.compile(r"@media[^{]*prefers-reduced-motion[^{]*\{", re.I)
RE_ZERO = re.compile(r"(?<![\w-])0(?:px|rem|em)\b")
RE_PAPER_PIN = re.compile(
    r"--[A-Za-z0-9_-]*(?:chrome-body-text|amz-drawer|paper-text|paper-|_paper)", re.I)

CSS_EXT = {".css", ".less", ".scss"}
MARKUP_EXT = {".phtml", ".html", ".htm"}
JS_EXT = {".js"}

PAPER_PINS = {"#0f1111", "#565959", "#666666"}

SEVERITY = {
    "layout_key_collision": "P0", "private_theme_json": "P0",
    "inline_script_exec": "P0", "inline_handler": "P0", "direct_script_src": "P0",
    "php_i18n_call": "P1", "raw_page_shell": "P1",
    "hard_color_hex": "P1", "hard_color_func": "P1", "important": "P1",
    "var_fallback_color": "P1", "var_fallback_size": "P1",
    "private_token_def": "P1", "hard_max_width_px": "P1",
    "inline_style": "P2", "hard_size": "P2",
    "js_fetch_direct": "P1", "js_observe_direct": "P2",
}


def module_of(path: Path) -> str:
    parts = path.relative_to(CODE).parts
    return "/".join(parts[:2]) if len(parts) >= 2 else "/".join(parts)


def in_scope(p: Path) -> bool:
    s = "/" + str(p.relative_to(CODE)).replace("\\", "/")
    if any(s.startswith("/" + tp + "/") for tp in THEME_PROVIDERS):
        return False
    if not any(k in s for k in ("/view/templates/", "/view/theme/", "/view/statics/")):
        return False
    if any(k in s for k in EXCLUDE_SUBSTR):
        return False
    if p.name.startswith(EXCLUDE_BASENAME_PREFIX) or p.name in EXCLUDE_BASENAME:
        return False
    name_low = p.name.lower()
    if any(k in name_low for k in EXCLUDE_BASENAME_CONTAINS):
        return False
    if p.name.endswith((".min.css", ".min.js")):
        return False
    return p.suffix.lower() in CSS_EXT | MARKUP_EXT | JS_EXT


def strip_noise(text: str, html: bool) -> str:
    text = RE_DATA_URL.sub("url()", text)
    text = RE_COMMENT_CSS.sub("", text)
    if html:
        text = RE_COMMENT_HTML.sub("", text)
    return text


def _block_ranges(text: str, opener: re.Pattern) -> list[tuple[int, int]]:
    """Brace-matched ranges for blocks whose header matches `opener`."""
    out: list[tuple[int, int]] = []
    for m in opener.finditer(text):
        depth, i, start = 0, m.end() - 1, m.end() - 1
        while i < len(text):
            if text[i] == "{":
                depth += 1
            elif text[i] == "}":
                depth -= 1
                if depth == 0:
                    out.append((start, i + 1))
                    break
            i += 1
    return out


def _decl_before(text: str, pos: int) -> tuple[str, str]:
    """Return (selector, declaration-so-far) for the position."""
    decl_start = max(text.rfind(";", 0, pos), text.rfind("{", 0, pos))
    decl = text[decl_start + 1:pos]
    sel_start = text.rfind("}", 0, max(decl_start, 1))
    selector = text[sel_start + 1:decl_start] if sel_start >= 0 else text[:decl_start]
    return selector, decl


PAGE_SHELL_SELECTOR = re.compile(
    r"(?:^|[\s,>+~])(?:[a-z-]*)?(?:page|container|shell|layout|main|wrapper)[-_a-z0-9]*\s*$", re.I)


def _px_value(value: str) -> float | None:
    m = re.fullmatch(r"(\d+(?:\.\d+)?)(px|rem|em)", value.strip().lower())
    if not m:
        return None
    n = float(m.group(1))
    return n * 16 if m.group(2) in ("rem", "em") else n


def _selector_before(text: str, pos: int) -> str:
    decl_start = max(text.rfind(";", 0, pos), text.rfind("{", 0, pos), text.rfind("}", 0, pos))
    sel_end = text.rfind("{", 0, decl_start + 1)
    if sel_end < 0:
        return ""
    sel_start = text.rfind("}", 0, sel_end)
    return text[sel_start + 1:sel_end].strip()


def _decl_prop(text: str, pos: int) -> str:
    start = max(text.rfind(";", 0, pos), text.rfind("{", 0, pos), text.rfind("}", 0, pos))
    m = RE_PROP_FOR_SIZE.match(text[start + 1:pos])
    return m.group(1).lower() if m else ""


# 合同允许：颜色/特质 Hero 可在模块内 scope 自定义（theme-css-variables-only.md §9 关联约定）。
HERO_SELECTOR = re.compile(
    r"(?:hero|banner|promo|masthead|jumbotron|glow|gradient|decor|visual|showcase|landing|"
    r"dark-panel|stage|backdrop|orb|spotlight)", re.I)
# 展示级排版（Hero 标题/大标题）属模块 scope 例外，不强制套主刻度字号。
DISPLAY_SELECTOR = re.compile(
    r"(?:hero|banner|display|headline|masthead|jumbotron|title|heading|"
    r"\bh[1-3]\b|stat|count|number|timer|countdown|amount|score)", re.I)


def _hero_scope_decl(text: str, pos: int) -> bool:
    """Hero / 展示 / 暗色专区的模块 scope 颜色（用户裁决：允许模块内自定义）。"""
    start = max(text.rfind(";", 0, pos), text.rfind("{", 0, pos), text.rfind("}", 0, pos))
    decl = text[start + 1:pos]
    sel_start = text.rfind("{", 0, max(start, 1))
    if sel_start < 0:
        return False
    prev_end = text.rfind("}", 0, sel_start)
    selector = text[prev_end + 1:sel_start]
    return HERO_SELECTOR.search(selector) is not None


def scan_css(text: str, hits: list, src: str) -> None:
    cleaned = strip_noise(text, False)
    media = [(m.start(), m.end()) for m in RE_MEDIA.finditer(cleaned)]
    reduced = _block_ranges(cleaned, RE_REDUCED_MOTION)

    def in_media(i: int) -> bool:
        return any(a <= i < b for a, b in media)

    def in_reduced(i: int) -> bool:
        return any(a <= i < b for a, b in reduced)

    for m in RE_HEX.finditer(cleaned):
        if _hero_scope_decl(cleaned, m.start()):
            continue
        if m.group().lower() in PAPER_PINS:
            selector, decl = _decl_before(cleaned, m.start())
            if RE_PAPER_PIN.search(decl) or RE_PAPER_PIN.search(selector):
                continue
        hits.append((src, "hard_color_hex", m.group()))
    for m in RE_RGB.finditer(cleaned):
        if _hero_scope_decl(cleaned, m.start()):
            continue
        snippet = cleaned[m.start():m.start() + 60]
        if "var(--" in snippet.split(")")[0]:
            continue  # alpha 为动态参数（rgba(..., var(--alpha))），非字面量
        hits.append((src, "hard_color_func", snippet[:40]))
    for m in RE_IMPORTANT.finditer(cleaned):
        if in_reduced(m.start()):
            continue
        selector, decl = _decl_before(cleaned, m.start())
        if re.search(r"\[\s*hidden\s*\]", selector) and re.search(r"display\s*:\s*none", decl, re.I):
            continue
        hits.append((src, "important", cleaned[max(0, m.start() - 40):m.start() + 12]))
    for m in RE_VAR_FALLBACK_COLOR.finditer(cleaned):
        hits.append((src, "var_fallback_color", m.group()[:80]))
    for m in RE_VAR_FALLBACK_SIZE.finditer(cleaned):
        hits.append((src, "var_fallback_size", m.group()[:80]))
    for m in RE_PRIVATE_TOKEN.finditer(cleaned):
        selector, decl = _decl_before(cleaned, m.start())
        if RE_PAPER_PIN.search(decl):
            continue
        hits.append((src, "private_token_def", m.group()[:80]))
    for m in RE_MAXWIDTH_PX.finditer(cleaned):
        value = int(m.group(1))
        if m.group(1) in {"100", "0"}:
            continue
        if in_media(m.start()):
            continue  # @media breakpoint literals are allowed (theme-css-variables-only.md §7)
        # Only page content shells are contract-scoped (theme-layout-content-width.md):
        #  - selector looks like a page shell AND width >= 900px, or
        #  - explicit page formula width: min(100%, Npx) with N >= 900px.
        selector = _selector_before(cleaned, m.start())
        is_shell_formula = re.match(r"width\s*:\s*min\(\s*100%\s*,", m.group(0).strip(), re.I) is not None
        if not (is_shell_formula and value >= 900) and not (
                value >= 900 and PAGE_SHELL_SELECTOR.search(selector)) and not (
                RE_BARE_CONTAINER_SEL.search(selector)):
            continue
        line = cleaned.count("\n", 0, m.start()) + 1
        hits.append((src, "hard_max_width_px", f"L{line}:{m.group()}"))
    for m in RE_SIZE.finditer(cleaned):
        if RE_ZERO.fullmatch(m.group()) or in_media(m.start()):
            continue
        prop = _decl_prop(cleaned, m.start())
        if prop not in SIZE_TOKEN_PROPS:
            continue
        # min-height >64px 是布局高度，不是控件高（控件高走 --weline-theme-control-height*）
        if prop in ("min-height", "height"):
            px = _px_value(m.group())
            if px is not None and px >= 64:
                continue
        # Hero/展示级字号属模块 scope 例外（含 ≥40px 的展示字体）
        if prop == "font-size":
            if DISPLAY_SELECTOR.search(_selector_before(cleaned, m.start())):
                continue
            px_font = _px_value(m.group())
            if px_font is not None and px_font >= 40:
                continue
        hits.append((src, "hard_size", m.group()))



RE_PHP_WRAPPER_CALL = re.compile(r"^\$[A-Za-z_][A-Za-z0-9_]*\s*\(", re.S)
RE_CAST = re.compile(r"^\(\s*(?:string|int|float|bool)\s*\)\s*", re.S)


def _strip_wrappers(code: str) -> str:
    """剥掉 htmlspecialchars(...)/$var(...)/(string) 等外壳与 ENT_* 参数，得到核心表达式。"""
    code = code.strip()
    for _ in range(6):
        before = code
        code = RE_CAST.sub("", code)
        m = re.match(r"^(?:htmlspecialchars|htmlentities)\s*\((.*)\)\s*$", code, re.S)
        if m:
            code = m.group(1).strip()
            code = re.sub(r",\s*ENT_[A-Z_]+(?:\s*,\s*'[^']*')?\s*$", "", code).strip()
        elif RE_PHP_WRAPPER_CALL.match(code) and code.endswith(")"):
            # $var( ... ) 单层包裹
            depth = 0
            for i, ch in enumerate(code):
                if ch == "(":
                    depth += 1
                elif ch == ")":
                    depth -= 1
                    if depth == 0:
                        if i == len(code) - 1:
                            code = code[code.index("(") + 1:-1].strip()
                        break
        if code == before:
            break
    return code


def _is_tag_convertible_i18n(code: str) -> bool:
    core = _strip_wrappers(code)
    return re.match(r"^__\s*\(\s*['\"]", core) is not None and core.rstrip().endswith(")")

def scan_markup(text: str, hits: list, src: str) -> None:
    cleaned = strip_noise(text, True)
    for m in RE_STYLE_BLOCK.finditer(cleaned):
        scan_css(m.group(1), hits, src + "#<style>")
    for m in RE_INLINE_STYLE_VALUE.finditer(cleaned):
        if _is_w_param_style(m.group(2)):
            continue
        hits.append((src, "inline_style", m.group()[:100]))
    for m in RE_SCRIPT_BLOCK.finditer(cleaned):
        attrs, body = m.group(1), m.group(2)
        # PHP 注释里出现的 "<script src>" 文案不是真实标签（无 = 号）
        if "=" not in attrs and re.search(r"\bsrc\b", attrs, re.I):
            continue
        if re.search(r"type\s*=\s*['\"](?:application/(?:ld\+)?json|text/template|text/x-template)", attrs, re.I):
            continue
        if re.search(r"\bsrc\s*=", attrs, re.I):
            if RE_BAKE_CHANNEL.search(attrs):
                continue  # widget bake channel (layout-source/source) is allowed
            hits.append((src, "direct_script_src", attrs.strip()[:100]))
            continue
        if body.strip():
            hits.append((src, "inline_script_exec", body.strip()[:100]))
    for m in RE_ON_ATTR.finditer(cleaned):
        line = cleaned.count("\n", 0, m.start()) + 1
        hits.append((src, "inline_handler", f"L{line}:{m.group().strip()}"))
    for seg in RE_ECHO_SEG.finditer(cleaned):
        seg_code = seg.group(1)
        # 数据载荷例外：json_encode(...) 组装的文案字典经 data-* 传给 JS，不是模板可见文案
        data_payload = "json_encode(" in seg_code.replace(" ", "")
        if data_payload:
            continue
        # 只有「整个输出就是一个 __(字面量) 调用」才必须 Tag 化（<lang>/@lang）。
        # 三元 / ?? 兜底 / 作为函数参数参与运算的 __() 属 PHP 侧运行时翻译，规则允许。
        if not _is_tag_convertible_i18n(seg_code):
            continue
        for m in RE_PHP_I18N_ANY.finditer(seg_code):
            line = cleaned.count("\n", 0, seg.start(1) + m.start()) + 1
            hits.append((src, "php_i18n_call", f"L{line}"))
    for m in RE_RAW_SHELL.finditer(cleaned):
        hits.append((src, "raw_page_shell", m.group()[:80]))


# 追踪像素传输层自身就是「请求出口」，走 Weline.Api 会形成环；其 fetch/beacon 属合法例外。
ANALYTICS_TRANSPORT = ("Visitor/view/statics/js/pixel.js", "Visitor/view/statics/js/pixel-event-picker.js")

RE_ARCH_FALLBACK = re.compile(r"/\* ARCH_MO_FALLBACK_START \*/.*?/\* ARCH_MO_FALLBACK_END \*/", re.S)


def scan_js(text: str, hits: list, src: str) -> None:
    # 官方降级区（无 Weline.dom 总线时才可用）内的 MutationObserver 不算违规
    fallback_spans = [(m.start(), m.end()) for m in RE_ARCH_FALLBACK.finditer(text)]

    def in_fallback(pos: int) -> bool:
        return any(a <= pos < b for a, b in fallback_spans)

    if any(k in "/" + src.replace("\\", "/") for k in ANALYTICS_TRANSPORT):
        return
    for m in RE_FETCH.finditer(text):
        hits.append((src, "js_fetch_direct", text[m.start():m.start() + 60]))
    for m in RE_XHR.finditer(text):
        hits.append((src, "js_fetch_direct", m.group()[:60]))
    for m in RE_OBSERVE.finditer(text):
        if in_fallback(m.start()):
            continue
        hits.append((src, "js_observe_direct", m.group()[:60]))


def has_frontend_surface(module: str) -> bool:
    base = CODE / module
    return any((base / d).is_dir() for d in (
        "view/templates/frontend", "view/theme/frontend", "view/statics/frontend"))


def load_scope() -> dict:
    cfg = Path(__file__).with_name("module-frontend-theme-scope.json")
    if not cfg.is_file():
        return {"exclude_modules": [], "include_paths": {}}
    return json.loads(cfg.read_text(encoding="utf-8"))


def iter_scope_files(use_scope: bool = True, module_filter: str | None = None,
                     out_of_scope: Counter | None = None):
    """Yield (path, module) for files inside the contract scope."""
    scope = load_scope() if use_scope else {"exclude_modules": [], "include_paths": {}}
    excluded_modules = {m for m in scope.get("exclude_modules", [])}
    include_paths = scope.get("include_paths", {})
    for p in sorted(CODE.rglob("*")):
        if not p.is_file() or not in_scope(p):
            continue
        mod = module_of(p)
        if module_filter and mod.replace("/", "_") != module_filter and mod != module_filter:
            continue
        if mod in excluded_modules:
            if out_of_scope is not None:
                out_of_scope[mod] += 1
            continue
        rel_posix = "/" + str(p.relative_to(CODE)).replace("\\", "/")
        # S2b: modules that ship a real frontend area only consume frontend surfaces;
        # root-level Block/analytics/Widget dirs are backend widgets.
        if (CODE / mod / "view/templates/frontend").is_dir() and not (
                "/frontend/" in rel_posix or "/view/statics/" in rel_posix):
            if out_of_scope is not None:
                out_of_scope[mod] += 1
            continue
        allowed = include_paths.get(mod)
        if allowed is not None and not any(a in rel_posix or a in str(p) for a in allowed):
            if out_of_scope is not None:
                out_of_scope[mod] += 1
            continue
        yield p, mod


def collect(module_filter: str | None = None, use_scope: bool = True) -> dict:
    scope = load_scope() if use_scope else {"exclude_modules": [], "include_paths": {}}
    excluded_modules = {m for m in scope.get("exclude_modules", [])}
    include_paths = scope.get("include_paths", {})
    out_of_scope: Counter = Counter()
    theme_layouts = set()
    tl = CODE / "Weline/Theme" / "view/theme/frontend/layouts"
    if tl.is_dir():
        theme_layouts = {p.name for p in tl.iterdir() if p.is_dir()}

    per_module: dict[str, Counter] = defaultdict(Counter)
    per_file: list[dict] = []
    examples: dict[tuple[str, str], list[str]] = defaultdict(list)
    js_info: dict[str, dict] = defaultdict(dict)
    modules_seen: set[str] = set()
    scanned = 0

    for p, mod in iter_scope_files(use_scope, module_filter, out_of_scope):
        modules_seen.add(mod)
        scanned += 1
        rel = str(p.relative_to(ROOT))
        raw = p.read_text(encoding="utf-8", errors="replace")
        hits: list[tuple[str, str, str]] = []
        suffix = p.suffix.lower()
        if suffix in CSS_EXT:
            scan_css(raw, hits, rel)
        elif suffix in MARKUP_EXT:
            scan_markup(raw, hits, rel)
        else:
            scan_js(raw, hits, rel)
            js_info[mod]["js_files"] = js_info[mod].get("js_files", 0) + 1
        if suffix in MARKUP_EXT:
            if RE_WELINE_USE.search(raw) or RE_DATA_WELINE.search(raw):
                js_info[mod]["templates_using_loader"] = js_info[mod].get("templates_using_loader", 0) + 1
        if hits:
            codes = Counter(h[1] for h in hits)
            per_file.append({
                "path": rel, "module": mod, "hits": len(hits), "by_code": dict(codes),
            })
            for _src, code, match in hits:
                per_module[mod][code] += 1
                if len(examples[(mod, code)]) < 3:
                    examples[(mod, code)].append(f"{_src} :: {match}")

    for mod in list(modules_seen):
        reg = CODE / mod / "view/statics/frontend/weline.modules.js"
        js_info[mod]["registered"] = reg.is_file()
        lay = CODE / mod / "view/theme/frontend/layouts"
        if lay.is_dir():
            for d in sorted(lay.iterdir()):
                if d.is_dir() and d.name in theme_layouts:
                    per_module[mod]["layout_key_collision"] += 1
                    per_file.append({
                        "path": str(d.relative_to(ROOT)), "module": mod, "hits": 1,
                        "by_code": {"layout_key_collision": 1},
                    })
                    examples[(mod, "layout_key_collision")].append(
                        f"layout key '{d.name}' collides with Weline_Theme default layout")
        for cand in ("theme.json", "etc/theme.json"):
            if (CODE / mod / cand).is_file():
                per_module[mod]["private_theme_json"] += 1
                examples[(mod, "private_theme_json")].append(str((CODE / mod / cand).relative_to(ROOT)))

    rows = []
    for mod, codes in per_module.items():
        sev = Counter()
        for code, n in codes.items():
            sev[SEVERITY.get(code, "P2")] += n
        rows.append({
            "module": mod,
            "module_id": mod.replace("/", "_"),
            "frontend_surface": has_frontend_surface(mod),
            "violations": sum(codes.values()),
            "files": len([f for f in per_file if f["module"] == mod]),
            "P0": sev["P0"], "P1": sev["P1"], "P2": sev["P2"],
            "by_code": dict(sorted(codes.items(), key=lambda kv: -kv[1])),
            "examples": {c: examples[(mod, c)] for c in codes},
            "js": js_info.get(mod, {}),
        })
    rows.sort(key=lambda r: (-r["P0"], -r["P1"], -r["violations"]))

    by_code_global = Counter()
    for r in rows:
        for code, n in r["by_code"].items():
            by_code_global[code] += n

    return {
        "schema": "module-frontend-theme-audit.v1",
        "contract": "模块前端页面修改标准（默认主题消费合同）",
        "modules_scanned": len(modules_seen),
        "files_scanned": scanned,
        "total_violations": sum(r["violations"] for r in rows),
        "bad_files": len(per_file),
        "modules_with_violations": len(rows),
        "frontend_surface_modules": len([r for r in rows if r["frontend_surface"]]),
        "ok": sum(r["violations"] for r in rows) == 0,
        "scope": {
            "mode": "frontend_consumption" if use_scope else "all",
            "excluded_modules": sorted(excluded_modules),
            "out_of_scope_files": dict(out_of_scope),
        },
        "by_code": dict(by_code_global.most_common()),
        "by_severity": {
            "P0": sum(r["P0"] for r in rows),
            "P1": sum(r["P1"] for r in rows),
            "P2": sum(r["P2"] for r in rows),
        },
        "rows": rows,
    }


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--json", action="store_true")
    ap.add_argument("--module", default=None, help="e.g. Weline_Compare")
    ap.add_argument("--all", action="store_true", help="ignore scope config (audit every module)")
    ap.add_argument("--baseline", default=None)
    ap.add_argument("--write-baseline", default=None)
    ap.add_argument("--md", default=None, help="write markdown summary here")
    args = ap.parse_args()

    report = collect(args.module, use_scope=not args.all)

    if args.write_baseline:
        snapshot = {
            "schema": "module-frontend-theme-baseline.v1",
            "note": "棘轮基线：只许降不许升。每次迁移后由 --write-baseline 下调。",
            "total_violations": report["total_violations"],
            "by_code": report["by_code"],
            "by_severity": report["by_severity"],
            "by_module": {r["module"]: r["violations"] for r in report["rows"]},
            "by_module_code": {r["module"]: r["by_code"] for r in report["rows"]},
        }
        Path(args.write_baseline).write_text(
            json.dumps(snapshot, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(f"baseline written: {args.write_baseline} total={report['total_violations']}")

    exit_code = 0
    if args.baseline:
        base = json.loads(Path(args.baseline).read_text(encoding="utf-8"))
        problems = []
        for code, limit in base.get("by_code", {}).items():
            current = report["by_code"].get(code, 0)
            if current > limit:
                problems.append(f"{code}: {current} > baseline {limit}")
        for mod, limit in base.get("by_module", {}).items():
            current = next((r["violations"] for r in report["rows"] if r["module"] == mod), 0)
            if current > limit:
                problems.append(f"{mod}: {current} > baseline {limit}")
        for code, limit in base.get("by_severity", {}).items():
            current = report["by_severity"].get(code, 0)
            if current > limit:
                problems.append(f"severity {code}: {current} > baseline {limit}")
        if problems:
            print("RATCHET FAIL:\n  " + "\n  ".join(problems), file=sys.stderr)
            exit_code = 1
        report["ratchet"] = {"ok": not problems, "problems": problems}

    if args.md:
        lines = [
            f"# 模块前端主题合规扫描（{report['modules_with_violations']}/{report['modules_scanned']} 模块有命中）",
            "",
            f"- 命中总数：**{report['total_violations']}**（P0 {report['by_severity']['P0']} / "
            f"P1 {report['by_severity']['P1']} / P2 {report['by_severity']['P2']}）",
            f"- 涉及文件：{report['bad_files']}",
            "",
            "| 模块 | 命中 | P0 | P1 | P2 | 文件 | 主要类型 |",
            "|---|---:|---:|---:|---:|---:|---|",
        ]
        for r in report["rows"]:
            top = ", ".join(f"{k}×{v}" for k, v in list(r["by_code"].items())[:4])
            lines.append(
                f"| {r['module']} | {r['violations']} | {r['P0']} | {r['P1']} | {r['P2']} | "
                f"{r['files']} | {top} |")
        scope = report.get("scope", {})
        lines += [
            "",
            "## 范围（机器可读配置：`module-frontend-theme-scope.json`）",
            "",
            f"- 扫描模式：`{scope.get('mode')}`",
            f"- 排除模块：{len(scope.get('excluded_modules', []))} 个 —— "
            + "、".join(m.replace("Weline/", "") for m in scope.get("excluded_modules", [])),
            "- 排除依据：后台 area（合同禁止）/ prototype / 第三方 app 壳 / 框架提供方",
            "",
            "| 模块 | 命中 | 路径白名单 |",
            "|---|---:|---|",
        ]
        for mod, paths in sorted(load_scope().get("include_paths", {}).items()):
            hits = next((r["violations"] for r in report["rows"] if r["module"] == mod), 0)
            lines.append(f"| {mod} | {hits} | {'<br>'.join(paths)} |")
        lines += [
            "",
            "## 例外白名单（不计违规，依据 Theme 文档）",
            "",
            "| 例外 | 依据 |",
            "|---|---|",
            "| 纸面钉 `#0f1111` / `#565959` / `#666666` 绑 `--weline-chrome-body-text*` / `--amz-drawer-text` / `*paper-text` | theme-css-variables-only.md §8 |",
            "| `@media` 断点字面量 | theme-css-variables-only.md §7 |",
            "| `[hidden] { display: none !important }` | theme-css-variables-only.md 例外表 |",
            "| `@media (prefers-reduced-motion: reduce)` 内 `!important` | theme-css-variables-only.md 例外表 |",
            "| 部件 bake 通道 `<script ... data-weline-widget-asset / data-weline-source-position>` | 前端JS模块加载规范.md §1.5 |",
            "",
            "## 用法",
            "",
            "```bash",
            "python3 app/code/Weline/Theme/bin/module-frontend-theme-audit.py                 # 汇总",
            "python3 app/code/Weline/Theme/bin/module-frontend-theme-audit.py --module Weline_Compare",
            "python3 app/code/Weline/Theme/bin/module-frontend-theme-audit.py --json",
            "python3 app/code/Weline/Theme/bin/module-frontend-theme-audit.py --baseline app/code/Weline/Theme/test/fixtures/module-frontend-theme-baseline.json",
            "python3 app/code/Weline/Theme/bin/module-frontend-theme-audit.py --write-baseline app/code/Weline/Theme/test/fixtures/module-frontend-theme-baseline.json  # 迁移后下调",
            "```",
        ]
        Path(args.md).write_text("\n".join(lines) + "\n", encoding="utf-8")

    if args.json:
        print(json.dumps(report, ensure_ascii=False, indent=2))
    elif not args.write_baseline:
        print(f"modules={report['modules_scanned']} files={report['files_scanned']} "
              f"bad_files={report['bad_files']} hits={report['total_violations']} "
              f"P0={report['by_severity']['P0']} P1={report['by_severity']['P1']} "
              f"P2={report['by_severity']['P2']} ok={report['ok']}")
        print(json.dumps(report["by_code"], ensure_ascii=False))
        for r in report["rows"][:15]:
            print(f"  {r['violations']:6d} {r['module']}")
    return exit_code


if __name__ == "__main__":
    raise SystemExit(main())
