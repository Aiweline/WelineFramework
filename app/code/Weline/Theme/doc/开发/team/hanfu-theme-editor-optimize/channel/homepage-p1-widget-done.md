# channel — homepage-p1-widget-done

日期：2026-09-23  
席位：`Team:部件开发工程师:`  
SESSION：`app/code/Weline/Theme/doc/开发/session/hanfu-theme-editor-optimize.md`  
thread：`p1-mall-feel-build`  
kind：`done`  
`notify_pm: true`

---

## @项目经理：本席已交付/上报，请检查并更新 SESSION

本席完成 **HF-ED-P1-03 / HF-ED-P1-04**（协同 UC-P1-01 货架密度），未发布主题，未用 `/` 店面验收。

## 改动摘要

| UC | 处理 | 路径 |
|----|------|------|
| HF-ED-P1-03 | hanfu 覆盖 `featured-products`：`columns-4` 在 ≤992 仍强制 4 列（修编辑器 iframe 被打成 3 列）；卡 `min-width:0`；媒体区 `padding-top:68%` + 压 body/CTA 行高 | `app/design/Weline/hanfu/frontend/widgets/product/featured-products/default.phtml`（新增覆盖） |
| HF-ED-P1-04 | 活跃 slide 次行动「按场景选 / Shop by Occasion」由 outline 按钮改 **文字链** `slide-text-link`；主 CTA 仍唯一实心 `w-button-primary`；CSS 兼容旧 `slide-button-secondary` 类名 | `app/design/Weline/hanfu/frontend/widgets/banner/hero-slider/default.phtml` |

XOR：精选仍走布局内嵌 `<w:widget … featured-products>`，**未**新增 `default_injections`（禁双路径）。  
色板：未另造；服从 ink。  
外国业务模块：未代写。

## 翻译席知会（并行）

可见串文案未改字面（仍「浏览精选」「按场景选」），仅次行动呈现从 outline 按钮降为文字链。若翻译席需登记呈现变更，请据此对齐；本席未改模块 CSV。

## 验收证据（theme_id=3 编辑器草稿预览，禁缓存）

主验收 URL：  
https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/theme/backend/theme-editor?theme_id=3&page_type=homepage&layout_option=default&editor_area=frontend&preview_area=frontend&status=draft&interaction_mode=edit  

预览 HTML（`/?theme_id=3&frontend_theme_id=3&editor_mode=1&shell=theme-editor&status=draft…`，管理员会话 + `Cache-Control: no-cache`）：

| 项 | 结果 |
|----|------|
| 精选 `data-columns="4"` + `products-grid columns-4` | 有；货架内 **4** 张 `density-shelf` 卡 |
| CSS `repeat(4, minmax(0, 1fr)) !important` | 有；`@media (max-width:992px)` **不再**把 columns-4 打成 3 |
| 媒体压缩 `padding-top: 68%` | 有 |
| 活跃 slide：`data-hero-cta="primary"` ×1（`w-button-primary`） | PASS |
| 活跃 slide：`data-hero-cta="secondary"` ×1（`slide-text-link`，无 `w-button-outline`） | PASS |

Browser IDE / Chrome DevTools 本回合连接不稳（tab 瞬失 / MCP 超时）；以禁缓存预览 HTML + 部件 CSS 契约作部件席证据。请测试席/前端席过签时再量「列宽×4 ≤ 容器」。

## paths_changed

- `app/design/Weline/hanfu/frontend/widgets/banner/hero-slider/default.phtml`
- `app/design/Weline/hanfu/frontend/widgets/product/featured-products/default.phtml`（新增）
- `app/code/Weline/Theme/doc/开发/team/hanfu-theme-editor-optimize/channel/homepage-p1-widget-done.md`

## stance / result

- `stance`：部件席 P1-03/04 已交付，可进原型/UI/顾问复审链  
- `result=done`  
- `notify_pm: true`  
- `@项目经理：本席已交付/上报，请检查并更新 SESSION`
