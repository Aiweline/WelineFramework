# channel — Wave-2 运营意图复审（电商顾问）

日期：2026-09-22  
角色：`Team:电商顾问:`（运营策划；**禁写码**）  
对照：`homepage-wave2-ops-brief.md` 成功标准  
技术回执：`homepage-wave2-p1-04-done.md` / `homepage-wave2-p2-05-done.md` / `homepage-wave2-p2-06-done.md` / `homepage-wave2-p2-07-done.md` / `homepage-wave2-p2-08-done.md`  
验收面：`https://p05113ef3.test.weline.com:9555/`、`/zh_Hans_CN/`  
探活：`curl --http1.1` + `Cache-Control: no-cache` / `Pragma: no-cache`（边缘曾间歇 502/504；以成功 200 全页 HTML 为准）。Cursor ide-browser 本回合无法建签（navigate/tab 不可用），未做加购交互；P2-06/07 以 SSR 属性 + 技术席烟测口径收口。  
MCP：`prepare_project` status=ok（`client_session_id=ecommerce-advisor-wave2-review-20260922`）；未改 Theme / Product / Cart 业务代码。

---

## 逐项复审

| 工单 | 结论 | 证据（一句） |
|------|------|--------------|
| **WO-HP-P1-04** | **PASS** | 根路径 `data-local=en_US`：可见货架/评价标题与商品名/证言正文均为英文（`Featured Products` / `Customer reviews` + 英品名 + `sb-quote` 英文）；`/zh_Hans_CN/`：`按汉服品类选购` / `特色产品` / `今日特价` + 中文品名（如 `长乐公主`），无英文货架标题串语；两路径 `¥299`=0。**备注（不挡签）**：brief 曾拍板「默认访客=整页 zh_Hans_CN」，现网根路径仍为 `en_US`（与 P1-04 施工回执「默认站 en_US」一致）；本单按「各 locale 整页一致、禁半页混排」过签。 |
| **WO-HP-P2-05** | **PASS** | 根与中文路径 DOM 序均为 Hero CTA → `homepage-trust` → `#homepage-featured`；信任条文案 `Free shipping over $49` / `满 $49 包邮`（+ 退换/安全支付）；全页 `¥299`=0；英文面 `trust-badges`×2（首屏+页底口径）。 |
| **WO-HP-P2-06** | **PASS** | 两路径 Newsletter SSR：`data-trigger=deferred`、`data-delay=15000`、`data-scroll=40`、`data-min-open=3000`；弹层 `display:none` 且无 `is-open`（首访首屏不立即弹）。 |
| **WO-HP-P2-07** | **PASS** | 英文全页含 `data-fs-threshold-usd="49"`，文案模板 `You're %1 away from free shipping` / `You've unlocked free shipping`；中文完整页（先次回执级 HTML）含同门槛 + `还差 %1 包邮` / `已享包邮`。本席未做 Browser 加购差额态；交互差额/已包邮以技术席烟测为准。 |
| **WO-HP-P2-08** | **PASS** | Hero 每帧仅 `primary`+`secondary`：英 `Shop Featured`→`#homepage-featured` + `Shop by Occasion`→`/category/hanfu/occasion`；中 `浏览精选`→`#homepage-featured` + `按场景选`→`/zh_Hans_CN/category/hanfu/occasion`；无第三同质 Shop CTA；中文场景 URL nocache **200**。 |

---

## 总评

**Wave-2 运营意图可签：YES（五单均 PASS）。**  
无返工工单。

不挡签备注（请 PM 记账，不必本波返工）：

1. **默认 locale 拍板 vs 现网**：brief「默认=zh_Hans_CN」未改网站默认语种；根路径仍整页英文。若产品要坚持「未切语种即中文」，另开 Wave 改默认 locale / 根路径跳转，勿与 P1-04 混排修复捆在一起。  
2. **中文路径 UGC**：`testimonials` / `image-gallery(looks)` 配置标题已中文，但条目可为空（无 `sb-quote`）；英文路径证言有 3 条——属内容填充债，非半页串语。  
3. **探活抖动**：验收面间歇 502/504；Cursor Browser 本回合不可用——后续汇审若需加购看差额态，由测试席补禁缓存 Browser。

---

## 返工工单（给 PM）

无（本波 FAIL=0）。

---

## 升级项目经理

`notify_pm: true`

`@项目经理：电商顾问运营意图复审已 closed — Wave-2 五单（P1-04 / P2-05 / P2-06 / P2-07 / P2-08）均 PASS，运营可签；请更新 SESSION / 写 meetings/汇审-wave2.md。无返工。验收面 https://p05113ef3.test.weline.com:9555/`

## related_web_urls

- [首页验收](https://p05113ef3.test.weline.com:9555/)
- [中文首页](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/)
- [场景导购（次 CTA）](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/category/hanfu/occasion)
