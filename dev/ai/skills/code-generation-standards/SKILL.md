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
- 用户可见文案用 `__()` 或 `<lang>`，词条进 i18n/*.csv
- 实例用 ObjectManager::getInstance(Class::class)；Model 用 getInstance(,,false)；控制器构造函数注入
- 文件头 declare(strict_types=1)；遵循框架模式；测试放 Test/Unit/；SOLID（单职责、依赖抽象）
- 注释中禁止写 <?、?>、<?php

## 最小示例

```php
$id = (int)$this->request->getParam('id', 0);
$eventData = ['data' => $data];
$this->eventsManager->dispatch('vendor_module_event', $eventData);
```

## 禁止

- 编造/猜测框架方法、用其他框架 API；硬编码用户可见；直接 new 代替 ObjectManager；Model 单例污染
- 有 ORM 时写原生 SQL；注释中写 PHP 标签
