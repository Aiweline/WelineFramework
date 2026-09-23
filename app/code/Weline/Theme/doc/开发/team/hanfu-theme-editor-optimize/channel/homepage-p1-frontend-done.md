# homepage-p1 · Team:前端: 交付

日期：2026-09-23T02:05+08:00  
席位：`Team:前端:`  
SESSION：`app/code/Weline/Theme/doc/开发/session/hanfu-theme-editor-optimize.md`  
thread：`p1-mall-feel-build`  
agent_id：`hanfu-theme-editor-frontend-p1`

## result

`result=delivered`  
`notify_pm: true`  
`@项目经理：本席已交付/上报，请检查并更新 SESSION`

覆盖 UC：`UC-P1-03`（真 4 列）+ 协同 `UC-P1-01/03`（卡高/价签可见）。  
未改：发布主题、HotCache、色板、原生 fetch/ajax。

---

## 1. 根因（HF-ED-P1-03 / 运营 305×3）

**不是** JS 列计算，也不是卡 `min-width` 把 grid 挤爆。

运营所见 `products-grid columns-4` 但实渲 **3 列（≈305×3）** 的根因：

| 层 | 证据 |
|----|------|
| Theme 部件 CSS | `featured-products/default.phtml` 内 `@media (max-width: 992px)` **把 `.columns-4` 降为 `repeat(3, 1fr)`**（bestsellers 同构） |
| 编辑器 iframe | 设计宽常 1200 + `transform: scale`，**media query 仍看 iframe CSS 视口**；壳侧栏后视口常落在 **800–1100px** → 命中 992 断点 |
| 算术 | 容器 ≈950px ÷ 3 ≈ **305px** → 与顾问度量一致；第 4 卡换行 |

静态对照（viewport=900px，禁缓存 Chrome headless）：

- **修复后**：`grid-template-columns` = `213px ×4`，`sameRow=4`
- **旧 992→3 规则**：`columns-4` 被降为 3 列轨道

---

## 2. 修复（本席）

### 2a. Theme 公共货架部件（根因切断）

1. `app/code/Weline/Theme/view/theme/frontend/widgets/product/featured-products/default.phtml`  
   - `@media (max-width: 992px)` **不再**改写 `.columns-4`  
   - 仅 `.columns-5/.columns-6` → 3 列  
   - `.columns-4` 保持到 **768**，再降为 2 列  

2. `…/bestsellers/default.phtml`  
   - 同上（同源断点，防特价/畅销货架复现）

### 2b. hanfu design 协同卡高（与主题席脏改并存）

`app/design/Weline/hanfu/frontend/layouts/homepage/default.phtml`（featured-lead）：

- 主题席已落：`columns-4` grid 锁 + `padding-top: 68%` 媒体锁  
- 本席追加：标题压字号/`min-height:0`、**隐评分**、价签字号收紧、768 断点 2 列回落  
- 边界：未同 key 覆盖 `theme.css`/`theme.js`；色仍走 ink token

---

## 3. 验收说明

| 项 | 状态 |
|----|------|
| 静态 CSS 契约（900px iframe 模拟） | **PASS**：新规则 4 列一行 |
| theme_id=3 编辑器草稿实机 | **本回合阻塞**：本机 PHP/WLS 高负载，`/admin/login` curl **超时**；Cursor Browser MCP 无可用 tab / Chrome DevTools MCP 连接超时。未伪称实机截图 PASS |
| 禁缓存 + 抹 webdriver | 静态/脚本侧已启用；实机待 PM/测试席复验时沿用 |

请 PM / 测试在负载回落后按主 URL 复验：

`https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/theme/backend/theme-editor?theme_id=3&page_type=homepage&layout_option=default&editor_area=frontend&preview_area=frontend&status=draft&interaction_mode=edit`

度量：`#homepage-featured .products-grid.columns-4` → `getComputedStyle` 列轨 ≥4，且 4 卡 `top` 同行；价签可见。

---

## 4. 边界 / 冲突（channel）

- 主题席并行改了同一 `hanfu/.../homepage/default.phtml`（chrome/Hero/精选 grid 锁）。本席 **dirty-load** 后只追加卡高协同，未回滚主题侧。  
- 部件席若仍改 `columns`/`limit` 配置：本席 CSS 以 class `columns-4` 为准，无需再造 JS 列算。  
- `new-arrivals` 仍有 `@media (max-width: 980px) → 2 列`（本波精选主伤不在此）；若顾问复审点名再开单。

## 5. paths_changed

- `app/code/Weline/Theme/view/theme/frontend/widgets/product/featured-products/default.phtml`
- `app/code/Weline/Theme/view/theme/frontend/widgets/product/bestsellers/default.phtml`
- `app/design/Weline/hanfu/frontend/layouts/homepage/default.phtml`（协同卡高；与主题席并行）

Browser 本席：**未留下** Cursor 验收 webview（MCP 未能稳定开页；无 tab 可关）。N/A close。
