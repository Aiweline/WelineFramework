# 全局约束（跨技能只写一次）

各技能只写本技能特有内容，以下约束统一引用此文，避免重复。

## 禁止（全局）

- **`.phtml` 模板文件禁止写 `declare(strict_types=1)`** → `.phtml` 由框架运行时作为独立脚本加载/编译，文件头可能存在 hash 注释或空白字符，导致 `strict_types` 不是"第一行语句"而触发 `E_COMPILE_ERROR`，使 WLS Worker 崩溃（见 runtime-and-process）
- **在 w: 标签 / 自定义 Taglib 标签 的属性中写 `<?= ?>` 或 `<?php ?>`** → 必须用 `@lang{xxx}` 或 `@lang(xxx)`，否则标签解析会报 `unexpected identifier` 等 ParseError（见 i18n-internationalization）
- 改 `generated/`；用 `error_log()`/echo/print 打错误
- `alert()` / `confirm()` / `prompt()` → 用 BackendToast / BackendConfirm（见 friendly-notifications）
- 硬编码用户可见文本 → 用 `__()`、`<lang>`、i18n/*.csv（见 i18n-internationalization）
- 在 Setup/Upgrade.php 做字段 CRUD → 字段用 #[Col] + setup:upgrade（见 database-model-standards）
- `routes.xml` → 用 `setup:upgrade --route`（见 weline-routing）
- 事件 dispatch 第二参数写字面量 → 必须变量（见 create-event）
- 编造/猜测框架方法 → 先查再用（见 framework-method-validation）
- **WLS 环境下直接使用阻塞函数** → 必须用 `SchedulerSystem`：
  - `\usleep()` → `SchedulerSystem::yieldDelay()`
  - `\sleep()` → `SchedulerSystem::sleep()`
  - `\die()` / `\exit()` → `throw new \RuntimeException()`（见 runtime-and-process）

## 必做（全局）

- 用户可见文案：`__()` 或 `<lang>`，词条进 i18n/*.csv
- 表结构变更：Model #[Col]/#[Index]，执行 `php bin/w setup:upgrade`
- 新增控制器后：`php bin/w setup:upgrade --route`
