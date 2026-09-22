# 事件×触发×参数齐备×对照 GA4 审计表

**日期**：2026-09-22  
**Host**：https://p05113ef3.test.weline.com:9555/  
**字典**：`Weline_Visitor/etc/event_dictionary.json`  
**证据源**：本机 `w_pixel` + `w_pixel_additional`（父会话审计 + PM 调度）

状态说明：`PASS` = 已触发且字典 required_params / GA4 推荐参大致齐；`FAIL-param` = 已触发但参数不合规；`NOT-IN-WAVE` = 本波窗口未新测到；`SKIP` = 未走通路/无 UI。未测不得标 PASS。

## 本波重点（电商 / 页 / 搜）

| 事件 | ga4_event | 是否触发 | 参数是否齐 | 对照 GA4 | 代表 pixel_id | 备注 / rework |
|------|-----------|----------|------------|----------|---------------|---------------|
| view_item | view_item | 是 | 是（items+currency+value） | PASS | 50251 | sku 可空；item_id 可用 |
| add_to_cart | add_to_cart | 是 | 是 | PASS | 50225 | — |
| view_cart | view_cart | 是 | **否→已闸**（空壳禁入；有货待 R2c 后复测） | **R2a PASS 闸** | 旧 FAIL 50214；新空壳=0 | 数据分析 closed |
| begin_checkout | begin_checkout | 是 | **否→已闸**（空壳/#payment-recovery 禁入） | **R2a PASS 闸** | 旧 FAIL 50228 | 数据分析 closed |
| checkout_success | purchase | 历史有 | 历史齐 | 历史 PASS；**本波 NOT-IN-WAVE** | 50027 | → R2b 真结账 |
| payment_success | purchase | 历史有 | 历史有 tid/value/currency | 历史 PASS；**本波 NOT-IN-WAVE** | （历史） | → R2b |
| purchase | purchase | **0 行** | — | **NOT-IN-WAVE** | — | → R2b |
| page_view | page_view | 是 | **是**（additional 含 page_location/title） | **PASS** | **50359** | 数据分析 R2a |
| search | search | 是 | **否→已闸**（无 search_term 禁入） | **R2a PASS 闸** | 旧 FAIL 50063 | 数据分析 closed |

## 其它字典事件（摘要）

| 家族 | 处理 |
|------|------|
| page_enter / page_hide / page_exit | 待测；page_* 与 page_view 同批修参后复测 |
| cta / hero_cta / route_click | 待测 |
| express_pay* / friend_help* / quick_buy* / selection_share* | 有 UI 才测；SKIP 须写理由 |
| login | 历史 PASS（50035）；本波非焦点 |
| register / sign_up / lead / refund / wishlist / remove_from_cart / select_* / promotion / site_error | 待测或 SKIP |

## rework 波次

| id | owner | 目标 |
|----|-------|------|
| R2-param-shell | **Team:数据分析:** | 禁空 items 的 view_cart/begin_checkout；page_view 补 page_location/title；search 必 search_term |
| R2-purchase-pathway | **Team:测试:**（± **Team:支付开发工程师:** 通路保驾） | 真实加购→结账成功→支付成功；验 purchase 参 |

通道：`channel/rework-param-ga4.md`

## R2 汇审追加（2026-09-22 13:27 · 采纳 acceptance-sitewide-funnel.md）

主链 **未 PASS**。已调度：

| id | owner | 动作 |
|----|-------|------|
| R2a | 数据分析 a55f4e78 | 禁空壳 view_cart/begin_checkout |
| R2b | 支付 afe691fe + 测试 281e1a4f | 强制 CNY+CN fake_card Paid → success 新 pixel |
| R2c | 后端 Cart 986e4c59 | `/cart` capability_denied / 空车可读 |
