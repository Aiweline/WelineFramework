---
name: testing
description: 测试与质量。PHPUnit(test/Unit/)、http:req 路由验证、前端 Browser MCP/Playwright、QA 校验。禁止单独建测试脚本。
globs:
  - "**/test/**/*.php"
  - "**/Test/**/*.php"
  - "**/test/e2e/**/*.js"
alwaysApply: false
---

# testing（极简版·单元+HTTP+前端+QA）

## 何时使用

- 单元测试、验证、功能测试、e2e、playwright、http:req、QA、代码审查

## 1) PHPUnit

- 测试放模块 test/Unit/；`php bin/w phpunit:run --module=X`；后台加 `-b`；优先单元测试

## 2) http:req

- 快速验证路由/接口；`php bin/w http:req -h`；后台 `-b`，API `-api`；filter= 搜响应；不单独建测试文件

## 3) 前端 UI 测试

- Browser MCP / Playwright 仅前端 UI；后端用 PHPUnit 或 http:req

## 4) QA

- 功能完成前必须验证；单元/集成/手动/浏览器；主动发现问题再提交

## 最小示例

```bash
php bin/w phpunit:run -b Vendor_Module
php bin/w http:req admin/dashboard -b
```

## 禁止

- 功能未验证就提交；为功能单独建测试脚本；前端跳过 UI 测试
