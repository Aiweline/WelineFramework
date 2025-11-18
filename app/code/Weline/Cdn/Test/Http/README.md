# Weline_Cdn HTTP集成测试

## 概述

本目录包含使用 `http:request` 命令进行的HTTP集成测试脚本，用于测试Controller层的HTTP接口。

## 测试文件

### Backend Controller测试

1. **AccountHttp.script.php** - 账户管理控制器测试
   - GET /cdn/backend/account/index
   - GET /cdn/backend/account/form
   - POST /cdn/backend/account/save
   - POST /cdn/backend/account/setDefault
   - GET /cdn/backend/account/domains
   - POST /cdn/backend/account/delete

2. **DomainHttp.script.php** - 域名管理控制器测试
   - GET /cdn/backend/domain/index
   - GET /cdn/backend/domain/form
   - POST /cdn/backend/domain/save
   - POST /cdn/backend/domain/toggleEnable
   - POST /cdn/backend/domain/clearCache
   - POST /cdn/backend/domain/delete

3. **RulesHttp.script.php** - 规则管理控制器测试
   - GET /cdn/backend/rules/index
   - GET /cdn/backend/rules/getGlobalRules
   - POST /cdn/backend/rules/saveGlobalRules
   - GET /cdn/backend/rules/getDomainRules
   - POST /cdn/backend/rules/saveDomainRules
   - POST /cdn/backend/rules/import
   - POST /cdn/backend/rules/push

4. **WarmupHttp.script.php** - 预热管理控制器测试
   - GET /cdn/backend/warmup/index
   - GET /cdn/backend/warmup/statistics
   - POST /cdn/backend/warmup/execute
   - POST /cdn/backend/warmup/toggleEnable
   - POST /cdn/backend/warmup/delete

### API Controller测试

5. **ClearApiHttp.script.php** - 缓存清理API测试
   - POST /api/cdn/clear (everything模式)
   - POST /api/cdn/clear (urls模式)
   - POST /api/cdn/clear (hosts模式)
   - POST /api/cdn/clear (tags模式)
   - 验证错误处理

## 运行测试

### 运行单个测试文件

```bash
# 测试账户管理
php app/code/Weline/Cdn/Test/Http/Backend/AccountHttp.script.php

# 测试域名管理
php app/code/Weline/Cdn/Test/Http/Backend/DomainHttp.script.php

# 测试规则管理
php app/code/Weline/Cdn/Test/Http/Backend/RulesHttp.script.php

# 测试预热管理
php app/code/Weline/Cdn/Test/Http/Backend/WarmupHttp.script.php

# 测试API接口
php app/code/Weline/Cdn/Test/Http/Api/ClearApiHttp.script.php
```

### 运行所有测试

```bash
# Windows PowerShell
Get-ChildItem -Path "app/code/Weline/Cdn/Test/Http" -Recurse -Filter "*.script.php" | ForEach-Object { php $_.FullName }

# Linux/Mac
find app/code/Weline/Cdn/Test/Http -name "*.script.php" -exec php {} \;
```

### 使用http:request命令直接测试

```bash
# 测试账户列表（自动登录）
php bin/w http:request cdn/backend/account/index -b

# 测试API清理缓存
php bin/w http:request api/cdn/clear -api -m=POST -d="domain=example.com&mode=everything"

# 测试并搜索响应内容
php bin/w http:request cdn/backend/domain/index -b filter="域名管理"
```

## http:request命令说明

### 基本用法

```bash
# 后端路径（自动登录）
php bin/w http:request <path> -b

# API路径（自动登录）
php bin/w http:request <path> -api

# POST请求
php bin/w http:request <path> -b -m=POST -d="key=value"

# 搜索响应内容
php bin/w http:request <path> -b filter="关键词"
```

### 选项说明

- `-b, -backend`: 指定为后端路径，自动登录并使用admin密钥
- `-api, -api-backend`: 指定为API后端路径，自动登录并使用api_admin密钥
- `-m, method=<方法>`: HTTP请求方法（GET, POST, PUT, DELETE等）
- `-d, data=<数据>`: POST/PUT数据（表单格式或JSON）
- `-H, header=<头>`: 自定义HTTP请求头
- `filter=<关键词>`: 搜索响应中包含关键词的内容
- `-n=<行数>`: 提取的上下文行数（默认3行）

## 测试注意事项

### 1. 自动登录

使用 `-b` 或 `-api` 参数时，命令会自动登录后台，无需手动指定登录信息。

### 2. Cookie管理

命令会自动保存cookie到 `var/http_request_cookies.txt`，过期时自动重新登录。

### 3. 测试数据

某些测试可能会因为数据库中没有数据而失败，这是正常的。测试主要验证：
- 路由是否正确
- 控制器是否可访问
- 参数验证是否正确
- 错误处理是否正常

### 4. 依赖服务

某些测试需要：
- 数据库连接
- CDN账户配置（测试清理缓存功能时）
- 有效的API Token（测试CDN操作时）

如果这些依赖不存在，测试会失败，但这是预期的。

## 测试结果

测试脚本会输出：
- 执行的命令
- 返回码（0表示成功，非0表示失败）
- 测试结果（SUCCESS或FAILED）

## 集成到CI/CD

可以在CI/CD流程中运行这些测试：

```bash
# 运行所有HTTP测试
php app/code/Weline/Cdn/Test/Http/Backend/AccountHttp.script.php
php app/code/Weline/Cdn/Test/Http/Backend/DomainHttp.script.php
php app/code/Weline/Cdn/Test/Http/Backend/RulesHttp.script.php
php app/code/Weline/Cdn/Test/Http/Backend/WarmupHttp.script.php
php app/code/Weline/Cdn/Test/Http/Api/ClearApiHttp.script.php
```

## 参考

- [http:request命令帮助](../../../../docs/dev/开发文档.md#httprequest-命令)
- [路由系统说明](../../../../docs/dev/开发文档.md#路由系统详解)
- [HTTP测试最佳实践](../../../../AI 测试提示词.md#HTTP请求测试)

