#!/usr/bin/env python3
"""Migrate `<?= __(...) ?>` in module frontend templates to `<lang>` / `@lang`.

Rules (Framework/doc/4-内置标签/01-lang标签使用指南.md + AI 硬规则 template_lang_not_php_i18n):
  1. HTML text node:            <?= __('文案') ?>              -> <lang>文案</lang>
  2. HTML text node with args:  <?= __('共 %{1} 件', [$n]) ?> -> <lang args="[$n]">共 %{1} 件</lang>
  3. HTML attribute value:      title="<?= __('文案') ?>"      -> title="@lang('文案')"
  4. anything else (concat / nested calls / ternary / multi-statement) is reported, not touched.

Conservative: only single-quoted or double-quoted literal source strings, balanced args,
no PHP interpolation inside the source, no HTML tags inside the source.

Usage:
  python3 module-frontend-i18n-migrate.py --module Weline_Product            # dry run
  python3 module-frontend-i18n-migrate.py --all --apply
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import re
from collections import Counter
from pathlib import Path

HERE = Path(__file__).resolve().parent


def _load(name: str, filename: str):
    spec = importlib.util.spec_from_file_location(name, HERE / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


audit = _load("mfa_audit", "module-frontend-theme-audit.py")

# <?= __('x') ?> / <?= __("x") ?> / <?= __('x', ARGS) ?>
RE_FLAT = re.compile(
    r"<\?=\s*__\(\s*(?P<q>['\"])(?P<text>(?:(?!(?P=q))[^\\]|\\.)*)(?P=q)\s*"
    r"(?:,\s*(?P<args>\[[^\[\]]*\]|(?:[^()]|\([^()]*\))*?)\s*)?\)\s*\?>",
    re.S,
)
RE_TAG_ATTR_CTX = re.compile(r"<[^<>]*$", re.S)


def in_html_attribute(text: str, pos: int) -> bool:
    """True when pos sits inside an open tag (attribute value), not in text content."""
    window = text[max(0, pos - 600):pos]
    last_open = window.rfind("<")
    last_close = window.rfind(">")
    if last_open < 0 or last_open < last_close:
        return False
    tail = window[last_open:]
    return re.match(r"<[a-zA-Z/!]", tail) is not None


RE_ECHO = re.compile(r"<\?=\s*(.*?)\s*\?>", re.S)
# 常见包裹形式：<?= $safe(__('x')) ?> / htmlspecialchars((string) __('x'), ENT_QUOTES) / (string) __('x')
RE_WRAPPED = (
    re.compile(r"^(?:htmlspecialchars|htmlentities)\s*\(\s*(?:\(string\)\s*)?__\(\s*(['\"])((?:(?!\1).)*)\1\s*\)(?:\s*,[^)]*)?\)$", re.S),
    re.compile(r"^\$[A-Za-z_][A-Za-z0-9_]*\s*\(\s*__\(\s*(['\"])((?:(?!\1).)*)\1\s*\)\s*\)$", re.S),
    re.compile(r"^(?:\(string\)\s*)?__\(\s*(['\"])((?:(?!\1).)*)\1\s*\)$", re.S),
)


def convert_wrapped(text: str, stats: Counter, review: list[dict], src: str) -> str:
    def repl(m: re.Match) -> str:
        code = m.group(1).strip()
        for pattern in RE_WRAPPED:
            mm = pattern.match(code)
            if not mm:
                continue
            quote, src_text = mm.group(1), mm.group(2)
            if "$" in src_text or "<" in src_text or ">" in src_text:
                review.append({"src": src, "reason": "wrapped-dynamic", "match": m.group(0)[:120]})
                return m.group(0)
            out = src_text.replace("\\" + quote, quote)
            if in_html_attribute(text, m.start()):
                if '"' in out:
                    review.append({"src": src, "reason": "attribute-quote", "match": m.group(0)[:120]})
                    return m.group(0)
                stats["attribute_at_lang"] += 1
                return f"@lang('{out}')"
            stats["lang_plain"] += 1
            return f"<lang>{out}</lang>"
        return m.group(0)

    return RE_ECHO.sub(repl, text)


def transform(text: str, stats: Counter, review: list[dict], src: str) -> str:
    def repl(m: re.Match) -> str:
        quote = m.group("q")
        raw_text = m.group("text")
        args = m.group("args")
        if "$" in raw_text.replace("\\\\$", ""):
            review.append({"src": src, "reason": "php-interpolation", "match": m.group(0)[:120]})
            return m.group(0)
        if "<" in raw_text or ">" in raw_text:
            review.append({"src": src, "reason": "html-in-source", "match": m.group(0)[:120]})
            return m.group(0)
        text_out = raw_text.replace("\\" + quote, quote)
        if in_html_attribute(text, m.start()):
            if args:
                review.append({"src": src, "reason": "attribute-with-args", "match": m.group(0)[:120]})
                return m.group(0)
            if '"' in text_out:
                review.append({"src": src, "reason": "attribute-quote", "match": m.group(0)[:120]})
                return m.group(0)
            stats["attribute_at_lang"] += 1
            return f"@lang('{text_out}')"
        if args:
            args_clean = re.sub(r"\s+", " ", args.strip())
            if '"' in args_clean:
                review.append({"src": src, "reason": "args-with-double-quote", "match": m.group(0)[:120]})
                return m.group(0)
            stats["lang_with_args"] += 1
            return f'<lang args="{args_clean}">{text_out}</lang>'
        stats["lang_plain"] += 1
        return f"<lang>{text_out}</lang>"

    text = RE_FLAT.sub(repl, text)
    return convert_wrapped(text, stats, review, src)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--module", default=None)
    ap.add_argument("--all", action="store_true")
    ap.add_argument("--apply", action="store_true")
    ap.add_argument("--report", default=str(HERE.parent / "test/fixtures/module-frontend-i18n-report.json"))
    args = ap.parse_args()
    if not args.module and not args.all:
        ap.error("pass --module Weline_Xxx or --all")

    stats: Counter = Counter()
    review: list[dict] = []
    changed: list[dict] = []
    for path, mod in audit.iter_scope_files(True, args.module):
        if path.suffix.lower() not in audit.MARKUP_EXT:
            continue
        raw = path.read_text(encoding="utf-8", errors="replace")
        if "__(" not in raw:
            continue
        rel = str(path.relative_to(audit.ROOT))
        file_stats: Counter = Counter()
        out = transform(raw, file_stats, review, rel)
        if out != raw:
            stats.update(file_stats)
            changed.append({"path": rel, "stats": dict(file_stats)})
            if args.apply:
                path.write_text(out, encoding="utf-8")

    report = {
        "schema": "module-frontend-i18n-migrate.v1",
        "applied": bool(args.apply),
        "module": args.module or "ALL",
        "changed_files": len(changed),
        "stats": dict(stats),
        "review_total": len(review),
        "review_by_reason": dict(Counter(r["reason"] for r in review)),
        "review": review[:800],
        "files": changed,
    }
    Path(args.report).write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"applied={args.apply} files={len(changed)} stats={dict(stats)} "
          f"manual={len(review)} by_reason={report['review_by_reason']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
