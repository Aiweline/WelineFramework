# wave8-8s5 — 固化整壳店面直读（主题）

- date: 2026-09-22 ~19:40+08
- seat: Team:主题开发工程师:
- channel: `framework-unreasonable-audit.md` **msg-97**（re msg-95/96 · 8a2 stance）
- Theme: **2.2.581** · claim_sla: **false** · 禁自 reload

## 口径（服从 8a2）

1. 店面访问 = include 含 header/chrome 的 published bake 整壳（`shell.phtml`）。
2. 再生仅：① 可视化编辑器发布；② 注入收集（default_injection / 有部件必入）。
3. 其它访问禁止 runtime `injectChrome` / `SlotFiller::fill` / `chrome_slot_projection` 拼布局。

## 改动

| 面 | 落点 |
|----|------|
| 整壳 bake | `ThemeLayoutEntityBakeCoordinator::writePublishedWholeShell`；publish 后写 `shell.phtml`；chrome 变更刷新 scope 下 published shells |
| 注入收集重生 | `rebakeAfterInjectionCollect` ← `ApplyWidgetDefaultInjections`（applied>0） |
| 店面 fragments | `renderPublishedSolidifiedFragments` 优先 shell；legacy 直读 chrome 盘；禁 `rememberPublishedChromeSlotProjection` |
| 禁 fill | published `fill` / `fillChromeOnly` / `fillRequiredDefaultsOnShell` no-op；LayoutSlot `+skip_fill_solidified` |
| editor/preview | draft / reactive 重路径保留 |

## 契约 UT

`PublishedStorefrontSolidifiedShellContractTest` 等 → **PASS**（18 tests / 本批）。

## escalate

@项目经理：可开 8v 复测（门对齐固化直读，非种袋主闭合）。
