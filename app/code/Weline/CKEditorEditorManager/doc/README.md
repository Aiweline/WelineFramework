<!-- weline:module-readme:auto-generated -->
# Weline_CKEditorEditorManager 模块文档

> 本 README 由 `dev/ai/scripts/generate-missing-module-readmes.php` 根据当前代码结构自动生成。它提供模块级结构说明和开发入口，不替代后续人工补充的业务规则、接口契约和专项设计文档。

## 当前入口

开发前先读：

1. `app/code/Weline/CKEditorEditorManager/doc/AI-INDEX.md`
2. `dev/ai/diagrams/08-module-docs-index.txt`
3. `dev/ai/global-constraints.md`
4. `app/code/Weline/Theme/doc/AI-INDEX.md`
5. `app/code/Weline/Frontend/doc/AI-INDEX.md`

## 模块定位

- 模块代码：`Weline_CKEditorEditorManager`
- 目录：`app/code/Weline/CKEditorEditorManager`
- 当前状态：结构化模块概览已补齐；稳定业务规则仍应继续沉淀到本模块 `doc/`。

## Dependency Inventory

- Backend 和 EditorManager 是必需依赖。
- CKEditor 通过 `editor_manager.Weline_CKEditorEditorManager` 编译 Provider 向 EditorManager 注册；EditorManager 不得反向依赖或按类名探测本模块。
- CKEditor 的适配器与 Block 只继承 `Weline\EditorManager\Api\Editor\*` 公共基类，不再引用 EditorManager 根类或 `Block` 内部实现。

## 代码面概览

入口文件：
- `app/code/Weline/CKEditorEditorManager/composer.json`

- `Block`：视图数据块与模板输出辅助层。 文件数：1
- `Setup`：安装/升级装配。 文件数：2
- `etc`：模块配置。 文件数：1
- `i18n`：国际化资源。 文件数：2
- `view/blocks`：区块模板/片段视图。 文件数：1
- `view/statics`：浏览器静态资源源文件。 文件数：80
- `view/tpl`：模板编译/生成产物。 文件数：0

## 开发关注点

- 存在模板源文件；出现页面问题时先追源码，不要直接改 `view/tpl`。
- 存在浏览器静态资源；业务请求必须走 `Weline.Api.*`，不要直接写 raw fetch/ajax。
- 存在 `i18n`，用户可见文案改动要同步 `zh_Hans_CN.csv` 与 `en_US.csv`。

## 本模块文档资产

- 当前除 `AI-INDEX.md` 外没有其他模块文档。后续一旦涉及稳定行为、接口或配置约定，请把长期说明补到本目录。

## 维护规则

- 不直接修改 `generated/`、`view/tpl/`、`routes.xml`。
- 涉及浏览器业务请求时，只使用 `Weline.Api.*` / QueryProvider 链路。
- 涉及字段结构时，用 `#[Col]` / `#[Index]` 和 `php bin/w setup:upgrade`。
- 涉及控制器路由时，用 `php bin/w setup:upgrade --route`。
- 本 README 目前是结构稿；后续功能稳定后，应继续补模块职责、关键流程、接口与反例。
