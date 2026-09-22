# contracts — team-closeout-related-web-urls

## UC（可执行）

1. 任一席 `result=closed` 的回报单必须含 `related_web_urls`：本席本波触及的前台/后台/API http(s) 地址清单；无则写 `无`/`N/A`。
2. 项目经理汇审通过后向用户汇报完成时，必须写「交付地址」小节，汇总各席 `related_web_urls` + 测试探活地址，格式服从 `feature_delivery_urls`；禁止只说「已完成」不列地址。
3. 纯逻辑/无 Web 面：交付地址写 `N/A`，回报单 `related_web_urls=无`。

## 权威指针（不新造冲突规矩）

- 已有：`feature_delivery_urls` / `closeout_delivery_reminder` / `WebUI浏览器验收与交付地址门禁.md`
- 本波补强：工程团队回报单字段 + 骨架 + 项目经理/测试席镜 + `engineering_team` surface norm
