<!-- weline:module-readme:auto-generated -->
# Weline_UrlManager 模块文档

> 本 README 由 `dev/ai/scripts/generate-missing-module-readmes.php` 根据当前代码结构自动生成。它提供模块级结构说明和开发入口，不替代后续人工补充的业务规则、接口契约和专项设计文档。

## 当前入口

开发前先读：

1. `app/code/Weline/UrlManager/doc/AI-INDEX.md`
2. `dev/ai/diagrams/08-module-docs-index.txt`
3. `dev/ai/global-constraints.md`
4. `app/code/Weline/Theme/doc/AI-INDEX.md`
5. `app/code/Weline/Frontend/doc/AI-INDEX.md`

## 模块定位

- 模块代码：`Weline_UrlManager`
- 目录：`app/code/Weline/UrlManager`
- 当前状态：结构化模块概览已补齐；稳定业务规则仍应继续沉淀到本模块 `doc/`。

## 公共 PHP 契约

跨模块扫描 URL rewrite 时使用 `Weline\UrlManager\Api\Rewrite\UrlRewriteDirectoryInterface`。同名 Factory/编译 Provider 返回不可变 `UrlRewriteRecord`，只发布 rewrite ID、系统默认站点或业务站点 ID、字段是否显式存在、内部 path 与公开 rewrite；调用方不得注入 `Weline\UrlManager\Model\UrlRewrite`，也不得读取其字段常量或 Query Builder。`websiteIdSpecified=true, websiteId=0` 明确表示系统默认站点；只有 `websiteIdSpecified=false, websiteId=null` 才表示遗留字段缺失，可由调用方采用受控的全站语义。

## 代码面概览

入口文件：
- `app/code/Weline/UrlManager/composer.json`
- `app/code/Weline/UrlManager/etc/backend/menu.xml`

- `Controller`：前后台 HTTP 控制器与路由入口。 文件数：2
- `Controller/Backend`：后台控制器入口；变更前同步检查 ACL、菜单和返回路径。 文件数：2
- `Model`：ORM 模型与字段 schema。 文件数：2
- `Observer`：事件观察者与订阅逻辑。 文件数：2
- `Plugin`：插件扩展点。 文件数：1
- `Setup`：安装/升级数据装配；字段和索引 DDL 仍由 Model attribute 与 SchemaDiff 负责。 文件数：1
- `etc`：模块配置。 文件数：4
- `i18n`：国际化资源。 文件数：2
- `view/templates`：模块模板源文件。 文件数：3
- `view/tpl`：模板编译/生成产物。 文件数：0

## 开发关注点

- 存在 `Controller/`，说明模块有 HTTP 入口；控制器变更后记得同步路由升级和最接近的真实入口验证。
- 存在 `Controller/Backend`，后台页面/行为变更时应同时检查菜单、ACL、返回地址和用户提示。
- 存在 `Model/`，字段或索引变更需走模型 attribute + `setup:upgrade`，不要手改生成物。
- 存在 `Observer/`，改事件数据前应同步检查触发点和消费点。
- 存在模板源文件；出现页面问题时先追源码，不要直接改 `view/tpl`。
- 存在 `i18n`，用户可见文案改动要同步 `zh_Hans_CN.csv` 与 `en_US.csv`。
- 存在测试目录，但默认不要新增测试产物；只有用户明确要求时才进入测试修改。

## 本模块文档资产

- `app/code/Weline/UrlManager/doc/route-import-idempotency.md`
- `app/code/Weline/UrlManager/doc/url-rewrite-slug-redirect-plan.md`

## 维护规则

- 不直接修改 `generated/`、`view/tpl/`、`routes.xml`。
- 涉及浏览器业务请求时，只使用 `Weline.Api.*` / QueryProvider 链路。
- 涉及字段结构时，用 `#[Col]` / `#[Index]` 和 `php bin/w setup:upgrade`。
- 涉及控制器路由时，用 `php bin/w setup:upgrade --route`。
- 本 README 目前是结构稿；后续功能稳定后，应继续补模块职责、关键流程、接口与反例。

## URL 重写公共读取

`Weline\UrlManager\Api\Rewrite\UrlRewriteDirectoryInterface` 提供非空重写列表、当前网站 ID
和按网站/path 的精确查询，结果为不可变 `UrlRewriteRecord`。可选集成必须经 Runtime Provider
解析，不能直接实例化 `UrlRewrite` Model，也不能在模块缺失时产生类加载错误。

## path 精确查询与三库索引

`url_rewrite.path` 保持 `TEXT` 和原文，不使用 MySQL 前缀索引，因为前缀相等不能代表完整路径相等。模型保存时按 path 原始字节计算小写十六进制 SHA-256，写入可空迁移列 `path_fingerprint`；查询索引为 `(website_id, path_fingerprint, rewrite_id)`。

指纹只是候选集索引键。`UrlRewrite::findLatestByWebsiteAndPath()` 必须再用 PHP 严格 `===` 比较存储 path 与输入原始字节，避免 MySQL 默认 collation 的大小写/重音等值影响，并防御理论上的 SHA-256 碰撞。迁移窗口内只有 `path_fingerprint IS NULL` 或空字符串的旧行可进入兼容候选集，仍由 PHP 原始字节比较决定是否命中；候选集继续使用 `(website_id, path_fingerprint, rewrite_id)` 索引，正常 miss 不会退化为 TEXT path 全表扫描。非空但不匹配的指纹必须失败关闭。

`Setup/Upgrade.php` 是纯数据 phase-1 迁移：先对全表指纹与 path 做无写入预检，然后在写事务内回填缺失值并再次全表校验。列和索引 DDL 只由 `#[Col]` / `#[Index]` 与 `php bin/w setup:upgrade` 处理，Upgrade 不执行手写 DDL。
