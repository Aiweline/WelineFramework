# 审图 — 尾款结账订单详情

- 分流：web_ui + ui_shot（结账 hang 尾款空旷面板）
- 线稿：顶栏「返回批发身份」→ 主卡「标题+应付金额」→ 订单 meta/行列表/金额拆分 → 支付方式 → CTA
- 原型调整：补 order_summary；压缩 max-width；支付块与订单块分层；未登录骨架「正在加载订单明细」
- 审图结论：pass（主题 Token / w-badge / w-button）
- 已修：空旷仅金额+支付方式、返回购物车误导、订单详情缺失
- 验收：登录态打开真实 UUID；E2E mock 含行项目断言 PASS
