<!-- weline:module-ai-index:auto-generated -->
# Weline_Framework AI 开发入口

> 本文件由 `dev/ai/scripts/generate-module-ai-indexes.php` 根据当前代码结构生成。它是 AI 进入模块前的导航入口；细节仍以本模块 `doc/`、实际源码和全局规则为准。

## 必读顺序

1. `AI-ENTRY.md`
2. `dev/ai/global-constraints.md`
3. `dev/ai/diagrams/08-module-docs-index.txt`
4. 本文件：`app/code/Weline/Framework/doc/AI-INDEX.md`
5. 模块说明：`app/code/Weline/Framework/doc/README.md`
6. 认证/设备任务：`app/code/Weline/Framework/doc/需求.md`、`app/code/Weline/Framework/doc/开发日志.md`、`app/code/Weline/SessionManager/doc/设备管理架构.md`
7. `app/code/Weline/Theme/doc/AI-INDEX.md`
8. `app/code/Weline/Frontend/doc/AI-INDEX.md`
9. `app/code/Weline/Taglib/doc/AI-INDEX.md`
10. 只读取本次任务相关源码、配置和验证入口

## 模块身份

- 模块代码：`Weline_Framework`
- 目录：`app/code/Weline/Framework`
- Vendor：`Weline`
- Module：`Framework`

## 代码面清单

入口/配置文件：
- `app/code/Weline/Framework/etc/backend/menu.xml`
- `app/code/Weline/Framework/.module_config.json`
- `app/code/Weline/Framework/composer.json`

- `Api`：公开接口契约。跨模块调用优先找已发布 Interface 或 QueryProvider，不要直接依赖对方内部 Service/Model。 文件数：8
- `Config`：配置读取、合并或 schema 支撑。涉及作用域配置时同时读 SystemConfig 文档。 文件数：1
- `Console`：php bin/w 命令入口。新增/变更命令后用真实 CLI 验证。 文件数：37
- `Controller`：HTTP/后台/前台控制器入口。新增控制器后运行 setup:upgrade --route，同步路由。 文件数：12
- `Helper`：模块内辅助能力。跨模块不要直接调用未发布 Helper。 文件数：2
- `Model`：ORM 数据模型与字段 schema。字段结构用 #[Col]/#[Index] 后执行 setup:upgrade。 文件数：12
- `Plugin`：插件扩展点。变更前确认被拦截对象和执行顺序。 文件数：15
- `Service`：模块内业务编排层。跨模块读取数据优先发布/使用 w_query。 文件数：53
- `Setup`：安装/升级装配。不要手改 generated，也不要在 Setup/Upgrade.php 做字段 CRUD。 文件数：38
- `Taglib`：模板标签扩展。改前读 Weline_Taglib 与 Theme 文档。 文件数：2
- `Ui`：后台/编辑器 UI 参数、schema 或渲染支撑。 文件数：1
- `etc`：模块配置。禁止 routes.xml；路由由控制器和 setup:upgrade --route 生成。 文件数：4
- `extends`：模块扩展声明。优先使用 extends/module/{Module}/... 的当前约定。 文件数：16
- `i18n`：国际化资源。用户可见文案使用中文 source/key，en_US/zh_Hans_CN 对齐。 文件数：2
- `view/statics`：静态资源源文件。浏览器业务请求必须走 Weline.Api.*。 文件数：3
- `view/templates`：模块模板源文件。可编辑源模板；不要改 view/tpl 编译产物。 文件数：3
- `view/tpl`：模板编译/生成产物。禁止直接修改。 文件数：0

## 从源码识别到的开发提示

- 存在 `view/templates`，说明有模块模板源文件；主题覆盖要走 Theme 路径解析规则。
- 存在 `view/tpl`，这是编译/生成产物面，禁止直接修改。
- 存在 `extends/module`，优先使用当前扩展约定，不要回退到旧式随意扩展路径。
- 存在 `i18n`，新增用户可见文案时同步 `zh_Hans_CN.csv` 与 `en_US.csv`。
- 识别到 QueryProvider 相关 PHP 文件：Authorization/Resource/AuthorizationResourceCatalogBuilder.php、Common/functions.php、Compilation/FrameworkCompiler.php、Console/Console/Framework/Compile.php、Console/Console/Query/Help.php、Extends/module/Weline_Framework/Query/AsyncEventDeliveryQueryProvider.php、Extends/module/Weline_Framework/Query/FrameworkAdminQueryProvider.php、Extends/module/Weline_Framework/Query/QueryHelpProvider.php 等；前端/跨模块读数据先查 query 帮助。

## doc 目录

- `app/code/Weline/Framework/doc/需求.md`
- `app/code/Weline/Framework/doc/开发日志.md`
- `app/code/Weline/Framework/doc/0-简介/理念/WelineFramework框架设计目的！.txt`
- `app/code/Weline/Framework/doc/1-部署/服务器部署.md`
- `app/code/Weline/Framework/doc/2-快速开始/01-概述.md`
- `app/code/Weline/Framework/doc/2-快速开始/02-快速创建模组-Hello World.md`
- `app/code/Weline/Framework/doc/2-快速开始/03-自定义控制器.md`
- `app/code/Weline/Framework/doc/2-快速开始/04-自定义模型.md`
- `app/code/Weline/Framework/doc/2-快速开始/05-模板.md`
- `app/code/Weline/Framework/doc/2-快速开始/06-自定义标签.md`
- `app/code/Weline/Framework/doc/2-快速开始/07-block标签以及其他框架标签简介.md`
- `app/code/Weline/Framework/doc/2-快速开始/08-事件.md`
- `app/code/Weline/Framework/doc/2-快速开始/09-模组管理.md`
- `app/code/Weline/Framework/doc/2-快速开始/10-类规范.md`
- `app/code/Weline/Framework/doc/2-快速开始/11-快速参考_常见错误和解决方案.md`
- `app/code/Weline/Framework/doc/2-快速开始/快速建立一个模组.txt`
- `app/code/Weline/Framework/doc/2-快速开始/控制器.txt`
- `app/code/Weline/Framework/doc/3-开发/01-翻译函数使用指南.md`
- `app/code/Weline/Framework/doc/3-开发/API接口开发规范.md`
- `app/code/Weline/Framework/doc/3-开发/SSE可恢复后台任务架构.md`
- `app/code/Weline/Framework/doc/3-开发/Scope限流.md`
- `app/code/Weline/Framework/doc/3-开发/secret_ref凭据密封.md`
- `app/code/Weline/Framework/doc/3-开发/事务协调与after-commit.md`
- `app/code/Weline/Framework/doc/3-开发/安全响应头策略.md`
- `app/code/Weline/Framework/doc/3-开发/异步事件死信运维.md`
- `app/code/Weline/Framework/doc/3-开发/异步观察者与资源变更测试矩阵.md`
- `app/code/Weline/Framework/doc/3-开发/控制面资源变更目录.md`
- `app/code/Weline/Framework/doc/3-开发/服务器事件系统.md`
- `app/code/Weline/Framework/doc/3-开发/模块开发完整指南.md`
- `app/code/Weline/Framework/doc/3-开发/模型升级顺序规则.md`
- `app/code/Weline/Framework/doc/3-开发/缓存使用指南.md`
- `app/code/Weline/Framework/doc/3-开发/配置包AEAD信封.md`
- `app/code/Weline/Framework/doc/4-内置标签/01-lang标签使用指南.md`
- `app/code/Weline/Framework/doc/4-内置标签/02-var标签使用指南.md`
- `app/code/Weline/Framework/doc/4-内置标签/03-if-elseif-else标签使用指南.md`
- `app/code/Weline/Framework/doc/4-内置标签/04-foreach标签使用指南.md`
- `app/code/Weline/Framework/doc/4-内置标签/05-block标签使用指南.md`
- `app/code/Weline/Framework/doc/4-内置标签/06-url标签使用指南.md`
- `app/code/Weline/Framework/doc/4-内置标签/07-php-include标签使用指南.md`
- `app/code/Weline/Framework/doc/4-内置标签/08-empty-notempty-has标签使用指南.md`
- `app/code/Weline/Framework/doc/4-内置标签/09-static-template-js-css标签使用指南.md`
- `app/code/Weline/Framework/doc/4-内置标签/10-pp-dd-count-string标签使用指南.md`
- `app/code/Weline/Framework/doc/4-内置标签/11-csrf-message-msg-hook标签使用指南.md`
- `app/code/Weline/Framework/doc/4-内置标签/README.md`
- `app/code/Weline/Framework/doc/5-模块管理/module-create命令使用文档.md`
- `app/code/Weline/Framework/doc/5-模块管理/卸载服务使用文档.md`
- `app/code/Weline/Framework/doc/BinQuery/Provider开发指南.md`
- `app/code/Weline/Framework/doc/BinQuery/README.md`
- `app/code/Weline/Framework/doc/BinQuery/SDK使用指南.md`
- `app/code/Weline/Framework/doc/BinQuery/协议对接指南.md`
- `app/code/Weline/Framework/doc/README.md`
- `app/code/Weline/Framework/doc/architecture/01-current.md`
- `app/code/Weline/Framework/doc/architecture/02-target.md`
- `app/code/Weline/Framework/doc/architecture/03-module-contract.md`
- `app/code/Weline/Framework/doc/architecture/04-performance-budget.md`
- `app/code/Weline/Framework/doc/architecture/README.md`
- `app/code/Weline/Framework/doc/event/acl/ACL分发.md`
- `app/code/Weline/Framework/doc/event/app/URL解析后.md`
- `app/code/Weline/Framework/doc/event/app/后端控制器初始化前.md`
- `app/code/Weline/Framework/doc/event/app/后端控制器初始化后.md`
- `app/code/Weline/Framework/doc/event/app/应用运行前.md`
- `app/code/Weline/Framework/doc/event/app/应用运行后.md`
- `app/code/Weline/Framework/doc/event/app/店面范围就绪门禁.md`
- `app/code/Weline/Framework/doc/event/app/请求前置门禁.md`
- `app/code/Weline/Framework/doc/event/console/控制台编译.md`
- `app/code/Weline/Framework/doc/event/controller/REST控制器初始化前.md`
- `app/code/Weline/Framework/doc/event/controller/REST控制器初始化后.md`
- `app/code/Weline/Framework/doc/event/controller/前端REST控制器初始化前.md`
- `app/code/Weline/Framework/doc/event/controller/前端REST控制器初始化后.md`
- `app/code/Weline/Framework/doc/event/controller/前端控制器初始化前.md`
- `app/code/Weline/Framework/doc/event/controller/前端控制器初始化后.md`
- `app/code/Weline/Framework/doc/event/controller/控制器模板获取前.md`
- `app/code/Weline/Framework/doc/event/controller/控制器模板获取后.md`
- `app/code/Weline/Framework/doc/event/cookie/Cookie语言本地化.md`
- `app/code/Weline/Framework/doc/event/database/数据库索引器.md`
- `app/code/Weline/Framework/doc/event/database/数据库索引器列表.md`
- `app/code/Weline/Framework/doc/event/database/模型更新前.md`
- `app/code/Weline/Framework/doc/event/database/模型更新后.md`
- `app/code/Weline/Framework/doc/event/deploy/部署模式切换到生产环境后.md`
- `app/code/Weline/Framework/doc/event/fpc/缓存命中响应.md`
- `app/code/Weline/Framework/doc/event/framework/resource_changed.md`
- `app/code/Weline/Framework/doc/event/framework/系统消息通知.md`
- `... 另有 66 个文档，请按任务在该模块 doc/ 下继续查找`

## 开发前门禁

- 先声明本次任务命中的模块、代码面和应读文档；没有命中文档时先补读源码，不要按通用经验猜。
- 涉及浏览器前后端业务请求时，只能使用 `Weline.Api.resource()`、`Weline.Api.graph()` 或 `Weline.Api.stream()`。
- 涉及跨模块读数据时，先查 `php bin/w query:help <provider|Weline_Framework> [operation]` 或对应 `w_query` 帮助。
- 涉及模板、主题、slot、widget、taglib 或 `view/theme` 时，必须先读 `app/code/Weline/Theme/doc/AI-INDEX.md`。
- 禁止直接修改 `generated/`、`view/tpl/`、`routes.xml` 或复制旧文档里的过时路径。
- 如果本文件与源码冲突，以源码为准，并在同次任务中修正模块文档。
