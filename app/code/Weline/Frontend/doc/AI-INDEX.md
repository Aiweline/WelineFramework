<!-- weline:module-ai-index:auto-generated -->
# Weline_Frontend AI 开发入口

> 本文件由 `dev/ai/scripts/generate-module-ai-indexes.php` 根据当前代码结构生成。它是 AI 进入模块前的导航入口；细节仍以本模块 `doc/`、实际源码和全局规则为准。

## 必读顺序

1. `AI-ENTRY.md`
2. `dev/ai/global-constraints.md`
3. `dev/ai/diagrams/08-module-docs-index.txt`
4. 本文件：`app/code/Weline/Frontend/doc/AI-INDEX.md`
5. 模块说明：`app/code/Weline/Frontend/doc/README.md`
6. `app/code/Weline/Theme/doc/AI-INDEX.md`
7. 只读取本次任务相关源码、配置和验证入口

## 模块身份

- 模块代码：`Weline_Frontend`
- 目录：`app/code/Weline/Frontend`
- Vendor：`Weline`
- Module：`Frontend`

## 代码面清单

入口/配置文件：
- `app/code/Weline/Frontend/etc/backend/menu.xml`
- `app/code/Weline/Frontend/composer.json`

- `Block`：视图数据块。配合模板输出页面数据，变更前要读对应模板和 layout。 文件数：4
- `Controller`：HTTP/后台/前台控制器入口。新增控制器后运行 setup:upgrade --route，同步路由。 文件数：3
- `Helper`：模块内辅助能力。跨模块不要直接调用未发布 Helper。 文件数：1
- `Interface`：模块发布的接口契约。跨模块依赖优先使用这里的稳定契约。 文件数：2
- `Model`：ORM 数据模型与字段 schema。字段结构用 #[Col]/#[Index] 后执行 setup:upgrade。 文件数：8
- `Observer`：事件观察者。改事件数据前要检查 doc/event 和触发方。 文件数：6
- `Plugin`：插件扩展点。变更前确认被拦截对象和执行顺序。 文件数：1
- `Service`：模块内业务编排层。跨模块读取数据优先发布/使用 w_query。 文件数：5
- `Setup`：安装/升级装配。不要手改 generated，也不要在 Setup/Upgrade.php 做字段 CRUD。 文件数：1
- `etc`：模块配置。禁止 routes.xml；路由由控制器和 setup:upgrade --route 生成。 文件数：5
- `i18n`：国际化资源。用户可见文案使用中文 source/key，en_US/zh_Hans_CN 对齐。 文件数：2
- `view/statics`：静态资源源文件。浏览器业务请求必须走 Weline.Api.*。 文件数：126
- `view/templates`：模块模板源文件。可编辑源模板；不要改 view/tpl 编译产物。 文件数：6
- `view/tpl`：模板编译/生成产物。禁止直接修改。 文件数：0

## 从源码识别到的开发提示

- 存在 `view/templates`，说明有模块模板源文件；主题覆盖要走 Theme 路径解析规则。
- 存在 `view/tpl`，这是编译/生成产物面，禁止直接修改。
- 存在 `i18n`，新增用户可见文案时同步 `zh_Hans_CN.csv` 与 `en_US.csv`。

## doc 目录

- `app/code/Weline/Frontend/doc/README.md`
- `app/code/Weline/Frontend/doc/Web Components/Switcher组件使用指南.md`
- `app/code/Weline/Frontend/doc/Web Components/架构建议.md`
- `app/code/Weline/Frontend/doc/Weline.Api使用指南.md`
- `app/code/Weline/Frontend/doc/event/account/前端账户注册后.md`
- `app/code/Weline/Frontend/doc/event/account/前端账户登录后.md`
- `app/code/Weline/Frontend/doc/hook/frontend/head.md`
- `app/code/Weline/Frontend/doc/hook/frontend/layouts/base/head-before.md`
- `app/code/Weline/Frontend/doc/主题规划.md`
- `app/code/Weline/Frontend/doc/主题设计/README.md`
- `app/code/Weline/Frontend/doc/主题设计/assets目录文档.md`
- `app/code/Weline/Frontend/doc/主题设计/colors目录文档.md`
- `app/code/Weline/Frontend/doc/主题设计/components目录文档.md`
- `app/code/Weline/Frontend/doc/主题设计/config目录文档.md`
- `app/code/Weline/Frontend/doc/主题设计/layouts目录文档.md`
- `app/code/Weline/Frontend/doc/主题设计/partials目录文档.md`
- `app/code/Weline/Frontend/doc/主题设计/theme目录详细说明.md`
- `app/code/Weline/Frontend/doc/主题设计/variables目录文档.md`
- `app/code/Weline/Frontend/doc/主题设计/主题目录结构.md`
- `app/code/Weline/Frontend/doc/主题设计/变量与颜色主题区别说明.md`
- `app/code/Weline/Frontend/doc/主题设计/模块加载系统使用指南.md`
- `app/code/Weline/Frontend/doc/主题设计/配色.md`

## 开发前门禁

- 先声明本次任务命中的模块、代码面和应读文档；没有命中文档时先补读源码，不要按通用经验猜。
- 涉及浏览器前后端业务请求时，只能使用 `Weline.Api.resource()`、`Weline.Api.graph()` 或 `Weline.Api.stream()`。
- 涉及跨模块读数据时，先查 `php bin/w query:help <provider|Weline_Frontend> [operation]` 或对应 `w_query` 帮助。
- 涉及模板、主题、slot、widget、taglib 或 `view/theme` 时，必须先读 `app/code/Weline/Theme/doc/AI-INDEX.md`。
- 禁止直接修改 `generated/`、`view/tpl/`、`routes.xml` 或复制旧文档里的过时路径。
- 如果本文件与源码冲突，以源码为准，并在同次任务中修正模块文档。
