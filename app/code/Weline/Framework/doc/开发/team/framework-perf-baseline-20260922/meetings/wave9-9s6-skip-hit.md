# wave9-9s6 — skip 未命中热修（主题 · msg-124/125）

- date: 2026-09-22 ~21:25+08
- seat: Team:主题开发工程师:
- Theme: **2.2.590 → 2.2.591**
- claim_sla: **false** · 禁自 reload · 禁 8c\* · 禁回退匿名双页 chrome
- work_mode: `theme_module_runtime`

## 诊断（本机匿名 Worker HTML）

| 样本 | 出站 gate | 备注 |
|------|-----------|------|
| `/` MISS · `/products` MISS | `reason=none` · needs=N | 出站已是 heal 后完整壳 |
| 9v4 debug `/tmp/weline_zero_fill_debug.log` | `needs=Y emptyCrit=Y shell=Y footer_c=N` | **入口** HTML 空 `footer--shell` |

模拟入口（出站 HTML 替换 footer 为 shell）后：

| 步 | reason | needs |
|----|--------|-------|
| PRE | `missing_chrome_blank_header_or_footer` | Y |
| `prefillPublishedChromeFromRenderedSnapshot(themeId=1→global 3)` | `none` | N（~5–50ms） |

## 误判支

**`shellHasEmptyCriticalPublishedSlots`（+ 2.2.590 回填）**：`chromePresent` 时 critical 仍含 `header|footer` → Partials 已有 `weline-header`，空/`weline-footer--shell` published 包装仍 `emptyCrit=Y` → 每请求 `safety_net_fill` + **先 prime**（壳 include 税）→ LayoutSlot ~2.2s。

非：`slot-placeholder` / filter `data-placeholder` / `shellMissing` 真缺头栏信号。

## 修复

1. chrome 完整 → critical **仅** filters；空 header/footer 根 → `shellMissing`（缺壳）。
2. `weline-header-slot` 子串不再当 header 信号。
3. LayoutSlot：gate → **snapshot prefill（禁 prime）** → 完整则 `+chrome_snapshot_prefill+skip_fill_solidified`；否则 prime+heal。
4. `shellSafetyNetFillReason` + reason `+gate_*`。
5. 确认无 `/tmp/weline_zero_fill_debug.log` 写盘。

## 验证

- UT：20 PASS（ForcedZeroFill + Solidified + ZeroRuntime）
- CLI：空 footer shell → prefill → `reason=none`（可 skip）

## 期望 9v5

LayoutSlot ≪100 · `skipped_fill=true` · reason 含 `skip_fill_solidified`（常见 `+chrome_snapshot_prefill`）· 完整性不回退。
