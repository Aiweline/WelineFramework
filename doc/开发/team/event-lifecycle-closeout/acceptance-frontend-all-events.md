# 全站前端事件触发 + 入库验收（对照字典）

- Host：`https://p05113ef3.test.weline.com:9555/`
- 字典：`app/code/Weline/Visitor/etc/event_dictionary.json` v1.3.3（**49** 事件）
- **本波 BASE**：`63438`（`/tmp/fe_all_base.txt`）；库内观测至 **63469**（31 行 >BASE）
- **msg-46 STOP**：Host **502** / 无实例；**禁 server:***；**已停 Browser**；本席仅 **审计已有 >BASE 样** 的 required_params，**未宣称 49 全量复测完成**
- 判据：触发 → `w_pixel` 有行；`required_params` 在 `total_event_data` 顶层 / `additionalInfo.meta` / `__ga4Params` 齐全且正确
- 方法：Browser（禁缓存 + 抹 `navigator.webdriver`）→ 中断后改 **只读 DB 审计**；禁假 INSERT / 假 PASS

## 字典全量矩阵（49）

| # | 事件 | 判 | pixel_id / 证据 | 备注 |
|---|------|----|-----------------|------|
| 1 | page_view | **PASS（本波>BASE）** | **63468**（另 63439/41/43/49/51/54/59/62/65/67） | page_location+page_title 齐；含 `/checkout` |
| 2 | page_enter | **SKIP** | — | 无独立前台发射（只发 page_view）；本波 >BASE 亦 0 |
| 3 | page_hide | **待复测** | 历史 62677/63004 | 本波 >BASE **无样**（STOP 前未触达） |
| 4 | page_exit | **待复测** | 历史 50721/63010 | 本波 >BASE **无样** |
| 5 | cta_click | **待复测** | 历史 37388 | 本波 >BASE **无样** |
| 6 | hero_cta_click | **PASS（本波>BASE）** | **63446** | link_url+#homepage-featured；link_text=「浏览精选」 |
| 7 | view_item | **PASS（本波>BASE）** | **63452** | items=1 currency=USD |
| 8 | add_to_cart | **PASS（本波>BASE）** | **63457** | items=1 currency=USD value=7.9 |
| 9 | friend_help_pay | **SKIP** | 历史 37995 | 深通路；本波无样 |
| 10 | selection_share | **SKIP** | 历史 43150 | 深通路；本波无样 |
| 11 | quick_buy | **SKIP** | 历史 50345 | 深通路；本波无样 |
| 12 | express_pay | **SKIP** | 历史 43321 | 需支付沙箱；本波无样 |
| 13 | friend_help_pay_link_ready | **SKIP** | — | 依赖代付出链 |
| 14 | selection_share_link_ready | **SKIP（历史）** | 43148 | 本波无样 |
| 15 | quick_buy_checkout_ready | **SKIP（历史）** | 50348 | 本波无样 |
| 16 | express_pay_started | **SKIP（历史）** | 43322 | 本波无样 |
| 17 | express_pay_confirmed | **SKIP（历史）** | 50032 | 本波无样 |
| 18 | express_pay_transaction | **SKIP（历史）** | 38013 | 本波无样 |
| 19 | friend_help_pay_checkout_success | **SKIP（历史）** | 50878 | 本波无样 |
| 20 | selection_share_checkout_success | **SKIP（历史）** | 50880 | 本波无样 |
| 21 | quick_buy_checkout_success | **SKIP（历史）** | 50879 | 本波无样 |
| 22 | express_pay_checkout_success | **SKIP（历史）** | 54283 | 本波无样 |
| 23 | begin_checkout | **待复测** | 历史 63128 | 本波有 checkout **page_view=63468**，但 **无 begin_checkout 行**；STOP 未续测 |
| 24 | checkout_success | **SKIP（历史）** | 54628 | 本波未跑支付 |
| 25 | payment_success | **SKIP（历史）** | 50390 | 本波未跑支付 |
| 26 | checkout_failure | **SKIP** | — | 需故意失败通路 |
| 27 | search_submit | **待复测** | 历史 63422/63437 | 本波 >BASE **无样**（STOP） |
| 28 | search_suggestion_click | **待复测** | 历史 44418 | 本波无样 |
| 29 | route_click | **待复测** | 历史 63125 | 本波 >BASE **无样**（有 select_item 无独立 route_click） |
| 30 | lead_submit | **待复测/SKIP** | 历史 12079 | 本波无样 |
| 31 | login | **待复测** | 历史 50635 | 本波无样 |
| 32 | register | **待复测** | 历史 50134 | 本波无样 |
| 33 | purchase | **SKIP** | — | 独立名全库 0；桥 GA4 |
| 34 | add_payment_info | **SKIP** | — | 本波无样 |
| 35 | add_shipping_info | **SKIP** | — | 本波无样 |
| 36 | add_to_wishlist | **PASS（本波>BASE）** | **63458** | currency=USD value=18.63 items=1 |
| 37 | remove_from_cart | **待复测** | 历史 63182 | 本波 >BASE **无样** |
| 38 | view_cart | **PASS（本波>BASE）** | **63461** | currency=USD value=15.8 items=1 |
| 39 | select_item | **PASS（本波>BASE）** | **63456** | items=1 |
| 40 | view_item_list | **PASS（本波>BASE）** | **63444** | items=20 |
| 41 | view_promotion | **SKIP** | — | 无稳定通路 |
| 42 | select_promotion | **SKIP** | — | 同上 |
| 43 | refund | **SKIP** | — | 需后台 |
| 44 | share | **SKIP** | — | 独立 share 0 |
| 45 | search | **待复测** | 历史 63069 | 本波 >BASE **无样** |
| 46 | sign_up | **SKIP** | — | 映射 register |
| 47 | generate_lead | **SKIP** | — | 映射 lead_submit |
| 48 | select_content | **SKIP** | — | 映射 suggestion |
| 49 | site_error | **闸** | 历史 63043 | 缺 error_message 禁入库 |

### 统计（msg-46 STOP 审计波）

| 类别 | 条数 |
|------|------|
| **本波 PASS（>BASE 且 required_params 齐）** | **8** |
| FAIL / FAIL-param（>BASE 样） | **0** |
| 待复测（A 类本波无样 / Host 502 中断） | **~12** |
| SKIP（结构/深通路/历史维持） | **~28** |
| 闸 | 1（site_error） |

### 本波 PASS 列表（事件 → pixel_id）

| 事件 | pixel_id |
|------|----------|
| page_view | **63468** |
| hero_cta_click | **63446** |
| view_item_list | **63444** |
| select_item | **63456** |
| view_item | **63452** |
| add_to_cart | **63457** |
| add_to_wishlist | **63458** |
| view_cart | **63461** |

### 旁注（非字典）

- `page_load` ×11、`page_transition` ×2（>BASE）— 不计入字典 49

## 增量日志

> **msg-31**：search HTML+像素 PASS（63061/63069）  
> **msg-34**：view_cart PASS（63106）  
> **msg-36**：begin_checkout **63128**；page_enter SKIP  
> **msg-42**：view_item_list=**63187**、select_item=**63189**、remove_from_cart=**63182**、hero_cta_click=**63195**  
> **msg-44**：search_submit **PASS**（63422/63437）  
> **msg-45**：调度全站 49 复测 BASE=**63438**  
> **msg-46**：STOP（Host 502）；只读审计 >BASE：8 PASS / 0 FAIL-param；A 类余项 **待复测**；禁 server:*

## 阻断与调度

1. **Host 502 / 无实例** → 测试席 **停 Browser**，等通知；**禁自行 server:***
2. 数据分析：本波 **无 FAIL-param escalate**
3. 恢复后优先补 A 类无样：page_hide/exit、cta_click、route_click、remove_from_cart、begin_checkout、search/search_submit、login、lead_submit

## 结论

**未完成字典 49 全量复测。** 在 STOP 前库内已有 **8** 个字典事件 >BASE 新样且参数审计 **全部 PASS**；**FAIL=0**。其余 A 类标 **待复测**，结构/深通路仍 **SKIP**。等 Host 恢复通知后再续 Browser。
