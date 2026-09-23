# homepage-p1 · Team:前端: 返工

日期：2026-09-23T13:05+08:00  
席位：`Team:前端:`  
对照：`channel/homepage-p1-theme-rework.md` · `channel/homepage-p1-widget-rework.md` · `channel/p1-reverify-evidence.md`（containerW≈700 → 旧 `344px 344px`）  
`notify_pm: true`  
`@项目经理：本席已交付/上报，请检查并更新 SESSION`

## result

**`result=done`**（共享 Theme 货架 CSS 已同步 559 断点）  
未开 Browser / 未跑服务；禁项均守。请测试席禁缓存复测 UC-P1-03。

---

## 根因（dirty-load）

主题席已把布局内联 MQ `768→2` 改为 `560→2`；部件席已修 `widget-hanfu-product-featured-products-default.css`。  
但 **Theme 模块共享 CSS**（非 hanfu 皮）仍有中宽档把 `.columns-4` 打成 2/3，且 `featured-products` 在 `max-width:768` 用 `!important` 压任意 `.products-grid`——会压过布局内联，编辑器 iframe ≈700px 仍实渲 2 列。

| 文件 | 旧行为 | 风险 |
|------|--------|------|
| `widget-product-featured-products-default.css` | `≤768` 全 grid → 2 `!important` | **主因**：压过布局 |
| `widget-product-bestsellers-default.css` | `≤992` 5/6→3；`≤768` 含 columns-4→2 | 同页货架回潮 |
| `widget-product-new-arrivals-default.css` | `≤980` columns-4→2 | 同族回潮 |
| `widget-product-related-products-default.css` | `≤768` 含 columns-4→2 | 同族回潮 |
| `widget-product-up-sell-default.css` | `≤980` columns-4→2 | 同族回潮 |
| `widget-hanfu-product-featured-products-default.css` | 部件席已对齐 559 | **已对齐免改** |
| `widget-product-deals-of-day-default.css` | 768 仅改 padding/header | **已对齐免改** |

---

## 改动契约

统一断点（与主题布局 / hanfu 精选皮一致）：

1. `≤992` / `≤768` / `≤980`：**强制保持** `.columns-4` → `repeat(4, minmax(0, 1fr))`（禁止降 3/2）
2. **仅** `max-width: 559px`：`.columns-4`（及 5/6）→ 2 列
3. 真窄屏 `≤480`：既有 1 列规则保留（bestsellers / new-arrivals / related / up-sell）

### paths_changed

- `app/code/Weline/Theme/view/statics/css/widgets/widget-product-featured-products-default.css`
- `app/code/Weline/Theme/view/statics/css/widgets/widget-product-bestsellers-default.css`
- `app/code/Weline/Theme/view/statics/css/widgets/widget-product-new-arrivals-default.css`
- `app/code/Weline/Theme/view/statics/css/widgets/widget-product-related-products-default.css`
- `app/code/Weline/Theme/view/statics/css/widgets/widget-product-up-sell-default.css`
- `app/code/Weline/Theme/doc/开发/team/hanfu-theme-editor-optimize/channel/homepage-p1-frontend-rework.md`

### 已对齐免改

- `widget-hanfu-product-featured-products-default.css`（部件席返工）
- `widget-product-deals-of-day-default.css`（无 columns-4 中宽降列）

---

## 自检（静态 · 无 server）

对上列 6 个 CSS 扫描：`max-width:768|992|980` 块内 **无** `columns-4 → repeat(2|3)`；均具备 `559` 断点。**PASS**。

未：`server:*` / `theme:active` / 发布 / 原生 fetch。

## escalate

### `Team:测试:`
theme_id=3 编辑器草稿 `#previewFrame`（禁缓存 + 抹 webdriver）复测 **UC-P1-03**：精选 `.products-grid.columns-4` 在 containerW≈700 时 `grid-template-columns` 为 **4** 段（非 `344px 344px`）。

### `Team:项目经理:`
本席范围 UC-P1-03 共享 CSS 已收口；UC-P1-01/02/04 仍按主题/部件返工口径由测试复测。

## stance

- `stance`：共享货架列断点与主题/部件 559 契约对齐；700px 预览不得再被模块 CSS `!important` 打成 2 列  
- `result=done`  
- `notify_pm: true`
