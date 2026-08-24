<!-- weline:module-ai-index:auto-generated -->
# Weline_Deploy AI 开发入口

> 本文件由 `dev/ai/scripts/generate-module-ai-indexes.php` 根据当前代码结构生成。它是 AI 进入模块前的导航入口；细节仍以本模块 `doc/`、实际源码和全局规则为准。

## 必读顺序

1. `AI-ENTRY.md`
2. `dev/ai/global-constraints.md`
3. `dev/ai/diagrams/08-module-docs-index.txt`
4. 本文件：`app/code/Weline/Deploy/doc/AI-INDEX.md`
5. 模块说明：`app/code/Weline/Deploy/doc/README.md`
6. `app/code/Weline/Theme/doc/AI-INDEX.md`
7. `app/code/Weline/Frontend/doc/AI-INDEX.md`
8. 只读取本次任务相关源码、配置和验证入口

## 模块身份

- 模块代码：`Weline_Deploy`
- 目录：`app/code/Weline/Deploy`
- Vendor：`Weline`
- Module：`Deploy`

## 代码面清单

入口/配置文件：
- `app/code/Weline/Deploy/etc/backend/menu.xml`
- `app/code/Weline/Deploy/composer.json`

- `Console`：php bin/w 命令入口。新增/变更命令后用真实 CLI 验证。 文件数：7
- `Controller`：HTTP/后台/前台控制器入口。新增控制器后优先跑完整 `setup:upgrade`；仅需重建路由图时可用 `setup:upgrade --route`。 文件数：9
- `Controller/Router.php`：ModuleRouter 自定义 URL 匹配入口。只有自定义公网路径/动态路由匹配才改这里。 文件数：1
- `Model`：ORM 数据模型与字段 schema。字段结构用 #[Col]/#[Index] 后执行 setup:upgrade。 文件数：2
- `Observer`：事件观察者。改事件数据前要检查 doc/event 和触发方。 文件数：1
- `Service`：模块内业务编排层。跨模块读取数据优先发布/使用 w_query。 文件数：14
- `Setup`：安装/升级装配。不要手改 generated，也不要在 Setup/Upgrade.php 做字段 CRUD。 文件数：1
- `etc`：模块配置。禁止 routes.xml；路由由控制器发现，完整 `setup:upgrade` 会同步；仅路由图变更时可用 `--route`。 文件数：5
- `extends`：模块扩展声明。优先使用 extends/module/{Module}/... 的当前约定。 文件数：1
- `i18n`：国际化资源。用户可见文案使用中文 source/key，en_US/zh_Hans_CN 对齐。 文件数：2
- `view/statics`：静态资源源文件。浏览器业务请求必须走 Weline.Api.*。 文件数：0
- `view/templates`：模块模板源文件。可编辑源模板；不要改 view/tpl 编译产物。 文件数：5
- `view/tpl`：模板编译/生成产物。禁止直接修改。 文件数：0

## 从源码识别到的开发提示

- 存在 `Controller/Router.php`，说明模块可能发布自定义 URL 匹配；不要用 `routes.xml` 代替。
- 存在 `view/templates`，说明有模块模板源文件；主题覆盖要走 Theme 路径解析规则。
- 存在 `view/tpl`，这是编译/生成产物面，禁止直接修改。
- 存在 `extends/module`，优先使用当前扩展约定，不要回退到旧式随意扩展路径。
- 存在 `i18n`，新增用户可见文案时同步 `zh_Hans_CN.csv` 与 `en_US.csv`。
- 识别到 QueryProvider 相关 PHP 文件：extends/module/Weline_Framework/Query/DeployAdminQueryProvider.php；前端/跨模块读数据先查 query 帮助。

## doc 目录

- `app/code/Weline/Deploy/doc/README.md`
- `app/code/Weline/Deploy/doc/backend-config.md`
- `app/code/Weline/Deploy/doc/gitee-webhook.md`
- `app/code/Weline/Deploy/doc/github-webhook.md`
- `app/code/Weline/Deploy/doc/webhook-secret.md`
- `app/code/Weline/Deploy/doc/wls-panel-project-webhook.md`
- `app/code/Weline/Deploy/doc/发布管理-需求说明.md`

## 开发前门禁

- 先声明本次任务命中的模块、代码面和应读文档；没有命中文档时先补读源码，不要按通用经验猜。
- 涉及浏览器前后端业务请求时，只能使用 `Weline.Api.resource()`、`Weline.Api.graph()` 或 `Weline.Api.stream()`。
- 涉及跨模块读数据时，先查 `php bin/w query:help <provider|Weline_Deploy> [operation]` 或对应 `w_query` 帮助。
- 涉及模板、主题、slot、widget、taglib 或 `view/theme` 时，必须先读 `app/code/Weline/Theme/doc/AI-INDEX.md`。
- 禁止直接修改 `generated/`、`view/tpl/`、`routes.xml` 或复制旧文档里的过时路径。
- 如果本文件与源码冲突，以源码为准，并在同次任务中修正模块文档。
