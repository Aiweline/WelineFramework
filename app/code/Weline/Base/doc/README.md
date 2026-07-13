<!-- weline:module-readme:auto-generated -->
# Weline_Base 模块文档

> 本 README 由 `dev/ai/scripts/generate-missing-module-readmes.php` 根据当前代码结构自动生成。它提供模块级结构说明和开发入口，不替代后续人工补充的业务规则、接口契约和专项设计文档。

## 当前入口

开发前先读：

1. `app/code/Weline/Base/doc/AI-INDEX.md`
2. `dev/ai/diagrams/08-module-docs-index.txt`
3. `dev/ai/global-constraints.md`

## 模块定位

- 模块代码：`Weline_Base`
- 目录：`app/code/Weline/Base`
- 当前状态：非运行时注册模块，作为根项目安装默认发行模块集的 Composer 聚合包。

## 代码面概览

入口文件：
- `app/code/Weline/Base/composer.json`

- `view/tpl`：模板编译/生成产物。 文件数：0

## 开发关注点

- PHP 平台要求与全部注册 Weline 模块保持一致，当前为 `^8.4`。
- 模块 Composer 包名使用 `weline/module-{kebab-case-module}`，仅 Framework 使用 `weline/framework`。
- 模块包更名时必须同步本聚合包，不得继续依赖旧别名。
- Path repository 只负责发现本地包；是否纳入默认发行集以 Base `composer.json` 的 `require` 为准。

## 本模块文档资产

- 当前除 `AI-INDEX.md` 外没有其他模块文档。后续一旦涉及稳定行为、接口或配置约定，请把长期说明补到本目录。

## 维护规则

- 不直接修改 `generated/`、`view/tpl/`、`routes.xml`。
- 涉及浏览器业务请求时，只使用 `Weline.Api.*` / QueryProvider 链路。
- 涉及字段结构时，用 `#[Col]` / `#[Index]` 和 `php bin/w setup:upgrade`。
- 涉及控制器路由时，用 `php bin/w setup:upgrade --route`。
- 本 README 目前是结构稿；后续功能稳定后，应继续补模块职责、关键流程、接口与反例。
