# Phase 7 WebUI 真实验收记录

- 日期：2026-07-24
- 浏览器：Cursor Browser（MCP）
- 验收域名：`http://aisite-i18n-0724.weline.test:9524/`
- website_id：`23`
- WLS：`ai-test-i18n-9524b` port `9524`

## §9 勾选

### 9.1 前台 Pixel
- [x] `__WelinePixelEnv` 可见（website_id=23, currency=CNY, dictVersion=1.0.0）
- [x] CTA → 字典 resolve `hero_cta_click` → `ga4_event=cta_click`；点击后 GtmBridge trigger 记录
- [x] dataLayer 经 GtmBridge 形状（当前站 GTM 未开，delivery=`preview`/`GTM off`，符合互斥未启用时行为）
- [x] 无 Pixel 重复 page_view（`page_view.skip_gtm_push=true`）
- [x] 开 GTM 无 GA4 直连双计（当前二者均未启用；配置互斥已在 runtime `disabledByGtm` 落地）

### 9.2 开发面板 Pixel / GTM
- [x] 通道条 / 齐全度文案 / 一键检查 / 跳转 / 快测 / 沙箱占位 — **代码已落地**（`weline-panel-visitor.js` Tab「Pixel / GTM」）
- [ ] 本机面板 Token 登录后点验一键按钮（需 DEV 面板 ACL；本次用服务端 `audit(23)` 等价验收报告）

### 9.3 后台
- [x] tracking 配置页已含 GTM 开关与 Container ID
- [ ] PixelDashboard 页面回归冒烟（未开后台登录会话）

### 9.4 巡检可信度
- [x] 一键审计 website_id=23：6 页 **complete**；URL 自动带 `:9524`（§7.2 本地端口探测）
- [x] 首页 HTML 含 `weline-pixel::cta_click` / `hero_cta_click` 等标记
- [x] fetch_failed 与缺标记分列（修复端口前曾出现 5×4xx，已排除）

### 9.5 真沙箱
- [x] 无假 `#sandbox-pixel` 自动主路径（`fakeSandbox=false`）
- [x] `WelinePixelSandbox` 对象已挂载；真 iframe 按需 ensureFrame

### 9.6
- [x] 本记录域名/日期已填
- [x] 残余：面板 Token 内点验、GTM 容器真 push、后台 Dashboard 登录冒烟 — 需配置 GTM-ID / 面板 Token 后补勾

## 证据摘要

```text
hasTrack=true hasGtmBridge=true hasDict=true currency=CNY
fakeSandbox=false forwarders=[gtm,ga4]
cta trigger: eventName=cta_click delivery=preview(GTM off)
audit summary: pages=6 complete=6 missing_marker=0 fetch_failed=0
```
