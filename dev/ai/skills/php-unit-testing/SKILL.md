---
name: php-unit-testing
description: PHP unit testing with PHPUnit in Weline Framework. Use when writing tests, testing models/controllers/services, or running phpunit:run command. Covers TDD workflow, test structure, assertions. 测试, test, phpunit, unit test.
globs:
  - "**/tests/**/*.php"
  - "**/*Test.php"
alwaysApply: false
---

# PHP Unit Testing in Weline Framework

This skill enforces PHP unit testing using PHPUnit for all backend PHP code. **Unit testing is MANDATORY** - all PHP features must follow TDD workflow.

## 馃敟 CRITICAL: PHP Unit Testing is Mandatory

**Every PHP feature MUST follow TDD workflow:**
1. Write tests first (ensure they fail)
2. Implement feature to make tests pass
3. Refactor while keeping tests passing
4. **NO EXCEPTIONS**

**Note**: For frontend testing, see `frontend-automation-testing` skill. For HTTP endpoint testing, see `http-request-testing` skill.

## HTTP Request Testing

For testing HTTP endpoints, routes, and API responses, use the `http:req` command (alias for `http:request`). See the [HTTP Request Testing Skill](../http-request-testing/SKILL.md) for complete documentation.

### Quick Examples
```bash
# Test frontend route
php bin/w http:req /

# Test backend route (auto login)
php bin/w http:req admin/dashboard -b

# Test API endpoint (auto login)
php bin/w http:req rest/v1/data -api

# Search for content in response
php bin/w http:req / filter=welcome

# Test with content filtering
php bin/w http:req admin/dashboard -b filter="Dashboard"
```

### Integration with Unit Tests
Combine unit tests with HTTP request testing:
1. Write unit tests for business logic (models, services)
2. Use `http:req` to test routes and endpoints
3. Verify integration between components

## PHP Unit Testing Focus

This skill focuses on **PHP backend unit testing** using PHPUnit. 

## Test Types for PHP Code

### 1. Unit Tests (Primary Focus)

**Location**: `Test/Unit/` directory (PSR-4)

**Purpose**: Test individual PHP components in isolation

## 常见问题与排查

### PHPUnit 10.x 参数不兼容
- 现象：`Unknown option "--no-interaction"`
- 处理：检查 `phpunit:run` 命令拼接参数，移除该选项

### 只运行单个测试文件
- 推荐：使用模块 + 文件定位，避免全量扫描
```bash
php bin/w phpunit:run -b --module=Weline_DeveloperWorkspace --name=Unit/Service/DocumentScannerTest.php
php bin/w phpunit:run -b --module=Weline_DeveloperWorkspace --name=Integration/DocumentScannerIntegrationTest.php
```

### 内存溢出
- 现象：`Allowed memory size ... exhausted`
- 处理：避免 `scanAllModules` 类全量测试；优先运行指定文件或优化清理逻辑
