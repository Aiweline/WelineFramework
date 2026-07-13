<!-- weline:module-ai-index:auto-generated -->
# Weline_Theme AI 开发入口

> 本文件由 `dev/ai/scripts/generate-module-ai-indexes.php` 根据当前代码结构生成。它是 AI 进入模块前的导航入口；细节仍以本模块 `doc/`、实际源码和全局规则为准。

## 必读顺序

1. `AI-ENTRY.md`
2. `dev/ai/global-constraints.md`
3. `dev/ai/diagrams/08-module-docs-index.txt`
4. 本文件：`app/code/Weline/Theme/doc/AI-INDEX.md`
5. 模块说明：`app/code/Weline/Theme/doc/README.md`
6. `app/code/Weline/Theme/doc/开发/Theme开发总指南.md`
7. `app/code/Weline/Theme/doc/theme-inheritance-and-file-conventions.md`
8. `app/code/Weline/Frontend/doc/AI-INDEX.md`
9. `app/code/Weline/Taglib/doc/AI-INDEX.md`
10. 只读取本次任务相关源码、配置和验证入口

## 模块身份

- 模块代码：`Weline_Theme`
- 目录：`app/code/Weline/Theme`
- Vendor：`Weline`
- Module：`Theme`

## 代码面清单

入口/配置文件：
- `app/code/Weline/Theme/etc/backend/menu.xml`
- `app/code/Weline/Theme/.module_config.json`
- `app/code/Weline/Theme/composer.json`

- `Api`：公开接口契约。跨模块调用优先找已发布 Interface 或 QueryProvider，不要直接依赖对方内部 Service/Model。 文件数：2
- `Block`：视图数据块。配合模板输出页面数据，变更前要读对应模板和 layout。 文件数：2
- `Config`：配置读取、合并或 schema 支撑。涉及作用域配置时同时读 SystemConfig 文档。 文件数：2
- `Console`：php bin/w 命令入口。新增/变更命令后用真实 CLI 验证。 文件数：12
- `Controller`：HTTP/后台/前台控制器入口。新增控制器后运行 setup:upgrade --route，同步路由。 文件数：17
- `Controller/Router.php`：ModuleRouter 自定义 URL 匹配入口。只有自定义公网路径/动态路由匹配才改这里。 文件数：1
- `Dto`：跨层传输结构。变更字段时同步接口/文档。 文件数：4
- `Helper`：模块内辅助能力。跨模块不要直接调用未发布 Helper。 文件数：35
- `Interface`：模块发布的接口契约。跨模块依赖优先使用这里的稳定契约。 文件数：4
- `Model`：ORM 数据模型与字段 schema。字段结构用 #[Col]/#[Index] 后执行 setup:upgrade。 文件数：8
- `Observer`：事件观察者。改事件数据前要检查 doc/event 和触发方。 文件数：21
- `Service`：模块内业务编排层。跨模块读取数据优先发布/使用 w_query。 文件数：54
- `Setup`：安装/升级装配。不要手改 generated，也不要在 Setup/Upgrade.php 做字段 CRUD。 文件数：3
- `Taglib`：模板标签扩展。改前读 Weline_Taglib 与 Theme 文档。 文件数：17
- `Ui`：后台/编辑器 UI 参数、schema 或渲染支撑。 文件数：1
- `etc`：模块配置。禁止 routes.xml；路由由控制器和 setup:upgrade --route 生成。 文件数：3
- `extends`：模块扩展声明。优先使用 extends/module/{Module}/... 的当前约定。 文件数：7
- `i18n`：国际化资源。用户可见文案使用中文 source/key，en_US/zh_Hans_CN 对齐。 文件数：2
- `view/statics`：静态资源源文件。浏览器业务请求必须走 Weline.Api.*。 文件数：14
- `view/templates`：模块模板源文件。可编辑源模板；不要改 view/tpl 编译产物。 文件数：27
- `view/theme`：主题资源贡献层。读 Weline_Theme/doc/AI-INDEX.md 后按 layout/partial/component/widget 规则开发。 文件数：206
- `view/tpl`：模板编译/生成产物。禁止直接修改。 文件数：14

## 从源码识别到的开发提示

- 存在 `Controller/Router.php`，说明模块可能发布自定义 URL 匹配；不要用 `routes.xml` 代替。
- 存在 `view/theme`，说明该模块向主题资源 catalog 贡献 layout/partial/component/widget/asset。
- 存在 `view/templates`，说明有模块模板源文件；主题覆盖要走 Theme 路径解析规则。
- 存在 `view/tpl`，这是编译/生成产物面，禁止直接修改。
- 存在 `extends/module`，优先使用当前扩展约定，不要回退到旧式随意扩展路径。
- 存在 `i18n`，新增用户可见文案时同步 `zh_Hans_CN.csv` 与 `en_US.csv`。
- 识别到 QueryProvider 相关 PHP 文件：Observer/WorkerBootstrapWarmup.php、extends/module/Weline_Framework/Query/ThemeQueryProvider.php、extends/module/Weline_Websites/WebsiteThemeSource/WelineThemeSource.php；前端/跨模块读数据先查 query 帮助。

## doc 目录

- `app/code/Weline/Theme/doc/DEVELOPMENT_NOTES.md`
- `app/code/Weline/Theme/doc/HTML-lang属性BCP-47规范.md`
- `app/code/Weline/Theme/doc/Hook使用指南.md`
- `app/code/Weline/Theme/doc/Partials配置系统使用指南.md`
- `app/code/Weline/Theme/doc/README.md`
- `app/code/Weline/Theme/doc/SOLID原则重构说明.md`
- `app/code/Weline/Theme/doc/Theme.js使用指南.md`
- `app/code/Weline/Theme/doc/runtime-cache-invalidation.md`
- `app/code/Weline/Theme/doc/worker-view-warmup-contributions.md`
- `app/code/Weline/Theme/doc/event/theme_editor_result_after.md`
- `app/code/Weline/Theme/doc/hook/backend/partials/topbar/logo.md`
- `app/code/Weline/Theme/doc/hook/frontend/account/sidebar-content.md`
- `app/code/Weline/Theme/doc/hook/frontend/account/sidebar.md`
- `app/code/Weline/Theme/doc/hook/frontend/footer.md`
- `app/code/Weline/Theme/doc/hook/frontend/header/account-links.md`
- `app/code/Weline/Theme/doc/hook/frontend/header/account.md`
- `app/code/Weline/Theme/doc/hook/frontend/header/cart.md`
- `app/code/Weline/Theme/doc/hook/frontend/header/categories-menu.md`
- `app/code/Weline/Theme/doc/hook/frontend/header/currency-switcher.md`
- `app/code/Weline/Theme/doc/hook/frontend/header/hamburger-menu.md`
- `app/code/Weline/Theme/doc/hook/frontend/header/language-switcher.md`
- `app/code/Weline/Theme/doc/hook/frontend/header/location-selector.md`
- `app/code/Weline/Theme/doc/hook/frontend/header/nav-links.md`
- `app/code/Weline/Theme/doc/hook/frontend/header/nav-right.md`
- `app/code/Weline/Theme/doc/hook/frontend/header/orders.md`
- `app/code/Weline/Theme/doc/hook/frontend/header/user-info.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/account/body-end.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/account/body-start.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/account/content-after.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/account/content-before.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/account/head-after.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/account/head-before.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/account/sidebar-after.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/account/sidebar-before.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/base/body-end.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/base/body-start.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/base/breadcrumb-after.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/base/breadcrumb-before.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/base/content-after.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/base/content-before.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/base/footer-after.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/base/footer-before.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/base/head-after.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/base/head-before.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/base/header-after.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/base/header-before.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/cart-empty/recommendations.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/cart/body-end.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/cart/body-start.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/cart/content-after.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/cart/content-before.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/cart/footer-after.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/cart/footer-before.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/cart/head-after.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/cart/header-after.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/cart/header-before.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/cart/main-after.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/cart/main-before.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/cart/recommendations.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/category/filters-after.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/category/filters-before.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/category/filters-sidebar.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/category/subcategories-filter.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/default/body-end.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/default/body-start.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/default/content-after.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/default/content-before.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/default/content.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/default/head-after.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/default/head-before.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/homepage/body-end.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/homepage/body-start.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/homepage/content-after.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/homepage/content-before.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/homepage/content.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/homepage/features-after.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/homepage/features-before.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/homepage/footer-after.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/homepage/footer-before.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/homepage/head-after.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/homepage/head-before.md`
- `app/code/Weline/Theme/doc/hook/frontend/layouts/homepage/header-after.md`
- `... 另有 145 个文档，请按任务在该模块 doc/ 下继续查找`

## 开发前门禁

- 先声明本次任务命中的模块、代码面和应读文档；没有命中文档时先补读源码，不要按通用经验猜。
- 涉及浏览器前后端业务请求时，只能使用 `Weline.Api.resource()`、`Weline.Api.graph()` 或 `Weline.Api.stream()`。
- 涉及跨模块读数据时，先查 `php bin/w query:help <provider|Weline_Theme> [operation]` 或对应 `w_query` 帮助。
- 涉及模板、主题、slot、widget、taglib 或 `view/theme` 时，必须先读 `app/code/Weline/Theme/doc/AI-INDEX.md`。
- 禁止直接修改 `generated/`、`view/tpl/`、`routes.xml` 或复制旧文档里的过时路径。
- 如果本文件与源码冲突，以源码为准，并在同次任务中修正模块文档。
