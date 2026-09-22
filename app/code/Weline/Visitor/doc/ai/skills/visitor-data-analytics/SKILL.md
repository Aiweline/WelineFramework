---
name: visitor-data-analytics
description: >-
  Engineering-team seat「数据分析」(Team:数据分析:). Owns entire Weline_Visitor:
  pixel runtime, event dictionary/chains, PixelEventVendor, sandbox, dedupe,
  reports, and in-module event.xml/Observer pixel bridges. Must skill-ref
  frontend_development + taglib_ui_control. Product role ≠ Team:事件: (generic
  Framework Event)—not an absolute ban on event.xml. MCP surface
  visitor_data_analytics is authoritative.
---

# Visitor 数据分析 / 像素事件（工程团队专席）

**MCP 权威**：`get_skill(visitor_data_analytics|weline-visitor-analytics)`  
**席位前缀**：`Team:数据分析:`  
**硬规则**：`analytics_engineer_for_visitor_work`

## 归属

整个 `Weline_Visitor` 模块（含后台报表/看板与取数、**本模块** `etc/event.xml` 与 `Observer/**` 像素桥接）。专职重心是前端访客/像素事件规约、扇出、排障与第三方对接；本席是开发席，可改模块内前后端。

## 技能引用（HARD）

开工前必须 `get_skill`（禁止只靠通用骨架）：

| 顺序 | 技能 | 用途 |
|------|------|------|
| 1 | `visitor_data_analytics` / `weline-visitor-analytics` | 本席像素/Visitor 权威 |
| 2 | `frontend_development` / `weline-theme-development` | 主题 Token、BinQuery、后台 chrome |
| 3 | `taglib_ui_control` / `weline-taglib-first` | `<w:scope>` 等选择性控件 |

宿主薄镜像可对照：`frontend-design`（构图，主题 Token 仍优先）。

前端底线：仅 `Weline.Api.resource|graph|stream`→query-bin；禁止 native fetch/ajax；禁止 `Api.request/get/post` 打业务 Controller；禁止「HTTP 主路径 + BinQuery 回退」。Visitor 后台用 `w-*`；站店渠走 Taglib，禁止手写 select。

声明式埋点可 channel 请 **前端** 席协助；复杂 runtime/Vendor/报表/本模块 phtml·JS 必须本席施工。跨模块 QueryProvider peer **API**；Dashboard 部件 peer **部件开发工程师**。

## 与「事件」席边界（产品角色，非文件类型禁令）

| 席位 | 管什么 |
|------|--------|
| **事件** | 通用 Framework Event：跨模块 `event.xml` / Observer / `dispatch` 命名与文档化 |
| **数据分析** | Visitor 像素 / `WelinePixel` / Vendor / 报表；**及**本模块像素桥接 Observer、`Weline_Visitor::*` 事件契约 |

**禁止产品混岗**：事件席不写 `pixel.js` / Vendor / 报表 / Visitor 像素桥接 Observer；本席不接管无关模块的通用 `Framework/doc/event`。业务模块监听 `event_chain_collect` / `taglib_pixel` 的 Observer 落归属业务模块——本席定契约并复审，可 peer 事件席做命名四件套。

**术语**：框架 Event = `event.xml`/Observer/`dispatch`；Visitor「事件」= 事件字典 / 事件链 / Vendor 扇出 / 沙盒——勿把「访客事件」派到 Team:事件:。

## 必读

1. `doc/像素拓展使用指南.md`
2. `doc/Visitor_Pixel_GTM_GA4_系统设计.md`（含 §10 热温冷）
3. `doc/像素事件供应商管理-定稿合同.md`
4. `doc/event/事件链注册.md`
5. `doc/event/访客像素标签.md`
6. `doc/开发/spec/conversion-event-dedupe.md`
7. `doc/数据分析功能使用指南.md`
8. `Weline_Websites/doc/store-saleschannel-scope.md`
9. `Theme/doc/开发/Theme开发总指南.md`
10. `Taglib/doc/场景映射表.md`
11. `Frontend/doc/Weline.Api使用指南.md`

## 硬约定

- **采集**：`WelinePixel.track` / `weline-pixel::` / `data-pixel-event`|`data-visitor-event`|`data-cta-event`|`data-ga-event`；禁止业务旁路 `dataLayer` 或私接三方像素脚本；**禁止用 Observer 替代店面采集主路径**。
- **双通道**：Pixel 入库为主真源；`GtmBridge` 为唯一 GTM dataLayer 出口；开 GTM 必须关 GA4 直连（防双计）；`page_view` 不由 Pixel 重复 push。
- **字典**：改事件名/映射须动 `etc/event_dictionary.json`（`weline_event`/`ga4_event`/`skip_gtm_push`/`event_family`/`page_scopes` 等）。
- **事件链**：`complete_event` 须带 `__event_chain_complete`；禁止把通用 `checkout_success` 设为闭环名。
- **去重**：先 `sandbox.emit` → purchase 族去重占用 → 再扇出；主 track 流禁止因去重提前 `return null`。
- **自定义池**：进池须 `record` 或事件链发布；`observe`/`invent`/`custom_*` 禁止。
- **沙盒**：默认 sandbox；DEV 外发硬拦；ga4+gtm 禁止同时 inject。
- **站店渠 Scope**（Website→Store→Channel，页顶 `<w:scope>`）≠ **路径过滤** `scope_json`。
- **第三方**：Extends `PixelEventVendorInterface` + `cspDirectives()`；禁止在壳或 Framework Defaults 硬编码 SDK 域名。
- **报表**：`PixelQueryRouter` 热温冷；禁止超温静默扫热。
- 触发即双轨：施工 + 合规复审。

## PageBuilder / 声明式埋点要点

与模块 `VisitorPixelSkillProvider`（`weline-pixel-events`）一致：snake_case 事件名；勿 invent `page_view` 等被动页事件；勿注入 gtag/fbq/ttq；元数据勿塞密钥/PII。
