---
name: http-request-testing
description: |
  HTTP 请求测试命令 http:req - 用于快速验证路由和接口。
  作为单元测试的辅助手段，不要单独为此创建测试文件。
  
  触发词：http:req, http:request, 路由测试, 接口测试, API 测试, 404, 500,
  测试路由, 测试接口, 测试页面, -b, -api, 后台测试, filter=
globs: []
alwaysApply: false
---

# HTTP Request Testing（http:req）

**定位**：作为单元测试的辅助手段，快速验证 HTTP 路由和接口。

**注意**：这不是主要测试方式，详细测试请使用 PHPUnit 单元测试（见 `php-unit-testing` 技能）。

## 命令概述

```bash
# 基本语法
php bin/w http:req <path> [options]

# 查看帮助
php bin/w http:req -h
```

**注意**：`path` 为必填参数，URL 由 `env.php` 中 `server.host` + `server.port` 自动拼接。

## 常用场景

### 1. 测试前端路由

```bash
php bin/w http:req /
php bin/w http:req category/view
```

### 2. 测试后台路由（自动登录）

```bash
php bin/w http:req admin/dashboard -b
php bin/w http:req ai/backend/model -b
```

### 3. 测试 API 接口（自动登录）

```bash
php bin/w http:req rest/v1/data -api
php bin/w http:req rest/v1/users -api
```

### 4. 搜索响应内容

```bash
php bin/w http:req / filter=welcome
php bin/w http:req admin/dashboard -b filter=Dashboard
php bin/w http:req admin/dashboard -b filter=Warning  # 检查 PHP 警告
```

### 5. POST 请求

```bash
php bin/w http:req api/data -m=POST -d='{"key":"value"}'
```

### 6. 并发测试

```bash
php bin/w http:req / -C -t=100
```

## 选项速查

| 选项 | 说明 |
|------|------|
| `-b` | 后台路由，自动登录 |
| `-api` | API 路由，自动登录 |
| `-m=METHOD` | HTTP 方法（GET/POST/PUT/DELETE） |
| `-d='...'` | 请求数据 |
| `filter=xxx` | 搜索响应内容 |
| `-n=5` | filter 上下文行数 |
| `-C -t=N` | 并发 N 次 |
| `-u=user` | 登录用户名 |
| `-p=pass` | 登录密码 |

## 与单元测试结合

在 PHPUnit 测试中调用 http:req：

```php
public function testRouteAccessible(): void
{
    $output = shell_exec('php bin/w http:req admin/dashboard -b 2>&1');
    $this->assertStringContainsString('200', $output);
}
```

## 最佳实践

1. **不懂先查帮助**：`php bin/w http:req -h`
2. **后台用 -b**：自动处理登录和 Cookie
3. **API 用 -api**：自动处理 API 认证
4. **检查错误用 filter**：`filter=Warning` 或 `filter=Fatal`
