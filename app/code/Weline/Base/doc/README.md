# Weline_Base

## 模块定位

- 模块代码：`Weline_Base`
- 目录：`app/code/Weline/Base`
- 当前状态：非运行时注册模块；作为默认发行模块集的 Composer 聚合包。

## 代码面概览

入口文件：
- `app/code/Weline/Base/composer.json`

- `view/tpl`：模板编译/生成产物。 文件数：0

## 开发关注点

- PHP 平台要求与全部注册 Weline 模块保持一致，当前为 `^8.4`。
- 模块 Composer 包名使用 `weline/module-{kebab-case-module}`，仅 Framework 使用 `weline/framework`。
- 模块包更名时必须同步本聚合包，不得继续依赖旧别名。
- Path repository 只负责发现本地包；是否纳入默认发行集以 Base `composer.json` 的 `require` 为准。

## 维护边界

- 本包没有运行时代码、模块注册或数据库升级；不要把业务逻辑放入此目录。
- 默认发行模块的增删或包名变更必须同步 `composer.json`，并通过 Composer 依赖解析验证。
- 某模块存在于 path repository 不代表进入默认发行集；是否默认安装以本包 `require` 为准。
- 长期需求和依赖集变更分别记录在 [需求](需求.md) 与 [开发日志](开发日志.md)。
