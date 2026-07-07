<!-- weline:module-ai-index:auto-generated -->
# Weline_Visitor AI 开发入口

> 本文件由 `dev/ai/scripts/generate-module-ai-indexes.php` 根据当前代码结构生成。它是 AI 进入模块前的导航入口；细节仍以本模块 `doc/`、实际源码和全局规则为准。

## 必读顺序

1. `AI-ENTRY.md`
2. `dev/ai/global-constraints.md`
3. `dev/ai/diagrams/08-module-docs-index.txt`
4. 本文件：`app/code/Weline/Visitor/doc/AI-INDEX.md`
5. 模块说明：`app/code/Weline/Visitor/doc/README.md`
6. `app/code/Weline/Theme/doc/AI-INDEX.md`
7. `app/code/Weline/Frontend/doc/AI-INDEX.md`
8. `app/code/Weline/Taglib/doc/AI-INDEX.md`
9. 只读取本次任务相关源码、配置和验证入口

## 模块身份

- 模块代码：`Weline_Visitor`
- 目录：`app/code/Weline/Visitor`
- Vendor：`Weline`
- Module：`Visitor`

## 代码面清单

入口/配置文件：
- `app/code/Weline/Visitor/etc/backend/menu.xml`
- `app/code/Weline/Visitor/composer.json`

- `Api`：公开接口契约。跨模块调用优先找已发布 Interface 或 QueryProvider，不要直接依赖对方内部 Service/Model。 文件数：5
- `Controller`：HTTP/后台/前台控制器入口。新增控制器后运行 setup:upgrade --route，同步路由。 文件数：5
- `Model`：ORM 数据模型与字段 schema。字段结构用 #[Col]/#[Index] 后执行 setup:upgrade。 文件数：5
- `Observer`：事件观察者。改事件数据前要检查 doc/event 和触发方。 文件数：4
- `Service`：模块内业务编排层。跨模块读取数据优先发布/使用 w_query。 文件数：10
- `Setup`：安装/升级装配。不要手改 generated，也不要在 Setup/Upgrade.php 做字段 CRUD。 文件数：2
- `Taglib`：模板标签扩展。改前读 Weline_Taglib 与 Theme 文档。 文件数：1
- `etc`：模块配置。禁止 routes.xml；路由由控制器和 setup:upgrade --route 生成。 文件数：3
- `extends`：模块扩展声明。优先使用 extends/module/{Module}/... 的当前约定。 文件数：3
- `i18n`：国际化资源。用户可见文案使用中文 source/key，en_US/zh_Hans_CN 对齐。 文件数：2
- `view/statics`：静态资源源文件。浏览器业务请求必须走 Weline.Api.*。 文件数：2
- `view/templates`：模块模板源文件。可编辑源模板；不要改 view/tpl 编译产物。 文件数：10
- `view/tpl`：模板编译/生成产物。禁止直接修改。 文件数：5

## 从源码识别到的开发提示

- 存在 `view/templates`，说明有模块模板源文件；主题覆盖要走 Theme 路径解析规则。
- 存在 `view/tpl`，这是编译/生成产物面，禁止直接修改。
- 存在 `extends/module`，优先使用当前扩展约定，不要回退到旧式随意扩展路径。
- 存在 `i18n`，新增用户可见文案时同步 `zh_Hans_CN.csv` 与 `en_US.csv`。
- 识别到 QueryProvider 相关 PHP 文件：extends/module/Weline_Framework/Query/VisitorQueryProvider.php；前端/跨模块读数据先查 query 帮助。

## doc 目录

- `app/code/Weline/Visitor/doc/README.md`
- `app/code/Weline/Visitor/doc/event/访客像素标签.md`
- `app/code/Weline/Visitor/doc/像素拓展使用指南.md`
- `app/code/Weline/Visitor/doc/功能完善总结-数据分析.md`
- `app/code/Weline/Visitor/doc/功能完善总结.md`
- `app/code/Weline/Visitor/doc/功能校验最终报告.md`
- `app/code/Weline/Visitor/doc/功能检验完整报告.md`
- `app/code/Weline/Visitor/doc/功能检验总结.md`
- `app/code/Weline/Visitor/doc/功能检验报告-详细.md`
- `app/code/Weline/Visitor/doc/功能检验报告.md`
- `app/code/Weline/Visitor/doc/功能检验最终报告.md`
- `app/code/Weline/Visitor/doc/升级故障排除-traffic_split.md`
- `app/code/Weline/Visitor/doc/多站点事件监听看板.md`
- `app/code/Weline/Visitor/doc/数据分析功能使用指南.md`
- `app/code/Weline/Visitor/doc/站点ID功能完善说明.md`

## 开发前门禁

- 先声明本次任务命中的模块、代码面和应读文档；没有命中文档时先补读源码，不要按通用经验猜。
- 涉及浏览器前后端业务请求时，只能使用 `Weline.Api.resource()`、`Weline.Api.graph()` 或 `Weline.Api.stream()`。
- 涉及跨模块读数据时，先查 `php bin/w query:help <provider|Weline_Visitor> [operation]` 或对应 `w_query` 帮助。
- 涉及模板、主题、slot、widget、taglib 或 `view/theme` 时，必须先读 `app/code/Weline/Theme/doc/AI-INDEX.md`。
- 禁止直接修改 `generated/`、`view/tpl/`、`routes.xml` 或复制旧文档里的过时路径。
- 如果本文件与源码冲突，以源码为准，并在同次任务中修正模块文档。
