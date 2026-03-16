---
name: weline-routing
description: 路由与 URL。URL 结构、getUrl/getBackendUrl、env.php router、404/405、WELINE_USER_LANG/CURRENCY。
globs:
  - "**/Controller/**/*.php"
  - "**/Http/Url.php"
alwaysApply: false
---

# weline-routing（极简版）

## 何时使用

- 创建控制器、定义路由
- 生成 URL（getUrl、getBackendUrl）
- 修复 404/405、语言/货币解析错误
- 理解 URL 结构

## 必做

- URL 结构：`/<backendKey>/<currency>/<language>/<module>/<area>/<controller>/<action>`
- 控制器用 `$this->getUrl()`、`$this->getBackendUrl()`
- 模块路由在 etc/env.php 配置 router、backend_router
- 新增控制器后执行 `php bin/w setup:upgrade --route`

## 最小示例

```php
$url = $this->getUrl('module/controller/action');
$backendUrl = $this->getBackendUrl('module/backend/controller/action');
```

## 禁止

- 手写 routes.xml（用 setup:upgrade --route）；硬编码 URL 路径
