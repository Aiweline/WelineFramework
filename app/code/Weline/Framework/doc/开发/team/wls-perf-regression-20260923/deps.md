# deps — wls-perf-regression-20260923

```text
架构师 reply msg-3 (A/B/C)
    ├─→ 后端 A+B（可与主题并行，若架构师已同意 A；B 须确认后动）
    └─→ 主题 C（须架构师对「空投影 []」合规表态）
后端 closed + 主题 closed
    └─→ PM 批 reload（若需）
         └─→ 性能检查工程师 review
              └─→ PM 汇审

P7 B′（msg-20）→ 后端 FIX → 性能 review（机制 pass / 绝对值 fail · msg-27）
    └─→ P8（msg-28 brief）
         └─→ 架构师 O1/O2/O3 freeze（msg-29 · closed）
              ├─→ 后端 O1（硬 · post_locale 短路）【须唤醒】
              ├─→ 主题 O2（框 · chrome miss · 禁拆壳）【可选并行】
              └─→ 后端 O3（次优 · products seal）
                   └─→ PM 批 reload → 性能绝对值复审（done≤5000）
```

并行说明：A 已在架构师 msg-2「分期瘦身种袋」草案中；后端可先动 A，B/C 以 channel 最新架构师 reply 为准。  
P8：O1 不阻塞于 O2；主题可 waiting_peer。禁私自 reload。
