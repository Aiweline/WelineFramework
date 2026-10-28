# 静态资源版本约定

`@static(...)`、`<css>` 与 `<js>` 继续是模块静态资源的唯一模板入口。

## 目标契约：单权威树 + `?v=` 纯 query 破缓存（flag `dev.static_flat_urls`）

**契约（用户裁定，2026-10-28）**：css/js/图片/字体在磁盘上只有**一份权威树** `pub/static/{稳定路径}`；主题更新 = 原地覆盖新字节；`?v=` 只是浏览器/CDN 的破缓存令牌（**纯 query，不参与磁盘查找**）；任何曾出现在已发出 HTML 里的路径，不得因发布而 404。

开关：`Env::dev('static_flat_urls')`（env.php `dev` 段），判定权威是
`StaticBuildId::flatUrlsEnabled()` / `isFlatMode()`（测试可用 `setFlatUrlsOverride()` 注入）。
**flag 即回滚手段**：关闭后恢复路径段世代机制（磁盘布局向前兼容，旧代码仍可读 flat 树）。

flag 开启后的行为收口：

| 环节 | 权威 | flat 行为 |
|------|------|-----------|
| URL 生成 | `StaticBuildId::versionedPath()` → `stablePath()` | 只输出 `/static/{稳定路径}`，不再注入 `{12hex}` / `tu-*` / `{code}/v{n}/{deploy}` 路径段；存量版本化路径幂等剥回 flat |
| HTML 出口 | `StaticAssetHtmlRewriter::normalizeToFlat()` | 占位符解成 flat；DB complicate / 编译模板里残留的版本化 URL 统一剥回 flat |
| `?v=` 值 | `TraitTemplate` → `theme_static_versions` map | **不变**（这条链本就符合契约） |
| 主题 promote | `ThemeUpdateBatchService::mirrorThemeStaticToDeploy()` | 原地覆盖 flat `pub/static/{ns}/...`，不写 `{code}/v{n}/{deployId}`，不删兄弟版本目录，不补代次目录 |
| 整站 swap | `DeployStagingSession::overlayStaticFlat()` | 静态树逐文件同目录原子替换（link/copy→rename），不整树 rename，任一时刻被引用路径都有可读字节；legacy 世代目录不参与覆盖也不删除 |
| 构建发布 | `StaticBuildPublisher::publish()` | 不再制造 `{12hex}` 整树副本；`.build.json` 仍写，仅作缓存键维度 |
| GC | `StaticBuildGc::prune()` | **冻结令**：旧世代目录一律不删（只清 `.tmp-*` 中断产物）；磁盘回收留待人工 |

前端 `weline.js resolveStaticPath()` 与 `header/base.phtml` 的 `staticBaseUrl` 在 flat 模式下固定 `/static`。

> legacy（flag 关闭）机制保留在下文「静态产物跨发布保留」一节，仍是未开 flag 站点（含本地默认）的现行链路。

## PROD 扁平部署（overlay 与 flat 双树）

`deploy:upgrade` 对每个活跃模块的 `view/statics`：**主题 overlay** 仍铺到 `pub/static/{theme.path}/…/view/statics/…`；同时**整树扁平**铺到 `pub/static/{Vendor}/{Module}/…`，与 PROD `Module::` / `resolveStaticPath`（无 theme、无 `view/statics` 段）对齐。既有 `deploy.flat_static.*` Provider 仅为可选补充/兼容，不再是防 404 主路径。

**模块静态主 URL（硬）**：`@static` / `<css>` / `<js>` / `TraitTemplate::fetchTagSource(statics)` / `PublicStaticUrl` 在 prod/staging 下**必须**输出扁平 `/static/{Vendor}/{Module}/{rest}`。主题命名空间路径只服务**主题设计资源**（`dir_type_THEME`、colors、design assets）与 overlay 落盘兼容，**不得**再作为模块 `view/statics` 的主公开前缀。

**正式入口**：`php bin/w deploy:mode:set prod`（内联 `Deploy\Upgrade` → `prod_after`）。日常**代码/模块静态**生产变更须经 `setup:upgrade`（!DEV → `SetupUpgradeAfterDeployStatic`）或显式 `deploy:upgrade`；`core:update` / 仅改 env **不算**完成。Orchestrator 空 `POST_DEPLOY` 默认执行 `setup:upgrade`。**主题内容/版本发布不走这条全站静态管道**，见下文「发版闭环 B」。

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
| 模块 `view/statics` 公开 URL | `PublicStaticUrl`（`TraitTemplate::buildModuleStaticPublicUrl`） |
| 主题设计资源 URL / overlay 前缀 | `TraitTemplate::resolvePublicThemeNamespace()` → `PublicThemeNamespace` |
| 模块 `view/statics` / `view/theme` 发布目标 | `Deploy\Upgrade::execute`（flat + overlay 双写） |
| 按请求即时补发 `/static` 资源 | `ThemeResourceGateway::publishForRequestPath` |
| 主题静态命名空间解析 | `ThemeStaticNamespaceService::resolvePublicThemePath`（委托 `tryResolve`） |
| 设计覆盖搬迁 | `theme:upgrade`（`Theme\Console\Theme\Upgrade`） |

**前后台双激活（硬）**：省略 `-t` 时，`theme:upgrade` 必须发布 **frontend + backend** 各自激活主题的静态命名空间（同主题去重）。仅发前台（如 `hanfu`）会导致后台 Default 命名空间 `/static/Weline/Theme/view/theme/...`（如 `backend/colors/_light.css`）在 PROD / 本地 NG 网关下 404（`X-WLS-Static-Missing: fastpath`）。指定 `-t` 仍只发该主题。

**设计主题 area 资源双写（硬）**：`app/design/{Vendor}/{theme}/{area}/assets/...` 经设计覆盖搬迁会落到 `/static/{ns}/{area}/assets/...`，但 `<theme:js|css>Weline_Theme::theme/{area}/...</theme:...>` 生成的 URL 是 `/static/{ns}/Weline/Theme/view/theme/{area}/...`。二者必须同时存在：`theme:upgrade` 在模块 `view/theme` 发布之后，再把设计主题 `{area}/**` 运行时资源经 `ThemeResourceGateway` 双写到 Theme 模块路径。PROD / NG / WLS **缺文件快路径直接 404**，不会延迟补发——只发相对设计根路径、不双写 Theme 路径，就会出现 `hanfu-product-docks.js` 一类店面 404。

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
不过服务侧已按同一口径拒绝（见下节），因此**即使历史产物仍物理存在也取不到** —— 清理只是收敛磁盘，不再是安全前提。

## 服务侧统一口径（静态公共面）

`pub/static` 在 Web 根之下，同一份「什么能作为静态字节对外提供」的规则曾散落四处且强度不一，
导致真实泄露。唯一权威是 **`Weline\Framework\Deploy\StaticPublicSurface`**（与上面的
`StaticPublishExclusion` 同源，二者不得各自演进）。

### 事故与根因

实测（**纯 WLS、不经 nginx**）：`GET /static/Weline/Frontend/view/statics/_probe.php`
→ `HTTP 200` `Content-Type: text/x-php`，PHP 源码原文回吐。

根因不是「某处规则写错了」，而是**分层语义错误**：

| # | 位置 | 强度 | 实际行为 |
|---|------|------|----------|
| 1 | `Server/bin/worker*.php` `handleStaticFile()` 的 `$staticExtensions` | 白名单，**正确** | 不匹配时 `return null` —— 本意是「我不接管」 |
| 2 | `Router\Core::StaticFile()`（回退层） | **无策略** | 唯一守卫是「路径含 `view`」，随后 `file_get_contents()` 原样回吐 |
| 3 | nginx 站点配置 | 残缺 | `^~` 前缀 location **跳过所有正则** deny；`/s/{version}/` 曾零过滤 |
| 4 | `StaticPublishExclusion`（发布侧） | 排除规则 | 只管「什么不该被铺进 `pub/static`」 |

**「不接管」被回退层当成了「允许」。**

### 架构原则

1. **「不接管」≠「允许」。** 快路径清单只是**性能优化**，不是安全边界；
   安全边界必须被**每一层**执行，且回退层的默认动作是**拒绝**。
2. **发布不了的东西，绝不可能是给人下载的东西。** 服务侧拒绝规则直接复用
   `StaticPublishExclusion`，两处共用同一套常量，无需第二份名单。
3. **默认拒绝（allowlist）**：对编译产物树（`static` / `s/<ver>`）再要求扩展名在
   `StaticPublicSurface::SERVABLE_EXTENSIONS` 内。

### 三类判定（勿混用）

| 判定 | 权威 | 语义 | 是否安全边界 |
|------|------|------|--------------|
| 拒绝规则 | `StaticPublishExclusion::isExcluded()` | 脚本/模板/清单/文档一律拒绝 | **是** |
| 允许规则 | `StaticPublicSurface::SERVABLE_EXTENSIONS` | 编译产物树的扩展名白名单 | **是**（纵深防御） |
| 快路径 | `StaticPublicSurface::FAST_PATH_EXTENSIONS` | WLS 传输层「值不值得直接回」 | **否**（⊆ 允许规则） |

### 消费点（都必须走权威）

| 消费点 | 位置 | 现状 |
|--------|------|------|
| 框架回退层（**纯 WLS / 无 nginx 时的执行点**） | `Router\Core::StaticFile()` → `StaticPublicSurface::isServableFile()` | 已接入 |
| WLS 传输层快路径 | `Server/bin/worker.php`、`worker_ssl.php` 的 `$staticExtensions` | 两份拷贝，须等于 `FAST_PATH_EXTENSIONS` |
| nginx | 仓库根 `nginx.static-surface.conf`，**由站点配置以字面绝对路径 include**（`include` 不支持变量，见下节） | 白名单由 `servableExtensionPattern()` 生成 |
| 发布侧 | `Deploy\Upgrade` / `theme:upgrade` / `ThemeResourceGateway` | 见上节 |
| 一致性 | `Framework/Test/Unit/Deploy/StaticPublicSurfaceContractTest` | 漂移即失败 |

### nginx 必须知道的规则（匹配 + 加载）

1. location 优先级：`=` > `^~`（最长前缀，**命中后跳过所有正则**）> 正则 `~`/`~*`
   （**按文件出现顺序，首个命中者胜**）> 普通前缀。
2. 因此只要站点配置里存在 `location ^~ /s/20260914/`，它就会绕过一切正则 deny，
   包括任何位置的 `location ~ \.php$ { deny all; }`。
3. **`include` 不支持变量**：`include $WELINE_ROOT/nginx.static-surface.conf;` 会被当成
   **字面路径**（`nginx -t` 报 `open() ".../$WELINE_ROOT/..." failed`）；相对路径又只按
   **主配置所在目录**解析（**不是**被包含文件的目录），同样不可移植。
   ⇒ 只能由**站点配置**（宝塔 vhost / `nginx.sample.conf` / `bt-nginx.conf`）用
   **字面绝对路径** include 本文件。
4. **正则里的 `{n}` 必须加双引号**：nginx 分词器把 `{` `}` 当**块定界符**，未加引号的
   `[0-9]{8}` 会让 `nginx -t` 报 `pcre2_compile() failed: missing closing parenthesis`。
   → 写成 `location ~ "^/s/[0-9]{8}/(?P<name>.*)$" {`。

> **硬要求**：站点配置不得自己手写静态 `^~` 块；静态面全部由 `nginx.static-surface.conf` 拥有，
> 并由站点配置以**字面绝对路径** include，位置必须在 PHP-FPM 的 `location ~ \.php$`
> （宝塔为 `include enable-php-*.conf;`）**之前** —— 否则 `/static/**.php` 会先被交给 PHP 执行。
> 若托管面板（宝塔等）强制生成 `^~`，必须把该文件末尾「`^~` 变体」的 `if` 原样贴进那个块内部
> （嵌套 `if` 在 `^~` 内部仍会被求值）。

### 影响面（实测，非估算）

对 `pub/static` 全量：白名单外的 **764** 个文件**全部**是构建残留
（`.scss` 600 / `.less` 70 / `.lock` 42 / `.hbs` 8 / `Makefile` / `.jshintrc` / `.sql` 等），
**无任何运行时资源**；且全仓无任何代码以 HTTP 方式取 `.scss` / `.less` / `.hbs`。
`.well-known/` / `media/` / `errors/` 只套拒绝规则、不套白名单（其中文件可能无扩展名）。

## 静态产物跨发布保留（rename-swap 不变量 · legacy，flag 关闭时生效）

> **flat 契约（`dev.static_flat_urls`=on）下本节整体退场**：单权威树原地逐文件覆盖天然满足
> 「旧引用永不 404」，`commitSwap` 不再调用 `carryForward`，GC 冻结旧世代目录。
> 以下内容描述未开 flag 站点的现行链路，并解释 carry/世代目录为何是路径段设计的症状补丁。

### 事故形态（必须记住）

`deploy:mode:set prod` 的 staging 管道把 `pub/static` **整树 rename**：

```text
pub/static  →  pub/static.prev.{stamp}  →  purgePrevAndResidue() 删除
staging/static  →  pub/static
```

内容哈希产物（`theme-head/{sha256}.css`、`widget-assets/{sha256}.css|js`）的 URL
**天然不可变**，但它的寿命此前等于「一次发布树」。于是：

1. 边缘仍按旧 TTL 发上一代 HTML，HTML 引用旧哈希；
2. 新发布 swap 后，旧哈希在源站 404；
3. 没有 Cloudflare purge 令牌时，旧 HTML 不会被替换，页面看起来像「静态文件丢了」。

**根因不是哈希机制，而是「不可变 URL 的寿命 < 引用它的 HTML 的寿命」。**

### 硬不变量

> **任何可被缓存 HTML 引用的静态字节，其可服务寿命必须 ≥ 该 HTML 的最大缓存寿命 + 余量。**

两类寿命必须一起配置，不能只靠运维记得 purge：

| 变量 | 权威配置 | 默认 | 作用 |
|------|----------|------|------|
| HTML 边缘 TTL | `wls.performance.fpc_edge_max_age_seconds` | `300` 秒 | Framework FPC HIT + 托管 Nginx html-disk HIT 共同封顶；`0` = CDN 不缓存 HTML |
| 静态保留窗口 | `wls.performance.static_carry_retention_days` | `400` 天 | `StaticCarryOver` 保留发布树中缺失的可服务文件；覆盖历史一年边缘 HTML |
| 源站 FPC policy TTL | `@Extra(type=fpc, ttl=…)` / 默认 | 一年 | 只决定源站 FPC 新鲜度，**不再直接等于边缘 TTL** |

默认 300 秒意味着：即使 CDN purge 完全失败，主题发版最多 5 分钟后也会自愈。
若把边缘 TTL 调大，必须同时保证保留窗口更长，并保证 purge 能力可用。

Cloudflare 侧若存在**强制 HTML Edge TTL 的自定义 Cache Rule**，它会绕过源站
`CDN-Cache-Control`；必须改为 Respect origin（或删除该规则），否则源站封顶无效。

### `StaticCarryOver` 做什么

`Weline\Framework\Deploy\StaticCarryOver` 在 `DeployStagingSession::commitSwap()`
把 live rename 走之前执行：

1. 扫描 live `pub/static` 中**新 staging 不存在**的普通文件；
2. 只保留 `StaticPublicSurface::isServableUrlPath()` 允许的路径
   （`.php` / 构建残留 / 发布排除项一律不保留）；
3. 优先 `link()` 硬链接进 staging，失败才 `copy()`；新 staging 文件永远优先；
4. 把 tombstone 写入 `var/deploy/static-carry.json`，记录
   `carried_at / inode / size / mtime`；
5. `purgePrevAndResidue()` 成功后再按保留窗口 GC；若同名路径已被新树原生文件
   替换（inode/大小/mtime 变化），只丢弃 manifest 条目，**绝不删除新文件**。

因此发布仍是原子的（staging 完整后才 swap），但旧哈希不会随旧树消失。
`wls.performance.static_carry_retention_days=0` 可显式关闭携带；安全删除场景
应使用该开关或手工清理 tombstone，而不是依赖「删源文件 = 全站立即消失」。

**根因定位（2026-10 changanhanfu 事故复盘）**：carry 修的不是「需要携带」，而是
「版本被做成了 URL 路径段 → 磁盘多份世代副本 → 换树后旧 HTML 引用物理消失」。
flat 契约把版本收回纯 query 后，该症状类别整体消失；carry 仅保留给 legacy 站点续命。

### 发版闭环（runbook）

先分清两种发布，**不要把主题发版做成整站发布**。

#### A. 整站代码 / 静态重发（full deploy）

适用：模块 PHP、`pub/static` 全量重建、部署管道切换。

1. `php bin/w deploy:mode:set prod`
2. `php bin/w server:reload`
3. `php bin/w server:nginx:reload`（让 html-disk 的 `max-age=300` 生效）
4. `StaticCarryOver` 在 staging swap 时自动保留旧可服务文件；无需人工补静态文件。
5. 观察：`curl -I` 首页应见 `X-Weline-Deploy-Version` 与
   `CDN-Cache-Control: public, max-age=300`；旧哈希资源应仍为 200。

整站发布本来就换整棵 `pub/static`，允许一次全量边缘失效；但**日常主题版本更新不属于 A**。

#### B. 主题版本 / 主题内容发布（routine theme release）

适用：改主题 phtml、CSS/JS、布局、版本发布。

1. **后台主题编辑器点「发布」**（推荐）：Scoped publish 只 bump 当前 Scope 的
   Theme 世代，并只为受影响布局发 `impact.urls/slugs`；Changed 管道只清这些
   URL 的 FPC 和 CDN，不 `Purge Everything`。
2. **CLI 同步主题静态/布局**：

   ```bash
   php bin/w theme:upgrade -t <theme>   # 默认：按本次布局清 FPC/CDN URL
   ```

   `theme:upgrade` 默认把 `cache_invalidation=none` 交给固化切流，然后按主题前端
   布局调用 `ThemeStorefrontSlugFpcPublisher::purgeLayoutsForThemeTargets()`：
   - 只清受影响页面的 FPC 叶子；
   - 只对这些页面发 CDN URL purge（`impact.urls`）；
   - 只 bump 对应 scope 的 Theme 运行时世代；
   - **不**调用 `clearAllThemeRelatedCaches`，**不** `deploy:mode:set prod`，
     **不**要求 `Purge Everything`。
3. 静态资源是内容哈希；旧 HTML 引用的旧包仍在 `pub/static`。若旧包确实已被
   整站发布清掉，由 A 的 `StaticCarryOver` 兜底。
4. 观察：新 HTML 应引用新哈希；旧哈希仍 200；边缘最多 300 秒自然换页。

#### C. `theme:upgrade -f`：显式全量失效（仅故障恢复）

`-f` 是逃生门，不是日常主题发版路径。它会 `clearAllThemeRelatedCaches()`：
全主题命名空间、FPC/Shared FPC、CDN 全页清理，并 staging→warm→promote。
`cdn_full_page_purge` 失败时**非零退出**（已 promote 的产物不回滚）。
只有在「边缘必须立刻全量换掉」时才使用。

#### D. 历史遗留 / 本次事故

修复前已用一年 TTL 写入边缘的 HTML 无法被新代码缩短寿命。若旧 HTML 仍引用
已丢失的旧哈希，需在 Cloudflare 面板执行**一次** `Purge Everything`（或按 URL
purge 首页/分类页）。这是**一次性**处置，不是长期机制；后续主题发版走 B。

#### E. DEV / 预览

`Response` 出站统一把非生产 HTML 改成
`Cache-Control: private, no-store` + `CDN-Cache-Control: no-store`，
防止 `/Weline/.../view/statics/...`、`_weline_dev=` 页面进入共享边缘缓存。

## 呈现世代 / FPC（C-NS · C-STAMP · C-HELPER）

| 概念 | 权威 | 说明 |
|------|------|------|
| `deploy_version` stamp | `var/deploy/current.json` | 缺文件或字面 `dev` 为缺陷态；切 prod / release 须写出可观测非 sticky-dev 值 |
| FPC fingerprint ns | `global/storefront/deploy` | 进 `StorefrontCacheKeyContextResolver`；与 `theme_static_version`、mtime `assetVersion` 职责分离 |
| 失效 helper | `DeployFpcInvalidation` | Mode\Set prod：bump + purge_fpc_all；**正式 Release：零碰 FPC**（仅可观测 stamp）；日常 Upgrade：优先 bump，空跑勿 thrash 全清 |
| 主题静态戳 | `theme_static_versions` map | 键=`versionOwnerScope\|themeId\|themeVersionId`；运营发布只 bump 该版；旧全局 `theme_static_version` 仅无上下文回退 |
| 浏览器 HTML | FPC HIT `Cache-Control` | 短 `max-age` + **`must-revalidate`**；系统戳响应头 `X-Weline-Deploy-Version` 仅观测/静态破缓；**店面世代权威** = Scope 已发布 `themePublishedVersionId`；框架 `StorefrontGenerationOutbound` 种可读 Cookie `weline_storefront_gen` + `Vary: Cookie`（共享 FPC suppress 仍禁 HIT Set-Cookie）；CDN Cache-Key 须含该 Cookie 名；旧页不一致 → `weline.js` **提示刷新**（非默默硬刷） |
| 事件场 `prod_after.deploy_version` | Deploy 模块 `register.php` 版本 | **≠** stamp；供 Visitor 等旁路，不作店面呈现权威 |

**开发者维护店面世代 / 前端工作流（必读）**：[`../../Frontend/doc/架构/店面世代与前端工作流.md`](../../Frontend/doc/架构/店面世代与前端工作流.md)（含总图、维护场景表、回归最小集）。系统发版与主题发布必须分轴，禁止把 `deploy_version` 再接回店面 reload。

## Taglib callback 例外（常见 404 坑）

`@static(...)` **只在 `.phtml` 编译期 AST 中解析**。Taglib `callback()` / `runtime_callback()` 返回的 HTML 字符串**不会**二次解析其中的 `@static`；裸写会导致浏览器请求 `.../@static(Module::css/foo.css)` 并 404。

- **禁止**：callback 返回 `'<link href="@static(Weline_X::css/a.css)">'`
- **必须**：`Template::fetchTagSource(DataInterface::dir_type_STATICS, 'Weline_X::css/a.css')`（范例：`Weline\I18n\Taglib\Local::resolveModuleStaticUrl`）
- **权威**：`app/code/Weline/Taglib/doc/如何自定义Tag.md` §静态资源

- 开发环境在未配置 `theme_static_version` / `theme.static_version` 时，框架按资源文件内容生成稳定的 `dev_*` 指纹并附加到 URL。
- 文件内容变化后 URL 同步变化，普通页面刷新即可取得新的 CSS/JS；内容不变时 URL 保持稳定，不制造随机请求。
- 生产环境不在请求期计算文件哈希：优先 `theme_static_versions[{scope}|{themeId}|{themeVersionId}]`（当前 `ThemeApplicationContext`）；无上下文时回退旧顶层 `theme_static_version` / `theme.static_version`。运营发布禁止再 bump 全局单键。
- 模块不得自行拼接时间戳、随机数或硬编码版本参数，也不得改写为内联脚本来规避缓存。
