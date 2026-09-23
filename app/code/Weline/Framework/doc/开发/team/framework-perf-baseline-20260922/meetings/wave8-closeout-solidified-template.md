# wave8 closeout — 固化模板直读主门

- date: 2026-09-22 ~19:49+08
- channel: msg-101（re msg-95/97/100）
- claim_sla: false

## 用户口径（达成）

店面访问 = 直接加载固化模板（`shell.phtml`）；重新生成仅主题可视化编辑发布 + 注入收集；其它路径不运行时拼槽。

## 证据链

| 节点 | 结果 |
|------|------|
| 8a2 stance | frozen（msg-95） |
| 8s5 Theme 2.2.581 | shell 直读 + fill/injectChrome 硬禁 + rebakeAfterInjectionCollect |
| PM 迁写 | shells_written=83 |
| 8v2 主门 | pass（msg-100）：header/chrome_slot absent；LayoutSlot≈82ms；marker=0 |

## 明确关闭

- 8c\* HotCache 种袋主波（不得代替固化直读）
- 同题再改 8s5（无回归）

## 另案（不阻塞）

total 辅证（card.render / storefront_head）— 新 thread / 新需求再开。
