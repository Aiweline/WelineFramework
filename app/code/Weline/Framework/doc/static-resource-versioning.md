# 静态资源版本约定

`@static(...)`、`<css>` 与 `<js>` 继续是模块静态资源的唯一模板入口。

## PROD 扁平部署（overlay 与 flat 双树）

`deploy:upgrade` 对每个活跃模块的 `view/statics`：**主题 overlay** 仍铺到 `pub/static/{theme.path}/…/view/statics/…`；同时**整树扁平**铺到 `pub/static/{Vendor}/{Module}/…`，与 PROD `Module::` / `resolveStaticPath`（无 theme、无 `view/statics` 段）对齐。既有 `deploy.flat_static.*` Provider 仅为可选补充/兼容，不再是防 404 主路径。

**正式入口**：`php bin/w deploy:mode:set prod`（内联 `Deploy\Upgrade` → `prod_after`）。日常生产变更须经 `setup:upgrade`（!DEV → `SetupUpgradeAfterDeployStatic`）或显式 `deploy:upgrade`；`core:update` / 仅改 env **不算**完成。Orchestrator 空 `POST_DEPLOY` 默认执行 `setup:upgrade`。

## 主题命名空间归一化（发布目标必须与 URL 同源）

`pub/static/{命名空间}/...` 与 `/static/{命名空间}/...` 的命名空间**必须来自同一个归一化函数**
`Weline\Framework\View\PublicThemeNamespace`。发布目录与 URL 前缀一旦分叉就是 404。

该类是**唯一权威**，提供两个入口：

- `resolve()` —— **永不返回空**；无法解析时回落默认命名空间。用于「必须得到一个可发布命名空间」的调用点（`Deploy\Upgrade`、`TraitTemplate`）。
- `tryResolve(): ?string` —— 无法解析时返回 `null`。用于需要区分「无法解析」的调用点（`ThemeStaticNamespaceService` 据此回退配置项、`theme:upgrade` 据此报错中止）。

`theme.path` 允许多种形态，归一化落点：

| `theme.path` 原值 | 归一化结果 |
|-------------------|-----------|
| `Weline/hanfu` | `Weline/hanfu`（相对自定义主题路径保持不变） |
| `Weline_Theme::view/theme` 模块标识 | `Weline/Theme/view/theme`（**展开**，保留主题身份） |
| `app/code/Weline/Theme/view/theme` 绝对路径 | `Weline/Theme/view/theme`（同上，保留主题身份） |
| `app/design/{Vendor}/{theme}` 绝对路径 | `{Vendor}/{theme}` |
| 项目内相对写法 `app/code/...` / `app/design/...` | 先补成项目内绝对路径，再按上两行处理 |
| 其它绝对路径 / 盘符路径 / `..` / `.` / 空段 / `::` / 空值 | 默认命名空间（`resolve()`）或 `null`（`tryResolve()`） |

**为什么模块标识要展开而不是回落默认**：`Vendor_Module::path` 命名的是**某个具体主题**；
若一律回落默认，两个不同主题会写进同一个 `pub/static/{默认}/...` 而互相覆盖。
对内置默认主题（`Weline_Theme::view/theme`）展开结果恰好等于默认命名空间，故该场景行为不变。

`Deploy\Upgrade` 的 overlay 与 `view/theme` 目标都经该函数解析；解析结果不是安全相对命名空间时
**跳过主题域发布并告警**（`pub/static/{Vendor}/{Module}/` 扁平树与命名空间无关，照常发布）。

> 事故背景：`Deploy\Upgrade` 曾直接使用未归一化的 `theme.path`。当它被自动安装写成绝对源码路径时，
> 绝对路径被当成目录段，`pub/static` 下长出 `Users/<name>/.../app/code/...`（291 MB）与
> `Weline_Theme::view/` 这类畸形树。

> 安全背景：`Theme\Service\ThemeStaticNamespaceService` 曾自持一份 `normalizePublicThemePath()`，
> 会把 `..` / `.` / `a//b` / `a::b` **原样返回**，使 `theme:upgrade` 能把文件铺到 `pub/static` 之外
> 或长出畸形目录树。现已**删除该实现，改为委托** `PublicThemeNamespace::tryResolve()`，
> 两处语义不再可能漂移。

**消费方（全部走同一权威）**：

| 调用点 | 入口 |
|--------|------|
| 模板静态资源 URL 前缀 | `TraitTemplate::resolvePublicThemeNamespace()` |
| 模块 `view/statics` / `view/theme` 发布目标 | `Deploy\Upgrade::execute` |
| 按请求即时补发 `/static` 资源 | `ThemeResourceGateway::publishForRequestPath` |
| 主题静态命名空间解析 | `ThemeStaticNamespaceService::resolvePublicThemePath`（委托 `tryResolve`） |
| 设计覆盖搬迁 | `theme:upgrade`（`Theme\Console\Theme\Upgrade`） |

## 发布排除（`pub/static` 只许含运行时资源）

`pub/static` 位于 Web 根之下，铺进去的文件浏览器可直接取到：文档会被读取，`*.php` 更会被执行
（`.phtml` / `.pht` / `.phar` 在 Apache / LiteSpeed 常见 `AddHandler` 配置下同样按 PHP 处理；
即便服务器不执行，`Router\Core::StaticFile()` 也会用 `file_get_contents()` 回吐源码原文）。
故所有发布链路都先按 `Weline\Framework\Deploy\StaticPublishExclusion` 过滤再落盘：

| 链路 | 入口 | 过滤点 |
|------|------|--------|
| 模块 `view/statics`（overlay + 扁平双写） | `Deploy\Upgrade::execute` | `recursiveCopy()` 以 `RecursiveCallbackFilterIterator` 剪枝 |
| `app/design/{theme}` 设计覆盖搬迁 | `theme:upgrade` | `Theme\Console\Theme\Upgrade::fetchThemeFiles()` |
| 按请求即时补发 | `ThemeResourceGateway::publishForRequestPath()` | 解析出 `relative_path` 后即判定（否则一次针对 `/static/{Vendor}/{Module}/php/connector.minimal.php` 的请求就能把 `view/statics/php/**` 重新铺回 Web 根） |

排除类别（权威常量在 `StaticPublishExclusion`）：

- **段**（任意层级，命中即剪整棵子树）：`.git` `.github` `.gitlab` `.circleci` `.husky` `.idea` `.vscode` `node_modules` `bower_components` `nuget` `doc` `docs` `documentation`；`test` `tests` `__tests__` `cypress` `e2e` `spec` `specs` **仅在用户命名空间之外**生效。
- **文件名**：`package.json` `package-lock.json` `yarn.lock` `pnpm-lock.yaml` `composer.json` `composer.lock` `bower.json` `Gruntfile.js` `gulpfile.js` `webpack.config.js` `rollup.config.js` `vite.config.js` `karma.conf.js` `jest.config.js` `cypress.json` `tsconfig.json`，`.eslintrc*` `.stylelintrc*` `.babelrc*` `.prettierrc*`，`.editorconfig` `.gitignore` `.npmignore` `.travis.yml` `.browserslistrc` 等；以及 **Web 服务器 / PHP 运行期配置**：`.htaccess` `.htpasswd` `.user.ini` `php.ini` `web.config`（落在 Web 根里能改变「谁能访问、什么会被执行」）。
- **文档主干**（忽略扩展名，含无扩展名的 `LICENSE`）：`readme` `changelog` `contributing` `license` `licence` `copying` `notice` `authors` `contributors` `code_of_conduct`。
- **文档扩展名**：`md` `markdown` `mdown` `rst` `adoc` `asciidoc`。刻意不含 `txt`：`robots.txt` 是合法运行时资源。
- **服务端可执行 / 模板源码扩展名**：`php` `phtml` `pht` `phps` `phar` `php3`–`php8`，以及 `php` 前缀变体（如 `.php-dist`）；`cgi` `fcgi` `pl` `py` `rb` `sh` `bash` `zsh`；`asp` `aspx` `jsp` `jspx` `shtml`。**只按文件判定，不剪同名目录**（`view/statics/php/` 目录本身可保留）。

**用户命名空间逃逸**：`layouts` / `partials` / `widgets` 下是布局与部件自命名目录，真实存在名为 `test` 的布局（`Theme/view/theme/frontend/layouts/test`），故 `test`/`spec` 等段在其内不套用，避免误伤运行时资源。注意逃逸只作用于**段规则**：该目录下的 `.phtml` **源码模板仍会被排除**，其 `.css` / `.js` 等运行时资源照常发布。

**边界**：排除只作用于**后续发布**，不会清理 `pub/static` 中已有的历史产物；清理须单独执行。

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
