# wave9 closeout — 禁丢件 + LayoutSlot 快路径

- date: 2026-09-22 ~21:42+08
- channel: msg-128（re msg-110/119/127）
- claim_sla: false

## 用户口径（达成）

1. 店面直读固化模板；再生仅编辑发布 + 注入收集（wave8）。
2. **性能优化不能丢东西** — 匿名双页 chrome/footer 稳住。
3. 完整壳 LayoutSlot skip ≪100ms。

## 证据链

| 节点 | 结果 |
|------|------|
| 8s5/8v2 | 固化整壳主门 |
| 9p/9s | A 轴 card/head 降 |
| 9s3–9s4 | showHeader/漏标/page-only → chrome 回站 |
| 9s5–9s6 | heal 误判收窄 + snapshot-before-prime → skip 命中 |
| 9v5 | LayoutSlot ~12–16ms · skipped_fill=true · 完整性 pass |

## 明确关闭

- 8c\* 种袋主波
- 同题再改 skip/chrome（无回归）

## 另案

total≤2152 辅证（非本收口阻塞）。
