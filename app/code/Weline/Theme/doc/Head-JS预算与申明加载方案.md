# Head JS 预算与申明加载方案（计划）

> 目标：`<head>` 里只保留**框架启动 + 绘制关键**的白名单脚本；业务/主题/组件 JS 一律**申明加载**（`Weline.declare` / `Weline.load` / `Weline.mount` / `data-weline-*`），并用门禁固化，防止回退。

## 0. 基线（2026-10-22，`p05113ef3.test.weline.com:9555` 首页）

| 指标 | 现状 |
|---|---|
| `<head>` script 标签 | 52（服务端直出 26 / 运行时注入 26） |
| 服务端直出外链 | 20 |
| 其中框架启动依赖 | 4（runtime.js、dom-observer.js、weline-api-business.js、url-frontend.js） |
| 其中 `weline.js` | 1（defer） |
| 其中主题皮肤 JS | 8（`<theme:js defer>`，hanfu） |
| 其中主题/组件包 | 1 个 theme-head JS 包 + 5 个 widget-assets 包 |
| 其中 UI/预览 | `weline-ui.js`（module）、preview-bootstrap（module） |
| 运行时 async（申明加载） | 26（购物车/搜索/B2B/Compare/客服/货币/wishlist/像素等） |

默认主题（Theme 模块）：

- `view/theme/frontend/partials/head/*.phtml`：**0 个 `<script>`、0 个 `<theme:js>`**；
- `view/theme/backend/partials/head/default.phtml`：3 个，全是 UI 启动件（prepaint / css-ready / ui module）；
- 设计主题（hanfu/playinginchina/daocharms/grocery/daocharms-3d）：同样 2~3 个 UI 启动件 + 5~8 个 `<theme:js defer>` 皮肤 JS。

结论：**业务 JS 已是申明加载**；待收敛的是「主题皮肤 JS、组件包、框架启动依赖」，以及「缺门禁」。

## 1. 契约（先定死，再迁移）

### 1.1 Head 可直出可执行 JS 白名单

| # | 脚本 | 理由 | 终态 |
|---|---|---|---|
| 1 | 内联 boot stub | 必须早于 defer 脚本，排队 declare/load/mount | 保留（内联，非外链） |
| 2 | `weline.js` | 加载器本体 | 保留 |
| 3 | `runtime.js` | weline.js 启动依赖 | P3 合并进 weline.js |
| 4 | `dom-observer.js` | Mutation 总线 | P3 合并 |
| 5 | `weline-api-business.js` | API 业务封装 | P3 合并 |
| 6 | `url-frontend.js` | URL 工具 | P3 合并 |
| 7 | `weline-theme-prepaint.js` | 防 FOUC，必须 pre-paint | 保留（阻塞） |
| 8 | `weline-css-ready.js` | CSS 就绪探测 | 保留（defer） |
| 9 | `weline-ui.js` | UI 运行时（module，天然非阻塞） | 保留 |

`application/ld+json`、`application/json`（runtime config）不计入 JS 预算。

**目标**：服务端直出外链从 20 → **≤6**（P1/P2 后），P3 后再 → **≤5**。

### 1.2 申明加载规则

- 业务/组件 JS：`Weline.load('module')` / `Weline.preLoad` / `Weline.mount`，或 `data-weline-load` 属性；
- 主题 JS：`<theme:js>` 增加 `mode="load"`（DOM ready 后由加载器插入），默认行为保持兼容；
- 第三方（gtag/pixel）：consent 通过且首屏交互后加载；
- 禁止：主题 head 局部新增裸 `<script src>`（白名单除外）、业务 JS `async` 直出。

## 2. 阶段与任务

### P0 门禁（先立规矩）

| 任务 | 文件 | 验收 |
|---|---|---|
| P0-1 静态门禁 | 新增 `Theme/Test/Unit/View/ThemeHeadScriptBudgetTest.php` | 扫描 `Theme/view/theme/{frontend,backend}/partials/head/**` 与 `app/design/**/frontend/partials/head/**`：外链 `<script src>` 仅允许白名单（prepaint/css-ready/ui），其余失败 |
| P0-2 运行时预算 | 新增 `tests/e2e/head-budget.spec.js` | 打开首页/购物车/商品页：`document.head` 服务端直出外链 ≤6，且不含业务 JS；输出计数到报告 |
| P0-3 规范 | `Theme/doc/静态资源版本契约.md` 增补「Head JS 预算」章节 | 文档评审通过 |

验收命令：

```bash
vendor/bin/phpunit --bootstrap app/bootstrap_phpunit.php --no-coverage \
  app/code/Weline/Theme/test/Unit/View/ThemeHeadScriptBudgetTest.php
cd tests/e2e && npx playwright test head-budget --project=chromium
```

### P1 主题皮肤 JS 声明化（收益最大、风险最低）

范围（按站点启用顺序）：hanfu(8) → playinginchina(5) → daocharms(8) → grocery/daocharms-3d。

| 任务 | 改动 | 验收 |
|---|---|---|
| P1-1 | `<theme:js data-order="62" defer>` → `mode="load"`（DOM ready 后加载）或改 `Weline.load` | 首页 head 外链 −8；skip-link/锚点/CTA/trust-strip/产品 docks 功能回归 |
| P1-2 | 同上，其余设计主题 | 各主题 head 外链 −5~8；功能 smoke |
| P1-3 | 组件包按需：5 个 widget-assets 包改 `Weline.load` | head 外链 −5；组件（迷你车/搜索/货币/wishlist）交互 smoke |

指标：LCP 不退化 >5%，DOMContentLoaded 不退化，控制台 0 error。

### P2 框架启动依赖合并

| 任务 | 改动 | 验收 |
|---|---|---|
| P2-1 | 将 runtime.js / dom-observer.js / weline-api-business.js / url-frontend.js 合并进 `weline.js`（或由 weline.js 内部顺序加载） | head 外链 −4；`Weline.declare/load/mount` 启动时序不回退；`weline-boot-stub` 排队回放正常 |
| P2-2 | 更新 `Frontend/view/blocks/header/base.phtml` | 首页 head 外链 ≤5 |

风险：weline.js 体积增大（需 gzip/brotli 评估）；首屏解析时间；合并后缓存粒度变粗。

### P3 预览/分析与同意门控

| 任务 | 改动 | 验收 |
|---|---|---|
| P3-1 | `preview-bootstrap.js` 仅预览模式输出 | 常规访问 head 不含该 module |
| P3-2 | gtag/pixel consent 门控 | 未同意前不发请求；同意后加载 |

### P4 收口与看板

- 记录三指标：head 外链数、head JS 字节、LCP p75；
- 将阈值写入 P0 门禁；
- 每次发布后跑 P0 门禁 + 页面 smoke。

## 3. 风险与回滚

| 风险 | 处置 |
|---|---|
| 执行顺序被破坏（组件依赖全局） | P1 逐主题灰度；每批只迁 1 个主题，保留 `defer` 回退开关 |
| 首屏交互延迟（搜索/迷你车） | 这两类保持优先 `preLoad`，DOM ready 前完成 |
| FOUC | prepaint 仍留在 head，不动 |
| CSP nonce | 申明加载器插入 script 时透传 nonce |
| FPC/浏览器缓存旧 HTML | 发布后 purge HTML disk + `server:reload`；`static_generation` 已入 FPC 键 |
| `app/design` 不在本仓 | 设计主题改动需同步到主题包仓库；门禁只扫描本仓可管部分 |

## 4. 进度（2026-10-22）

| 阶段 | 状态 | 说明 |
|---|---|---|
| P0 门禁 | ✅ | 新增 `Theme/Test/Unit/View/ThemeHeadScriptBudgetTest.php`：默认主题 + 设计主题 head 只允许 prepaint/css-ready/ui 直出；默认主题 `<theme:js>` 禁止 `defer` |
| P1-1 hanfu | ✅ | `<theme:js>` 新增 `load` 声明模式（输出 `data-weline-theme-js` 标记）；`weline.js` / `theme.js` 在 DOM ready 后按 `data-order` 插入；hanfu 8 个皮肤 JS 已迁移 |
| P1-2 其它设计主题 | ✅ | 各设计主题 head 已无 `<theme:js defer>`；playinginchina / daocharms-3d 的 head 直出业务 JS 已改声明标记；门禁已覆盖设计主题 |
| P2 widget 包 | ✅ | `WidgetAssetOptimizer::publishBatch()`：head 内 widget JS 改声明标记；运行时包 `data-order=20`、业务包 `30`（运行时按发布内容识别），DOM ready 后按序插入 |
| P3 框架依赖合并 | ✅ | 新增 `Framework/Deploy/WelineCoreBundle`：把 runtime.js / dom-observer.js / weline-api-business.js / url-frontend.js 合并为 `weline-core.js`；生产 header 只加载 1 个（DEV 仍逐文件）；deploy 生成、构建树版本化 |

**实测（`p05113ef3.test.weline.com:9555`，2026-10-22 03:00）**

| 页面 | head 服务端直出外链 | 声明标记 | 运行时验证 |
|---|---|---|---|
| 首页 | **20 → 5** | 13（8 皮肤 + 5 widget） | 5 个 widget 包 + 8 个皮肤 JS 全部加载，`WelineWidgetAssets` 初始化，0 个 4xx / 0 console error |
| 购物车 | — | 11 | 3 个 widget 包加载，0 个 4xx |

- 门禁：`ThemeHeadScriptBudgetTest` 2/2、`ThemeStaticVersionContractTest` 4/4 通过；
- 最终 head 外链 5 个 = `weline-core.js`（4 依赖合并） + `weline.js` + theme-head JS 包 + `weline-ui.js` + preview-bootstrap；
- 稳定性验证（首页/购物车/商品页）：`Weline.__initialized`、`Weline.dom.observe`、`Weline.Api`、购物车 API 全部正常，0 个 4xx、0 console error。

## 5. 下一步

1. P4：preview-bootstrap 仅预览模式输出；gtag/pixel 同意门控；
2. P5：指标看板（head 外链数 / head JS 字节 / LCP p75）写入门禁阈值；
3. 已知修复（本轮顺手）：`deploy:upgrade` 恢复 exit 0 —— purge 目标改回规范根、`pub/static/tu-*` 主题更新代次根不参与权限归一/构建哈希/版本树复制；`static_generation`（主题代次）与 `static_build`（部署构建号）拆分为两个缓存维度。
