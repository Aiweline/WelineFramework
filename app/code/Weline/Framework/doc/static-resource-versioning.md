# 静态资源版本约定

`@static(...)`、`<css>` 与 `<js>` 继续是模块静态资源的唯一模板入口。

## PROD 扁平部署（overlay 与 flat 双树）

`deploy:upgrade` 对每个活跃模块的 `view/statics`：**主题 overlay** 仍铺到 `pub/static/{theme.path}/…/view/statics/…`；同时**整树扁平**铺到 `pub/static/{Vendor}/{Module}/…`，与 PROD `Module::` / `resolveStaticPath`（无 theme、无 `view/statics` 段）对齐。既有 `deploy.flat_static.*` Provider 仅为可选补充/兼容，不再是防 404 主路径。

**正式入口**：`php bin/w deploy:mode:set prod`（内联 `Deploy\Upgrade` → `prod_after`）。日常生产变更须经 `setup:upgrade`（!DEV → `SetupUpgradeAfterDeployStatic`）或显式 `deploy:upgrade`；`core:update` / 仅改 env **不算**完成。Orchestrator 空 `POST_DEPLOY` 默认执行 `setup:upgrade`。

## 呈现世代 / FPC（C-NS · C-STAMP · C-HELPER）

| 概念 | 权威 | 说明 |
|------|------|------|
| `deploy_version` stamp | `var/deploy/current.json` | 缺文件或字面 `dev` 为缺陷态；切 prod / release 须写出可观测非 sticky-dev 值 |
| FPC fingerprint ns | `global/storefront/deploy` | 进 `StorefrontCacheKeyContextResolver`；与 `theme_static_version`、mtime `assetVersion` 职责分离 |
| 失效 helper | `DeployFpcInvalidation` | Mode\Set prod：**bump + 必须 purge_fpc_all**；日常 Upgrade：**优先 bump**，空跑勿 thrash 全清 |
| 事件场 `prod_after.deploy_version` | Deploy 模块 `register.php` 版本 | **≠** stamp；供 Visitor 等旁路，不作店面呈现权威 |

## Taglib callback 例外（常见 404 坑）

`@static(...)` **只在 `.phtml` 编译期 AST 中解析**。Taglib `callback()` / `runtime_callback()` 返回的 HTML 字符串**不会**二次解析其中的 `@static`；裸写会导致浏览器请求 `.../@static(Module::css/foo.css)` 并 404。

- **禁止**：callback 返回 `'<link href="@static(Weline_X::css/a.css)">'`
- **必须**：`Template::fetchTagSource(DataInterface::dir_type_STATICS, 'Weline_X::css/a.css')`（范例：`Weline\I18n\Taglib\Local::resolveModuleStaticUrl`）
- **权威**：`app/code/Weline/Taglib/doc/如何自定义Tag.md` §静态资源

- 开发环境在未配置 `theme_static_version` / `theme.static_version` 时，框架按资源文件内容生成稳定的 `dev_*` 指纹并附加到 URL。
- 文件内容变化后 URL 同步变化，普通页面刷新即可取得新的 CSS/JS；内容不变时 URL 保持稳定，不制造随机请求。
- 生产环境不在请求期计算文件哈希，继续使用顶层 `theme_static_version`（权威）或兼容的 `theme.static_version` / 资源编译清单。注意：`theme` 会被主题元数据整表刷新，静态版本必须写顶层键。
- 模块不得自行拼接时间戳、随机数或硬编码版本参数，也不得改写为内联脚本来规避缓存。
