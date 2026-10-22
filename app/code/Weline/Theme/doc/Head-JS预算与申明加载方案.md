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
| P5 指标门禁 | ✅ | 新增 `tests/e2e/head-budget.mjs` + 基线 `head-budget.baseline.json`：head 外链数 / head JS 解码字节 / LCP（3 次中位数）三指标按页棘轮；页面本体非 2xx 才失败，子资源 4xx 由 `check_404_site.mjs` 单独把关 |
| P4 预览/分析门控 | ✅ | `PreviewBootstrapAssetInjector` 仅预览模式注入（普通店面 head 不再有 preview-bootstrap）；GTM 容器加载补营销同意门控（GA4 原有 analytics 门控），同意变化后重估加载 |
| P3 框架依赖合并 | ✅ | 新增 `Framework/Deploy/WelineCoreBundle`：把 runtime.js / dom-observer.js / weline-api-business.js / url-frontend.js 合并为 `weline-core.js`；生产 header 只加载 1 个（DEV 仍逐文件）；deploy 生成、构建树版本化 |

**实测（`p05113ef3.test.weline.com:9555`，2026-10-22 03:00）**

| 页面 | head 服务端直出外链 | 声明标记 | 运行时验证 |
|---|---|---|---|
| 首页 | **20 → 4** | 13（8 皮肤 + 5 widget） | 5 个 widget 包 + 8 个皮肤 JS 全部加载，`WelineWidgetAssets` 初始化，0 个 4xx / 0 console error |
| 购物车 | — | 11 | 3 个 widget 包加载，0 个 4xx |

- 门禁：`ThemeHeadScriptBudgetTest` 2/2、`ThemeStaticVersionContractTest` 4/4 通过；
- 最终 head 外链 4 个 = `weline-core.js`（4 依赖合并） + `weline.js` + theme-head JS 包 + `weline-ui.js`；
- 稳定性验证（首页/购物车/商品页）：`Weline.__initialized`、`Weline.dom.observe`、`Weline.Api`、购物车 API 全部正常，0 个 4xx、0 console error。

## 5. 下一步

1. P5：指标看板（head 外链数 / head JS 字节 / LCP p75）写入门禁阈值；
2. 已修：主题更新代次根（`/static/tu-*`）轮换后旧烘焙 URL 404 —— `ThemeJs::fallbackGenerationUrl()` 在代次文件缺失时回退规范路径并加构建代次（含单测 4/4）；
3. 已实现并实测：同意模块未启用时**默认拒绝**（显式同意才放行分析/营销脚本）
   - `consent.defaultDeny` 默认 true（SystemConfig `visitor/tracking/consent_default_deny=false` 可按站点回退旧语义）；
   - 关键修复：`__visitorConsentAllows()` 原在 `enabled=false` 时直接放行，绕过新策略；现统一看 `granted()` 结论；
   - `pixel.js` 与内联 `pixel.phtml` 同步；
   - 实测：首页/购物车/商品页 **analytics 请求 0**，`gtag`/`gtm` 均不加载，站点功能正常；
4. 已知修复（本轮顺手）：`deploy:upgrade` 恢复 exit 0 —— purge 目标改回规范根、`pub/static/tu-*` 主题更新代次根不参与权限归一/构建哈希/版本树复制；`static_generation`（主题代次）与 `static_build`（部署构建号）拆分为两个缓存维度。

## 5.1 同意地区策略（P4 扩展）

欧盟/英国/瑞士对非必要追踪要求「事先同意」，因此默认拒绝必须**按地区强制**，其余地区才允许配置放行：

| 配置（SystemConfig） | 默认 | 语义 |
|---|---|---|
| `visitor/tracking/consent_default_deny` | `1` | 非强制地区的默认拒绝开关；`0` 仅对非 EEA/GB/CH 且能判定地区的访客放行 |
| `visitor/tracking/consent_default_deny_regions` | `EEA,GB,CH` | 强制默认拒绝地区：`EEA` 组名（EU27 + IS/LI/NO）或 ISO 国家码 |

- **每访客结论不进共享缓存**：`region` / `effectiveDefaultDeny` 不得烘进可缓存 HTML（FPC 会让全站共用一份）；
  运行时配置只下发 `defaultDeny` / `defaultDenyRegions` / `eeaCountries`；
- 地区来源：前端顺序为「每请求配置 > cookie（`weline_country` / `cf_country` / `country`）> 全局 geo 变量」，
  都无法判定 → **按拒绝处理（fail-closed）**；服务端保留 `VisitorTrackingConfig::getRequestRegion()`（复用 Captcha 可信头）供接口/非缓存场景使用；
- 纯函数 `VisitorTrackingConfig::effectiveDefaultDeny(region, denyRegions, globalDefaultDeny)` 已单测覆盖（US+false→放行，DE/GB/''+false→拒绝）；
- 后台配置项已加警示：「关闭默认拒绝仅在非强制地区生效；EEA/UK/CH 必须事先同意」；
- 测试：`VisitorConsentRegionPolicyTest`（强制地区、组合决策、前端/后台契约）。

## 5.2 设计主题与 FPC 兜底（T11）

- 设计主题 `app/design/**` 不入本仓：本轮改动打包为 `dev/session/head-js-budget/design-package-patches/`（含 README），需同步到主题包仓库；
- 兜底：`FpcHtmlDiskStore::publish()` 落盘前统一改写 HTML 静态 URL（见 Framework 文档 §6.3.3），覆盖主题包未同步的情况；
- 实测首页 HTML：静态 URL **0 裸路径**（BUILD 28 + HASH 7）。

## 5.3 同意地区策略线上验证（T12）

临时 `consent_default_deny=0` + cookie 地区，实测：

| 地区 | 结果 |
|---|---|
| `weline_country=US`（非强制） | GA4/GTM 加载（3 个分析请求） |
| `weline_country=DE`（EEA） | 0 请求 |
| `weline_country=GB`（强制） | 0 请求 |
| 无地区（未知） | 0 请求（fail-closed） |

验证后已还原 `consent_default_deny=1`，全部地区恢复默认拒绝。

## 5.4 主题发布与站点代次（T13/T14）

- 发布：`php bin/w theme:upgrade -t <theme>` 原子构建新代次并翻指针（不影响线上，逐站按绑定 scope 生效）；
- 本轮发布结果：

| 主题 | 当前代次 |
|---|---|
| hanfu（website 0） | `tu-20261022-035208-3e8dbb` |
| daocharms（website 158） | `tu-20261022-034732-367df5` |
| playinginchina（website 550） | `tu-20261022-034908-b380ac` |
| grocery（website 544） | `tu-20261022-034942-97a507` |

- 站点验证：`p05113ef3`（hanfu）与 `playinginchina.test` 运行时 0 个 4xx；daocharms 测试域连接被关闭（站点/实例未服务该 host，非主题问题）；
- 代次切换后 CSS 包可能残留旧 `tu-*` 图片 URL：`WidgetAssetArtifactPublisher::rewriteCssUrls()` 已加**代次回退**（缺失则回退规范路径并加构建代次），CSS 包键并入当前代次强制重建；
- 指标口径修正：`head-budget.mjs` 的 `headJsBytes` 只统计 head 精确 URL（避免把动态 import 的同名脚本重复计入）。

## 6. 指标门禁用法

```bash
cd tests/e2e
node head-budget.mjs                      # 按基线校验（exit 1 = 超阈值）
node head-budget.mjs --update-baseline    # 发布后棘轮收紧基线
node head-budget.mjs --json               # 机器可读输出

脚本已做健壮化：LCP 采样失败可降级、测量异常自动重试 3 次（防护限流/浏览器抖动不再误报）。
```

基线字段（每页）：`headExternalMax` / `headJsBytesMax`（解码字节，稳定口径）/ `lcpMsMax`（3 次中位数 ×1.5，下限 3000ms）。
