# 静态资源版本与 FPC 代次方案

> 目标：任何一次静态资源发布，都不会让「旧 FPC HTML」或「浏览器长缓存」读到错版本、错字节的静态资源。
> 适用范围：`/static/**`、`/pub/media/**`、FPC 静态镜像 `generated/static/live/**`、主题内容寻址资产。

## 0. 为什么「给版本号」还不够

现状（2026-10-21 实测）：

- `/static/**` 由托管 Nginx 直接落盘返回，响应 `Cache-Control: public, max-age=31536000, immutable`；
  WLS Worker 静态响应同样是一年（`Server/bin/worker_ssl_event.php`），Framework 回退路由也是一年（`Router/Core.php::buildCacheHeaders`）。
- 模块静态是**同名覆盖**发布：`pub/static/Weline/Frontend/js/weline-api.js` 内容会变、URL 不变。
- 主题资产已经是**内容寻址**：`/static/Weline/<theme>/Weline/Theme/theme-head/<sha>.css`，URL 与字节天然绑定。

只加查询串版本（`?v=xxx`）有两个洞：

1. **旧 FPC HTML + 新静态**：旧 HTML 请求 `?v=OLD`，Nginx `try_files $uri` 忽略查询串，返回**新字节**；浏览器把它按 `OLD` 这个 URL 永久缓存 → URL 与内容不再一一对应，版本号变成谎话。
2. **一个全局版本盖不住全部文件**：`assetVersion` 现在只取 5 个前端文件的 mtime（`Frontend/view/blocks/header/base.phtml`），其它模块 JS/CSS 变了它不变，懒加载脚本的 URL 不变 → 老访客继续用旧文件。

结论：**不可变缓存只允许出现在「URL 由内容决定」的资源上**。版本号必须进路径（内容寻址），或服务器必须保留按版本隔离的副本。

## 0.1 方案 B（推荐落地形态）：版本化根目录 `/static/{buildId}/...`

用户提出的 `/static/{version}/<path>` 是对的，关键在 `{version}` 取什么、旧目录怎么留、HTML/FPC 怎么跟着换。

### 版本号取谁

| 候选 | 变化时机 | 问题 |
|---|---|---|
| 主题发布版本 `themePublishedVersionId` | 仅主题发布 | **模块 JS/CSS 改动不会换号**（本次 `weline-api.js` 的 bug 仍会发生） |
| 静态构建号 `buildId`（推荐） | 任何静态文件变化 | 一次改动全树换号，缓存命中率略降 |
| 每文件内容哈希 | 单文件变化 | 最省缓存，但模板/加载器都要查清单，改造面大 |

推荐 `buildId` = 发布时对「全部 (logicalPath, sha256) 排序后的列表」取 sha256 的前 12-16 位（或 `日期-短哈希`）。它是主题版本的超集：主题更新会变，模块静态更新也会变。

### 目录与 URL

```
pub/static/{buildId}/Weline/Frontend/js/weline-api.js
pub/static/{buildId}/Weline/<theme>/Weline/Theme/theme-head/<sha>.css
pub/static/<legacy 原路径>            ← 兼容旧 HTML / 第三方硬编码，保留但只给短缓存
```

- URL：`/static/{buildId}/...`，Nginx `root pub` + `try_files $uri` **无需改写**（多一段真实目录）。
- 旧 buildId 目录**不删**，保留 ≥ `max(FPC TTL, CDN TTL)`（建议 7~30 天），由 `deploy:static:gc` 清理。
- 发布用**硬链接**（`link()`，跨盘回退复制）从源码树建新目录，成本接近零；`pub/static/{buildId}` 建好后原子写 `pub/static/.build.json`。

### 长缓存策略（关键）

| URL | Cache-Control |
|---|---|
| `/static/{buildId}/...` | `public, max-age=31536000, immutable` |
| `/static/...`（legacy，无 buildId 段） | `public, max-age=0, must-revalidate` |

这样即使有页面/第三方仍引用旧路径，也只会协商 304，不会永久错字节。

### HTML 与 FPC

- `runtimeConfig.assetVersion = buildId`；`modulesBaseUrl` 也带 buildId。
- FPC 键并入 `static_generation = buildId`：静态一发布，旧 HTML 自然不再命中。
- 即使边缘节点还留旧 HTML，它引用的旧 buildId 目录仍在盘上 → 旧页面继续用旧资源，**自洽**，不会新旧混跑。

### 需要改的入口（已量化）

- **中央入口**：`Framework/View/PublicStaticUrl::fromDevWebPath()` / `fromModuleSource()` —— 模块静态 URL 的唯一权威，加 buildId 前缀即可覆盖大部分模板。
- **加载器**：`Frontend/view/statics/js/weline.js`、`Theme/.../theme.js`（`modulesBaseUrl` / `getScriptUrl`）；`header/base.phtml` 注入 buildId。
- **模板**：`.phtml` 里没有裸 `/static/` 字面量（已核查），都走 helper。
- **JS 内联 dev→prod 映射**：约 10 处 `(function(d){...'/static/'+m[1]+...})` 与 3 处硬编码 `/static/...css`；这些继续走 legacy 路径也能工作，建议改为读 `Weline.config.staticBase`，逐步迁移。
- **服务端**：`Server/Service/Edge/Nginx/ManagedNginxConfigWriter.php`（按 `$uri` 是否含 buildId 段决定长/短缓存）、`Server/bin/worker_ssl_event.php`、`Router/Core.php::buildCacheHeaders()`。
- **FPC**：`Framework/Cache/KeyBuilder.php` 增加 `static_generation`。
- **发布**：`Framework/Console/Console/Deploy/Upgrade.php::publishStaticTree()` 写版本目录 + legacy 树 + `.build.json`；新增 `deploy:static:gc`、`deploy:verify-assets`。

### 取舍

- 优点：一条规则覆盖全部静态；CSS 里的相对 `url()` 自动跟着版本目录走；模板不用逐文件查哈希；旧页面天然自洽。
- 代价：一次发布整树换号（缓存全失效）；磁盘多留 N 代（硬链接下很小）；`/static/` 目录层级多一段，需要审计所有「路径形状」判断（`StaticPublicSurface`、Worker 静态策略、FPC 镜像）。
- 与「每文件内容哈希」相比：实现简单、运行时零查表；代价是缓存粒度粗。当前发布节奏下推荐先上 buildId，后续需要再叠加每文件哈希。


## 1. 核心不变量（Invariants）

| # | 不变量 | 违反后果 |
|---|---|---|
| I1 | `immutable` 响应的 URL 必须内容寻址：文件名含内容 sha，或路径含 build id | 浏览器永久缓存错字节 |
| I2 | 已发布过的 URL 永不改变其字节（旧代文件至少保留 `max(FPC TTL, CDN TTL)`） | 旧 FPC/旧页面 404 或拿到新契约 |
| I3 | 页面 HTML 引用的每个静态 URL，都能在发布清单里查到且存在 | 白屏 / 半树 |
| I4 | FPC 键包含静态清单摘要（manifest digest） | 旧 HTML 与新静态混跑 |
| I5 | 发布顺序：先写新文件 → 再切清单 → 再 bump FPC 代次 → 最后延迟 GC | 中间态读到半套资源 |

## 2. 目标模型

### 2.1 静态发布清单（manifest）

发布时（`Framework/Console/Console/Deploy/Upgrade.php::publishStaticTree()`）为每个模块静态文件计算 `sha256`：

- 写入 `pub/static/.weline-static-manifest.json`：
  ```json
  {
    "digest": "<全部 (logicalPath,sha) 排序后的 sha256>",
    "built_at": 1792590000,
    "files": {
      "Weline/Frontend/js/weline-api.js": {"sha": "ab12…", "path": "Weline/Frontend/js/weline-api.ab12cd34ef56.js"}
    }
  }
  ```
- 同时生成内容寻址副本（硬链接优先，跨盘回退复制）。原同名文件保留（兼容旧 HTML）。
- `digest` 即页面代次：静态树任何变化都会改变它。

### 2.2 URL 解析统一走清单

- 新增 `Framework/Deploy/StaticAssetManifest`：`resolve(string $publicPath): string`、`digest(): string`。
- `PublicStaticUrl::fromDevWebPath()`、模块加载器 `getScriptUrl()`、模板里的 `<link>/<script>` 资产标签全部经它解析。
- 解析结果：`/static/Weline/Frontend/js/weline-api.ab12cd34ef56.js`（无查询串）。
- 清单缺失（老部署）→ 退回原路径 + `?v=<deployVersion>`，但**不得**发送 `immutable`（见 3.1）。

### 2.3 页面代次与 FPC

- `runtimeConfig.assetVersion = manifest.digest`（取代现在的 5 文件 mtime 拼接）。
- `KeyBuilder::storefrontDimensions()` 增加 `static_generation = manifest.digest`，FPC/页面缓存键随之变化。
- 发布后旧 HTML 自然失效；即使某个边缘节点还留旧 HTML，它引用的旧内容寻址文件仍在盘上（I2），不会读错。

## 3. 安全网（防回归）

### 3.1 服务端：长缓存只给内容寻址 URL

三处统一判定，`immutable/一年` 仅在满足 I1 时发送，否则 `public, max-age=0, must-revalidate`：

1. `Server/Service/Edge/Nginx/ManagedNginxConfigWriter.php`：用 `map` 判定
   - `$uri` 命中 `\.[0-9a-f]{12,}\.(css|js|mjs|map|svg|json|xml|txt|woff2?|png|jpg|webp|avif|ico)$` → 长缓存；
   - 或 `$uri` 命中 `/static/[^/]*build[^/]*/` 形式的 build 目录 → 长缓存；
   - 其余 → 短缓存。
2. `Server/bin/worker_ssl_event.php`（WLS 静态响应）：同一规则，PHP 侧实现。
3. `Framework/Router/Core.php::buildCacheHeaders()`：同一规则（非 WLS 回退）。

> 这条落地后，即使有人漏盖章，最坏结果是「每次协商 304」，而不是「永久错文件」。

### 3.2 客户端：脚本加载失败可自愈

- `script.onerror` 携带 URL 与 `assetVersion` 上报；
- `Weline.config.assetVersion` 与 HTML 内嵌代次不一致时，节流地 reload 一次（与 Scope 刷新共用 8s 守卫思路，独立 key）。

### 3.3 发布验收

- 新增 `php bin/w deploy:verify-assets [--url=...]`：
  1. 取一份真实 HTML；
  2. 抽出全部 `/static/**` 与 `/pub/media/**` 引用；
  3. 逐个 HEAD，断言 200；
  4. 若 URL 含内容 sha，断言响应体 sha 前缀一致；
  5. 任何裸奔的模块 JS/CSS（无 sha 且无版本）→ 失败。
- `deploy:release` 收尾调用；失败即阻断发布。

## 4. 分阶段落地

| 阶段 | 内容 | 文件 | 验收 |
|---|---|---|---|
| P1 | manifest 生成 + 内容寻址副本 + 解析服务 | `Deploy/Upgrade.php`、新增 `Deploy/StaticAssetManifest.php`、`PublicStaticUrl` | 单测：清单 digest 稳定；同名文件变更 → digest 变；`resolve()` 返回带 sha 路径 |
| P2 | 加载器/模板统一走 manifest；`assetVersion = digest` | `Frontend/view/statics/js/weline.js`、`Theme/.../theme.js`、`header/base.phtml`、资产标签 | 真机 HTML 中所有模块 JS/CSS 带 sha |
| P3 | FPC 键并入 `static_generation` | `Framework/Cache/KeyBuilder.php` | 静态发布后旧 FPC 不再命中 |
| P4 | 长缓存安全网（三处） | Nginx writer / worker_ssl_event / Router\Core + 既有契约测试更新 | 无 sha 的 `/static/x.js` 响应 `max-age=0` |
| P5 | `deploy:verify-assets` + release 收尾 | 新增命令 + `Deploy/Release` | 故意改一个静态文件不发布 → 命令失败 |
| P6 | 延迟 GC（保留 7 天） | 新增 `deploy:static:gc` | 旧 sha 文件在窗口内仍可访问 |

## 5. 迁移与风险

- **兼容**：同名文件继续发布（旧 HTML 仍可用），新增 sha 副本；GC 只删超过保留窗口的 sha 副本。
- **磁盘**：每文件多一份副本（硬链接通常零额外空间）；GC 窗口 7 天。
- **Nginx**：`map` 变量改动会重写托管配置，需 `nginx -t` + 既有 `ManagedNginx*ContractTest` 覆盖；先在单站点验证再全量。
- **CDN**：`immutable` + 内容寻址后，CDN 缓存键天然正确；无需按发布刷 CDN。
- **回滚**：manifest 指回上一代 digest 即可；旧文件仍在盘上。

## 6. 与本次修复的关系

- 已完成（止血，P2 的一部分）：
  - `Frontend/view/statics/js/weline.js`：模块加载器生产环境追加 `weline_asset=<assetVersion>`；
  - `Theme/view/theme/frontend/assets/js/theme.js`：主题侧加载器同一规则。
  这两处解决「旧客户端永不更新」，但仍是**全局版本**，其它模块文件变化不会改版本。
- 本方案是根治：把「版本号」从全局查询串升级为**每文件内容寻址 + 清单摘要进 FPC 键**，并给长缓存加服务端判定。
- 建议执行顺序：P4（长缓存安全网，最便宜、立刻兜底）→ P1/P2（内容寻址）→ P3（FPC 键）→ P5（发布验收）→ P6（GC）。

### 6.1 已实现（2026-10-21 第一刀，方案 B 版本化根目录）

| 组件 | 文件 | 行为 |
|---|---|---|
| 构建号解析 | `Framework/Deploy/StaticBuildId.php` | 读 `pub/static/.build.json`，10s 进程缓存；`versionedPath('/static/...')` |
| 版本目录发布 | `Framework/Deploy/StaticBuildPublisher.php` | 内容哈希 12 位；硬链接整树到 `pub/static/{id}`（`.tmp-{id}` 原子改名）；保留旧代 |
| 发布接入 | `Deploy/Upgrade.php` | `publishStaticTree()` 末尾发布版本目录；`publishFile()` 改原子替换（保护硬链接旧代） |
| URL 权威 | `Framework/View/PublicStaticUrl.php` | 所有 `/static/...` 输出自动带 `/{buildId}` |
| 页面运行时 | `Frontend/view/blocks/header/base.phtml` | `assetVersion`/`staticBaseUrl` = buildId；`modulesBaseUrl` 随之版本化 |
| 模块加载器 | `Frontend/view/statics/js/weline.js` | 两处 `/static/...` 生产分支改用 `runtimeConfig.staticBaseUrl` |
| 缓存键 | `Framework/Cache/StorefrontCacheKeyContext.php` | `keyDimensions()` 增加 `static_generation` |

实测：`pub/static/.build.json` → `{"build_id":"05561952395a",...}`；页面脚本全部变为
`/static/05561952395a/Weline/Frontend/js/...`；购物车与 Scope 自恢复冒烟通过。

### 6.2 长缓存安全网（P4，已实现）

| 层 | 文件 | 规则 |
|---|---|---|
| 托管 Nginx | `Server/Service/Edge/Nginx/ManagedNginxConfigWriter.php` | `map $uri $wls_static_versioned`（`/static/{12hex}/`）+ `map $args`（`v`/`weline_asset`/`_weline_dev`）→ `$wls_static_cache_control`；`/static/` 三处 header 改用变量 |
| WLS Worker | `Server/bin/worker_ssl_event.php` | `StaticPublicSurface::isVersionedStaticUrl()` 分流；legacy 不进 L1（避免绕过协商） |
| Framework 回退路由 | `Framework/Router/Core.php` | `buildCacheHeaders()` 按版本分流；legacy 发 `max-age=0, must-revalidate` 且不发 `Expires` |
| 判定权威 | `Framework/Deploy/StaticPublicSurface.php` | `isVersionedStaticUrl()`：`/static/{12位hex}/` 或带版本查询 |

生成的托管 Nginx 配置已通过 `nginx -t`（临时目录生成验证）。

### 6.3 主题资产与内容寻址

- `ThemeResourceGateway::buildStaticUrl()`、`ThemeHeadChromeCssPack` 两处兜底已接入 `StaticBuildId`；
- **内容寻址文件（32~64 位 hex 文件名）不加 build 前缀**，直接按内容哈希长缓存：
  - `StaticBuildId::versionedPath()` 跳过；
  - `StaticPublicSurface::isVersionedStaticUrl()` 判为已版本化；
  - Nginx `$wls_static_versioned` 增加哈希文件名规则。
  这样运行时生成的主题包（`widget-assets/<sha>.css`）不会因为晚于构建快照而 404。

### 6.3.1 主题侧硬编码与 CSS 内部 url()

- 设计主题里历史硬编码的 `/static/Weline/<theme>/...`（`hanfu-skin.phtml`、瓦片/hero 部件）已改为走 `StaticBuildId::versionedPath()`；
- `WidgetAssetArtifactPublisher::rewriteCssUrls()` 在把 CSS 相对 `url()` 改写为绝对路径时**同步加构建代次**（可选 `$buildId` 参数，测试显式传 `''` 保持纯形状断言）；
- CSS 包键（`ThemeHeadChromeCssPack` v11 / 组件包）与 **policy 缓存键**（`KeyBuilder::policyDimensions()`）都并入 `static_generation`，
  静态发布后主题 chrome / 组件 CSS 不会继续内嵌旧构建 URL；
- 实测 `p05113ef3.test.weline.com:9555`：首页/购物车/结账/商品页运行时静态引用 **NONE=0**，404=0。

仍待迁移：`playinginchina`、`daocharms` 等其它设计主题里同类的硬编码 `/static/` 引用（当前站点未使用）。

### 6.3.2 默认主题（Theme 模块前后端）契约

- `app/code/Weline/Theme/view/theme/{frontend,backend}` 模板已确认**无裸 `/static/`**，主题资产走 `<theme:css>` / `<theme:js>` / `PublicStaticUrl`；
- 主题 `view/statics/**/*.css` 只用相对 `url()`，无绝对 `/static/`；
- `weline-ui.js` 图标 sprite 改为继承 `import.meta.url` 的 `?v=`，随 UI 包发布更新；
- 新增守卫：`Theme/Test/Unit/View/ThemeStaticVersionContractTest.php`（模板/CSS/图标三条规则）；
- 主题开发规范：`app/code/Weline/Theme/doc/静态资源版本契约.md`。

### 6.4 运维命令（P5/P6，已实现）

| 命令 | 行为 |
|---|---|
| `php bin/w deploy:static:gc [--keep-days=7] [--keep-last=2] [--dry-run]` | 只清理带 `.weline-build-id` 的 12 位 hex 版本目录与超龄 `.tmp-*`；当前代、最近 N 代、保留窗口内一律保留；legacy 树不动 |
| `php bin/w deploy:verify:assets --url=... [--insecure]` | 抓页面 HTML，校验每个本站 `/static` 资源：200 且已版本化（12 位构建段 / 32~64 位内容哈希 / 版本查询）；未版本化或非 200 → 退出码 1 |

实测：`deploy:static:gc --dry-run` 正常列出保留/删除；`deploy:verify:assets` 对本地商城返回「全部资源校验通过」。

### 6.5 尚未做

- WLS 热重载当前被并发生命周期操作占用，代码改动需下一次 `server:reload` 才在运行时生效。
