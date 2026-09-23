# wave9-9s5 — LayoutSlot 完整壳 skip 快路径（主题 · msg-121）

- date: 2026-09-22 ~21:10+08
- seat: Team:主题开发工程师:
- Theme: **2.2.588 → 2.2.589**
- claim_sla: **false** · 禁自 reload · 禁 8c\* · 禁回退 9s4 匿名双页 chrome

## 根因

9v3：完整性 pass · header ~0.15ms · LayoutSlotRenderer **~1569–1622ms** · `zero_runtime_fill`=`safety_net`。

1. heal 过宽：空 `delivery` / 嵌套 chrome 扩展槽触发 `healPublishedPlaceholderShell`（每请求 fill/include）。
2. 假空白：`(.*?)` 同名标签截断误判 `footer` blank。
3. skip 仍先 `primeStorefront`（壳 include 税）。

## 修复

- 有 chrome 信号 → 仅空 filters / placeholder / 缺壳 → heal；否则 `+skip_fill_solidified`（不 prime）。
- published 槽空白 = 深度平衡截取。
- 空 published chrome 槽 ≠ chrome 信号；`header-nav` 不被子串 `header-nav-extensions` 误命中。

## 验证

- UT：23 PASS（ForcedZeroFill + Solidified + ZeroRuntime + ZeroDataWslot）
- 本机出站 HTML（reload 前）：home/prod `shellNeeds=NO` · `weline-header=3`

## 期望 9v4

LayoutSlot ≪100 · marker=0 · 匿名 `/`+`/products` chrome 不回退。
