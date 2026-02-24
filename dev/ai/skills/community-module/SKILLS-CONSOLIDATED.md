# 社区模块开发 - 技能精简汇总

本文件汇总社区模块开发所需技能要点，计划中引用此文件。

## 1. 模块开发 (module-development)

- **依赖注入**：构造函数注入，三步骤（声明属性 → 参数注入 → 赋值）
- **目录结构**：`Controller/`, `Model/`, `Service/`, `etc/env.php`, `etc/backend/menu.xml`, `view/templates/`, `i18n/`, `Test/Unit/`
- **后台菜单**：`menu.xml` 必含 source/name/title/action/parent/icon/order，action 用 `*/backend/控制器/方法`
- **版本升级**：修改 upgrade() 必须同步更新 register.php 版本号，执行 `s:up`
- **ModelSetup**：`tableExist()`, `hasField()`, `hasIndex()`, `createTable()`, `alterTable()`, `addColumn()`, `addIndex()`
- **测试**：Unit (PHPUnit) + E2E (Playwright) 必须

## 2. 代码生成规范 (code-generation-standards)

- **禁止**：发明框架方法、硬编码用户可见文本、使用其他框架模式
- **必须**：`declare(strict_types=1);`、验证方法存在、使用 `__()` 翻译
- **命名**：Controller 方法 PascalCase，路由驼峰转短横线
- **ORM**：使用框架查询方法，禁止业务代码写 SQL 方言

## 3. 数据库模型 (database-model-standards)

- **ORM fetch()**：select/insert/update/delete 后必须 `fetch()` 执行
- **方言 SQL**：禁止在业务代码中写；仅可在 `Connection/Adapter/{DB}/` 适配器中写
- **JOIN**：使用 `joinModel()` 或 `join()`
- **索引/字段**：用 `hasIndex()`, `hasField()` 检查后 `alterTable()->addIndex/addColumn()->alter()`

## 4. 国际化 (i18n-internationalization)

- **占位符**：必须用 `%{1}`、`%{name}` 带花括号，禁止 `%1`
- **模板**：无参数用 `<lang>`，有参数用 `__()` 或 `<lang args="...">`
- **文件**：`i18n/zh_Hans_CN.csv`, `i18n/en_US.csv`，执行 `i18n:collect`
- **规则**：所有用户可见文本必须 i18n

## 5. 路由 (weline-routing)

- **HTTP 前缀**：get→GET, post→POST, put→PUT, delete→DELETE
- **命名**：避免路径含 get/post/delete；删除建议用 `postRemove` 而非 `postDelete`
- **URL**：`$this->getUrl('module/controller/action')`，Observer 中注入 `Url` 用 `getBackendUrl()`
- **POST 数据**：用 `$this->request->getBodyParams()`，支持 JSON

## 6. 事件 (create-event)

- **命名**：`ModuleName::type::event_name`
- **规约**：`event.php` 定义事件，`etc/event.xml` 注册 Observer
- **dispatch**：数据用变量传，包装在 `data` 键下；Observer 实现 `ObserverInterface`

## 7. Hook (create-hook)

- **规约**：`hook.php` 定义，格式 `ModuleName::area::type::component::position`
- **实现**：`view/hooks/ModuleName/area/type/component/position.phtml`
- **文档**：`doc/hook/` 对应路径

## 8. Extends (create-extends)

- **定义**：`extends.php` 声明扩展点，`extends.md` 文档
- **实现**：其他模块在 `extends/module/{ModuleName}/{ExtensionPoint}/` 下实现接口

## 9. 主题/前端 (theme-development)

- **CSS**：必须用主题变量 `var(--backend-color-*)`，禁止硬编码颜色
- **暗色模式**：使用 `_dark.css` / `_light.css` 变量
- **JS**：模块化加载，API 用 fetch + JSON

## 10. 通知 (friendly-notifications)

- **禁止**：`alert()`, `confirm()`, `prompt()`
- **使用**：AdminToast、FrontendToast 或自定义 Modal

## 11. WLS 状态管理 (wls-state-management)

- **static 变量**：持有请求级数据（URL、用户、递归标志）必须注册 StateManager 重置
- **进程级缓存**：反射、配置等无需重置，加注释说明
