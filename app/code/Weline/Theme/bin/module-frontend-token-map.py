#!/usr/bin/env python3
"""Build the literal -> Theme token map for module frontend migration (read-only).

Outputs:
  - Theme/bin/module-frontend-token-map.json : machine map + gap list (used by migrators)
  - Theme/doc/module-frontend-token-map.md   : human review table

Reuses the audit scope (`module-frontend-theme-scope.json`) and the disk reverse index /
overrides from `widget-theme-token-migrate.py`, so module migration and widget migration
agree on token names.

Usage:
  python3 module-frontend-token-map.py
  python3 module-frontend-token-map.py --module Weline_Compare
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import re
from collections import Counter, defaultdict
from pathlib import Path

HERE = Path(__file__).resolve().parent


def _load(name: str, filename: str):
    spec = importlib.util.spec_from_file_location(name, HERE / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


audit = _load("mfa_audit", "module-frontend-theme-audit.py")
migrate = _load("wtt_migrate", "widget-theme-token-migrate.py")

RE_RGB_FUNC = re.compile(r"\b(?:rgb|rgba|hsl|hsla)\s*\(([^)]*)\)", re.I)


def px_of(value: str) -> float | None:
    m = re.fullmatch(r"(\d+(?:\.\d+)?)(px|rem|em)", value.strip().lower())
    if not m:
        return None
    n = float(m.group(1))
    return n * 16.0 if m.group(2) in ("rem", "em") else n


def build_maps():
    color_map, size_map, existing = migrate.build_disk_maps()
    # secondary index: px value -> token (for rem/px equivalence)
    size_by_px: dict[float, str] = {}
    for key, token in size_map.items():
        px = px_of(key)
        if px is None or px == 0:
            continue
        current = size_by_px.get(px)
        if current is None or (token.startswith("--weline-space-") and not current.startswith("--weline-space-")):
            size_by_px[px] = token
    return color_map, size_map, size_by_px, existing


def rgb_to_hex(text: str) -> str | None:
    parts = [p.strip() for p in text.split(",")]
    if len(parts) < 3:
        return None
    try:
        r, g, b = (int(round(float(p))) for p in parts[:3])
    except ValueError:
        return None
    return f"#{r:02x}{g:02x}{b:02x}"


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--module", default=None)
    ap.add_argument("--md", default=str(HERE.parent / "doc" / "module-frontend-token-map.md"))
    ap.add_argument("--json", default=str(HERE / "module-frontend-token-map.json"))
    args = ap.parse_args()

    color_map, size_map, size_by_px, existing = build_maps()

    color_literals: Counter = Counter()
    size_literals: Counter = Counter()
    color_sites: dict[str, list[str]] = defaultdict(list)
    size_sites: dict[str, list[str]] = defaultdict(list)
    per_module: dict[str, Counter] = defaultdict(Counter)
    files = 0

    for path, mod in audit.iter_scope_files(True, args.module):
        files += 1
        raw = path.read_text(encoding="utf-8", errors="replace")
        suffix = path.suffix.lower()
        rel = str(path.relative_to(audit.ROOT))
        if suffix in audit.CSS_EXT:
            surfaces = [(rel, audit.strip_noise(raw, False))]
        elif suffix in audit.MARKUP_EXT:
            cleaned = audit.strip_noise(raw, True)
            surfaces = [(rel + "#<style>", m.group(1)) for m in audit.RE_STYLE_BLOCK.finditer(cleaned)]
        else:
            continue
        for src, body in surfaces:
            for m in audit.RE_HEX.finditer(body):
                value = migrate.norm_hex(m.group())
                color_literals[value] += 1
                if len(color_sites[value]) < 3:
                    color_sites[value].append(src)
                per_module[mod]["color_literals"] += 1
            for m in RE_RGB_FUNC.finditer(body):
                hexed = rgb_to_hex(m.group(1))
                if hexed is None:
                    continue
                key = f"{hexed} (alpha)"
                color_literals[key] += 1
                if len(color_sites[key]) < 3:
                    color_sites[key].append(src)
                per_module[mod]["color_literals"] += 1
            for m in audit.RE_SIZE.finditer(body):
                value = m.group().lower()
                if audit.RE_ZERO.fullmatch(value):
                    continue
                size_literals[value] += 1
                if len(size_sites[value]) < 3:
                    size_sites[value].append(src)
                per_module[mod]["size_literals"] += 1

    def map_color(value: str) -> str | None:
        if value.endswith("(alpha)"):
            return color_map.get(value.split(" ")[0])
        return color_map.get(value)

    def map_size(value: str) -> str | None:
        token = size_map.get(value)
        if token:
            return token
        px = px_of(value)
        if px is None:
            return None
        return size_by_px.get(round(px, 2))

    color_rows = []
    for value, count in color_literals.most_common():
        token = map_color(value)
        color_rows.append({
            "value": value, "count": count, "token": token,
            "status": "mapped" if token else "gap",
            "sites": color_sites[value],
        })
    size_rows = []
    for value, count in size_literals.most_common():
        token = map_size(value)
        size_rows.append({
            "value": value, "count": count, "token": token,
            "status": "mapped" if token else "gap",
            "sites": size_sites[value],
        })

    mapped_c = sum(r["count"] for r in color_rows if r["token"])
    mapped_s = sum(r["count"] for r in size_rows if r["token"])
    total_c = sum(r["count"] for r in color_rows)
    total_s = sum(r["count"] for r in size_rows)

    report = {
        "schema": "module-frontend-token-map.v1",
        "module_filter": args.module,
        "files_scanned": files,
        "colors": {
            "distinct": len(color_rows), "occurrences": total_c,
            "mapped_occurrences": mapped_c,
            "coverage": round(mapped_c / total_c, 4) if total_c else 1.0,
            "rows": color_rows,
        },
        "sizes": {
            "distinct": len(size_rows), "occurrences": total_s,
            "mapped_occurrences": mapped_s,
            "coverage": round(mapped_s / total_s, 4) if total_s else 1.0,
            "rows": size_rows,
        },
        "existing_theme_tokens": len(existing),
        "per_module": {m: dict(c) for m, c in sorted(per_module.items())},
    }
    Path(args.json).write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    color_gaps = [r for r in color_rows if not r["token"]]
    size_gaps = [r for r in size_rows if not r["token"]]
    lines = [
        "# 模块前端 字面量 → Theme Token 映射表",
        "",
        "> 生成器：`app/code/Weline/Theme/bin/module-frontend-token-map.py`（只读；改完重跑即可刷新）",
        f"> 范围：合同 scope（`module-frontend-theme-scope.json`），本次 {files} 个文件"
        + (f"，模块过滤 `{args.module}`" if args.module else ""),
        "",
        "## 覆盖率",
        "",
        "| 类别 | 字面量种类 | 出现次数 | 可映射次数 | 覆盖率 |",
        "|---|---:|---:|---:|---:|",
        f"| 颜色 | {len(color_rows)} | {total_c} | {mapped_c} | {report['colors']['coverage']:.1%} |",
        f"| 尺寸 | {len(size_rows)} | {total_s} | {mapped_s} | {report['sizes']['coverage']:.1%} |",
        "",
        "## 颜色映射（出现次数 Top 60）",
        "",
        "| 字面量 | 次数 | Token | 状态 | 示例位置 |",
        "|---|---:|---|---|---|",
    ]
    for r in color_rows[:60]:
        lines.append(
            f"| `{r['value']}` | {r['count']} | {('`' + r['token'] + '`') if r['token'] else '—'} | "
            f"{r['status']} | {r['sites'][0] if r['sites'] else ''} |")
    lines += [
        "",
        "## 尺寸映射（出现次数 Top 60）",
        "",
        "| 字面量 | 次数 | Token | 状态 | 示例位置 |",
        "|---|---:|---|---|---|",
    ]
    for r in size_rows[:60]:
        lines.append(
            f"| `{r['value']}` | {r['count']} | {('`' + r['token'] + '`') if r['token'] else '—'} | "
            f"{r['status']} | {r['sites'][0] if r['sites'] else ''} |")
    lines += [
        "",
        "## 缺口（需要 Lead 在 Theme 公共层补盘，禁止模块内私造）",
        "",
        f"### 颜色缺口（{len(color_gaps)} 种 / {sum(r['count'] for r in color_gaps)} 次）",
        "",
        "| 字面量 | 次数 | 建议落盘 | 示例位置 |",
        "|---|---:|---|---|",
    ]
    for r in color_gaps[:80]:
        lines.append(f"| `{r['value']}` | {r['count']} | `variables/_colors.css` | {r['sites'][0] if r['sites'] else ''} |")
    lines += [
        "",
        f"### 尺寸缺口（{len(size_gaps)} 种 / {sum(r['count'] for r in size_gaps)} 次）",
        "",
        "| 字面量 | 次数 | 建议落盘 | 示例位置 |",
        "|---|---:|---|---|",
    ]
    for r in size_gaps[:80]:
        lines.append(f"| `{r['value']}` | {r['count']} | `variables/_spacing.css` / `_typography.css` / `_borders.css` | {r['sites'][0] if r['sites'] else ''} |")
    lines += [
        "",
        "## 迁移纪律",
        "",
        "1. 颜色：同值 ≠ 同角色。按 `theme-semantic-color-matrix.md` 判定背景/边框/文字/状态，再取 Token；本表只给候选。",
        "2. 尺寸：取最近主刻度（`--weline-space-1..12`），不要发明 `0.35/0.55/1.1` 这类刻度。",
        "3. 圆角：控件 `--weline-theme-radius-md`、卡片 `lg`、大面 `xl`、胶囊 `full`。",
        "4. 缺口 Token 只能补在 Theme 盘；模块侧禁止私有色板/私有 token。",
        "5. 纸面钉、`@media` 断点、`[hidden]`、reduced-motion 例外不进本表。",
    ]
    Path(args.md).write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(f"files={files} color={len(color_rows)}种/{total_c}次 覆盖{mapped_c}/{total_c} "
          f"size={len(size_rows)}种/{total_s}次 覆盖{mapped_s}/{total_s}")
    print(f"gaps: color={len(color_gaps)}种 size={len(size_gaps)}种")
    print(f"written: {args.json}")
    print(f"written: {args.md}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
