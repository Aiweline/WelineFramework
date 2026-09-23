# channel — homepage-p1-widget-rework

日期：2026-09-23  
席位：`Team:部件开发工程师:`  
依据：`channel/p1-reverify-evidence.md`（UC-P1-03 / UC-P1-04 FAIL）  
kind：`done`  
`notify_pm: true`

---

## @项目经理：本席已交付/上报，请检查并更新 SESSION

针对复测缺口 **UC-P1-03（700px 实渲 2 列）**、**UC-P1-04（活跃 slide 实心主 CTA=0）** 做部件侧返工。  
**未** `server:start/stop`、**未** `theme:active`、**未** 发布。

## 根因（对照实测）

| UC | 证据 | 根因 |
|----|------|------|
| UC-P1-03 | `columns-4` → `grid-template-columns: 344px 344px`，`containerW=700` | hanfu 精选 CSS 与布局 MQ 在 `max-width:768` 把 4 列打成 2；编辑器 iframe CSS 宽≈700 命中该档 |
| UC-P1-04 | `solid_ctas_active_slide=0`，`cta_texts=[]` | ① `.slide-content` 相对定位落在图下文流，CTA 几何落在 fold 外（hero.top≈515 / vh≈645）；② `max-width:768` 贴底对齐进一步压低 CTA；③ 入场 `opacity:0`+delay；④ 类名 `w-button-primary` 非 BEM，易被全局 `.w-button` 透明底覆盖 |

## 改动

### UC-P1-03 真 4 列（≈700 预览）

路径：`app/code/Weline/Theme/view/statics/css/widgets/widget-hanfu-product-featured-products-default.css`（hanfu 专用，不伤他主题）

- `columns-4`：`≤992` / `≤768` 均 **强制** `repeat(4, minmax(0, 1fr)) !important`（压过布局内联 MQ）
- 仅 `max-width:559px` 才降为 2 列
- 禁止「≥992 才 4 列、700 掉 2」回潮

### UC-P1-04 单实心主 CTA

路径：

- `app/design/Weline/hanfu/frontend/widgets/banner/hero-slider/default.phtml`
- `app/code/Weline/Theme/view/statics/css/widgets/widget-hanfu-banner-hero-slider-default.css`

| 项 | 处理 |
|----|------|
| 主 CTA | `slide-button w-button w-button--primary` + `data-hero-cta="primary"`；CSS `background-color: var(--weline-theme-primary) !important` |
| 次行动 | 保持 `slide-text-link` 文字链（禁 outline/实心） |
| 叠层 | `.slide-content` → `position:absolute; inset:0`（叠在媒体上，禁图下文流） |
| 对齐 | `align-items:flex-start`（含 ≤768）；禁贴底 |
| 入场 | 激活帧默认 `opacity:1`；动画 from≥0.92（禁全透明 delay 漏检） |
| 密度 | 紧缩 kicker/title/subtitle 间距，便于 fold 内露出主钮 |

XOR：仍走布局内嵌 widget，未加 `default_injections`。色板未另造。

## 自检契约（供测试席复测）

在 theme_id=3 编辑器草稿 `#previewFrame`（禁缓存）：

1. **UC-P1-03**：精选 `.products-grid.columns-4` 在 containerW≈700 时 `grid-template-columns` 为 **4** 段（非 `344px 344px`）
2. **UC-P1-04**：`.slide.active` 内可见实心主 CTA **恰好 1**；次行动为文字链、无第二实心钮

本席未再开 Browser / 未改 WLS。请 `Team:测试:` 按 `p1-reverify` 同口径复测。

## paths_changed

- `app/code/Weline/Theme/view/statics/css/widgets/widget-hanfu-product-featured-products-default.css`
- `app/code/Weline/Theme/view/statics/css/widgets/widget-hanfu-banner-hero-slider-default.css`
- `app/design/Weline/hanfu/frontend/widgets/banner/hero-slider/default.phtml`
- `app/code/Weline/Theme/doc/开发/team/hanfu-theme-editor-optimize/channel/homepage-p1-widget-rework.md`

## stance / result

- `stance`：P1-03/04 部件返工已落盘；UC-P1-01/02（chrome/特价上移）不在本席最小修范围，仍待主题/布局席
- `result=done`
- `notify_pm: true`
- `@项目经理：本席已交付/上报，请检查并更新 SESSION`
