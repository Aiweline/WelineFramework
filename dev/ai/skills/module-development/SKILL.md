---
name: module-development
description: 模块开发极简技能。目录模板、register/menu/env、#[Col] 字段升级、setup:upgrade、常用命令。
globs:
  - "app/code/**/*.php"
  - "**/register.php"
  - "**/etc/**/*.xml"
  - "**/etc/env.php"
alwaysApply: false
---

# module-development（极简版）

## 何时使用

- 模块开发、控制器、模型、菜单
- env、升级、测试

## 必做

- 字段/索引变更：改 Model #[Col]/#[Index]，执行 `php bin/w setup:upgrade`（禁止在 Upgrade 做字段 CRUD）
- 后台功能需：Controller/Backend + view + menu.xml + env.php
- 用户可见文案用 `__()` 或 `<lang>`，词条进 i18n/*.csv；模块间数据用 w_query()

## 最小示例

```bash
php bin/w setup:upgrade
php bin/w setup:upgrade --route
php bin/w phpunit:run -b Vendor_ModuleName
```

## 禁止

- 禁止改 generated/、在 Upgrade 做字段 CRUD、手写 routes.xml
