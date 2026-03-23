---
name: code-generation-standards
description: 代码生成规范。PHP 严格类型、ObjectManager、i18n、禁止发明框架方法；强调框架优先、SOLID、可测试性。前端必须参考 `theme-development`。
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
- 写前端代码（必须同时读 `theme-development`）
- 创建模块或任何代码生成场景

## 必做

- 用前验证方法存在（grep/search），禁止 invent 框架方法
- 用户可见文案用 `__()` 或 `<lang>`，词条进 `i18n/*.csv`；标签属性用 `@lang{xxx}` 等静态标签，禁止写 `<?= ?>` 干扰标签解析
- 实例用 `ObjectManager::getInstance(Class::class)`；Model 用 `getInstance(,,false)`；控制器构造函数注入
- 文件头 `declare(strict_types=1)`；遵循框架模式；测试放 `Test/Unit/`
- 框架优先 + SOLID：Controller/Console 只负责编排输入输出，业务规则下沉到 Service，Model 聚焦持久化与实体行为；依赖抽象，避免巨型类和跨层混写
- 为 TDD 设计：新增逻辑优先提炼为可单测的服务或协作者，不把关键业务判断塞进模板、控制器或命令入口
- 注释中的 PHP 结束符（硬性）：
  1. `//` 单行注释里禁止写 `?>`，PHP 仍会把它当作结束标签而截断 `<?php` 代码块
  2. `/** ... */` 块注释里禁止出现会闭合注释的 `*/` 子串；说明路径时写 `backend/foo`，不要拼出 `*/xxx`

## 最小示例

```php
$id = (int)$this->request->getParam('id', 0);
$eventData = ['data' => $data];
$this->eventsManager->dispatch('vendor_module_event', $eventData);
```

## 禁止

- 编造、猜测框架方法；用其他框架 API；硬编码用户可见文本
- 直接 `new` 代替 ObjectManager；Model 单例污染
- 没有 ORM 时写原生 SQL
- 注释中写 `<?`、`?>`、`<?php`，或在块注释里写 `*/xxx`
