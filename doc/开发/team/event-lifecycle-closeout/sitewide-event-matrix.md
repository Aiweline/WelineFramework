# 全站像素事件 × 页面通路矩阵

**日期**：2026-09-22  
**Host**：https://p05113ef3.test.weline.com:9555/  
**字典**：`Weline_Visitor/etc/event_dictionary.json`（49）  
**验收标志**：`w_pixel` / `w_pixel_additional`；WB 须抹 `navigator.webdriver`

## 通路 → 期望事件

| 通路 | URL 例 | 自动/DOM | 显式 track / 成功页 |
|------|--------|----------|---------------------|
| 首页 | `/` | page_view, page_load, page_enter/hide | cta_click, hero_cta_click, route_click |
| 列表/类目 | `/` 类目链、搜索结果 | view_item_list, select_item, search_* | — |
| PDP | `/product/{id\|slug}` | view_item | add_to_cart, add_to_wishlist, share, quick_buy, express_pay* |
| 购物车 | `/cart` | view_cart | remove_from_cart |
| 结账 | `/checkout` | begin_checkout | add_shipping_info, add_payment_info, express_pay_* |
| 结账成功 | `/checkout/success?...` | checkout_success, purchase 族 | checkout_failure（失败页） |
| 支付成功 | `/payment/success` / return | payment_success | — |
| 账户 | `/customer/account/login` | — | login / register / sign_up（Observer+前端） |
| 线索 | 表单页 | — | lead_submit, generate_lead |
| 错误 | 任意 | site_error | — |

## 特殊电商链（需真实 UI 步骤，禁止假造）

friend_help_pay*、selection_share*、quick_buy*、express_pay* 全家：仅当对应控件/沙盒通路可走时标 PASS；否则 SKIP 并记缺口给项目经理。

## 基线

见本回合 `/tmp/weline_sitewide_baseline.txt` + marker。

## 本波缺口（勿标 PASS）

| 事件 | 通路 | 状态 | 证据 / 备注 |
|------|------|------|-------------|
| view_item | PDP `/product/hua-chao-ji-bi-yun-xia-…` | **PASS（rework closed）** | 代表 `50176`；复现 `50168/50159/50146/50201`；数据分析 closed |
| add_to_cart | PDP 加购按钮 `weline-pixel::add_to_cart` | **PASS（rework closed）** | 代表 `50171`；复现 `50161/50203`；数据分析 closed |

> 全站其余字典事件仍按测试席主链继续验收；未测不得标 PASS。
