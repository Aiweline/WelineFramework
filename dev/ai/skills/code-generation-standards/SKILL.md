---
name: code-generation-standards
description: 代码生成规范。PHP 严格类型、ObjectManager、__()、禁止 invent 方法。前端必须参考 theme-development。
globs:
  - "**/*.php"
  - "**/view/**/*.phtml"
  - "**/view/**/*.js"
  - "**/view/**/*.css"
alwaysApply: false
---

# code-generation-standards（极简版）

## 何时使用

- 创建 PHP 文件、控制器、模型、服务、Block
- 写前端代码（必须同时读 theme-development）
- 创建模块或任何代码

## 必做

- 用前验证方法存在（grep/search），禁止 invent 方法
- 用户可见文案用 `__()` 或 `<lang>`，词条进 i18n/*.csv；标签属性用 `@lang{xxx}` 等静态标签，禁止写 `<?= ?>` 干扰标签解析
- 实例用 ObjectManager::getInstance(Class::class)；Model 用 getInstance(,,false)；控制器构造函数注入
- 文件头 declare(strict_types=1)；遵循框架模式；测试放 Test/Unit/；SOLID（单职责、依赖抽象）
- **注释与 PHP 结束符（硬性）**：（1）`//` 单行注释内**禁止**写 `?>`，PHP 仍会当作结束标签从而**截断** `<?php` 块。（2）`/** … */` 块注释内**禁止**出现会闭合注释的 **`*/`** 子串（例如路径不要写成紧接在星号后的斜杠再跟 path，易与 `*/` 混淆）；说明路由时写 `backend/foo`、或拆成多行 `//`，勿在注释里拼类似 `*` + `/` + `path` 的连续片段

## 最小示例

```php
$id = (int)$this->request->getParam('id', 0);
$eventData = ['data' => $data];
$this->eventsManager->dispatch('vendor_module_event', $eventData);
```

## 禁止

- 编造/猜测框架方法、用其他框架 API；硬编码用户可见；直接 new 代替 ObjectManager；Model 单例污染
- 有 ORM 时写原生 SQL；注释中写 `<?`、`?>`、`<?php`；块注释里写 `*/xxx`；`//` 注释里写 `?>`
