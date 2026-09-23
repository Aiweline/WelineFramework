# 全站点击 QA · 修复计划（架构分流）

日期：2026-09-23  
依据：`sitewide-click-qa-issues.md` + 架构席探查  
原则：架构问题调契约/边界；简单缺陷快修；禁止假绿

## 分流表

| ID | 分类 | 优先 | 主责 | 依赖 QA-01 |
|---|---|---|---|---|
| QA-01 FiberOutputBuffer E_ERROR / Worker 挂 | **架构调整** | P0 | 框架 Runtime | —（先做） |
| QA-06 products `Undefined $content` | **架构调整**（Taglib 条件编译） | P0 | 框架 View/Taglib | 可并行，减压 |
| QA-09 结算加载中+空车+有表单 | **模块契约**（含 Form `hidden` 丢弃） | P0 | Form + Checkout | WLS 稳后验 |
| QA-05 购物车三态并存 | **模块契约** | P0 | Cart | 同左 |
| QA-04 `get()?.close` | **模块契约** | P1 | Theme UI | 可并行 |
| QA-03 热搜无商品 | **内容/轻契约** | P1 | Search + 运营 | 可并行 |
| QA-02 / QA-11 订单跟踪→登录无游客表单 | **产品意图**（合并） | P2→P1 | Theme/Order/Customer | 否 |
| QA-07 规格图文不一致 | **数据** | P3 | 运营 | 否 |
| QA-08 `/1` 404 | **可延期** | P3 | 链扫 | 否 |
| QA-10 首页维护弹层挡首屏 | **环境/契约**（本机验收是否应开维护态） | P0 | Maintenance + 运维 | 否（可并行） |
| QA-12 登录 referer 双重编码 | **模块契约快修** | P1 | Customer | 否 |
| QA-13 政策短链/注册别名 404 | **路由契约快修** | P1 | CMS/Policy + Customer | 否 |
| QA-14 分类 title 笼统 | **快修/SEO** | P3 | Theme/Product | 否 |
| QA-15 Checkout「Back to cart」未译 | **快修 i18n** | P3 | Checkout + 翻译 | 否 |
| QA-16 列表首卡 CTA 不一致 | **可延期/内容** | P3 | Product | 否 |
| QA-17 无会话 `/cart` 长加载 | **补证 QA-05**（不另开根因） | — | Cart | 并入 Wave1 |

## 波次

1. **Wave 0**：QA-01（OB 回调纯度 + headroom）∥ QA-06（Taglib condition）∥ **QA-10**（本机关维护弹层或验收环境豁免契约）
2. **Wave 1**：Form `hidden` → Checkout/Cart 状态机（含 QA-17）+ guest 身份
3. **Wave 2**：UI.close；热搜；**QA-12 referer**；**QA-13 短链 301/`/privacy`→`/policy/privacy` 等 + `/create`→register**
4. **Wave 3**：QA-02/11 游客跟踪产品确认；14/15 快修；07/08/16 低优

## 完成定义

- 压力浏览无 `appendToFrame` display-handler fatal
- `/cart` `/checkout` 同时只暴露一种用户可见态；有货车与结算身份一致
- products 无 `$content` WARNING
- 本机验收首屏不被维护弹层默认拦截（或文档明确维护态验收例外）
- 页脚/FAQ 政策短链可达；登录后 referer 回跳正确
- Wave0 绿灯后再做 Browser 深点收口

状态：**高优 Browser 汇审 8/8 pass**（含 QA-09 热修复测）。Wave3（QA-02/11/14/15 等）未开。

## Codex 规划席对齐（QA-01～08，2026-09-23）

与上表一致处：QA-01/06 架构或编译契约优先；QA-04 快修；QA-03/07 数据优先；QA-02/08 延期待产品/来源证据。

补强调（施工须遵守）：
- **QA-05 升为架构**：单一购物车快照 + 互斥视图态（Cart / mini-cart / Checkout 同 revision）；禁止只藏 DOM。
- **QA-01**：禁加 memory_limit / 狂重启 Worker 假绿；overflow 须在拼接前；多 Fiber 预算不串用。
- 禁止假绿：不吞 site_error、不改 `view/tpl` 发布副本、不手工塞搜索索引、不把 `/1` 301 到首页。

Codex 全文三节已产出（exit 0）；宿主以本文件 + 在途席位为准继续施工。

