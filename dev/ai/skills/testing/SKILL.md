---
name: testing
description: 测试与质量。TDD、PHPUnit(`test/Unit/`)、`http:req` 路由验证、前端 Browser MCP/Playwright、QA、E2E 收口。禁止单独创建测试脚本。
globs:
  - "**/test/**/*.php"
  - "**/Test/**/*.php"
  - "**/test/e2e/**/*.js"
alwaysApply: false
---

# testing（极简版：TDD + 单元 + HTTP + 前端 + QA）

## 何时使用

- 单元测试、验证、功能测试、e2e、Playwright、http:req、QA、代码审查

## 0) TDD 流程

- 先定义失败用例、失败路由验证口或明确的验收断言，再写实现；按 Red -> Green -> Refactor 小步推进
- 如果场景暂时无法先写自动化测试，也要先把失败条件、验收口和原因写进当前任务的 `plan.md` / `result.md`

## 1) PHPUnit

- 测试放模块 `test/Unit/`
- `php bin/w phpunit:run --module=Vendor_Module`
- 后台上下文加 `-b`
- 优先单元测试，再补更重的验证

## 2) http:req

- 快速验证路由、接口：`php bin/w http:req -h`
- 后台加 `-b`，API 加 `-api`
- 可用 `--filter=` 搜错误或关键输出
- 不单独创建临时测试脚本替代框架内验证口

## 3) 前端 UI 测试

- Browser MCP / Playwright 仅用于前端 UI；后端优先 PHPUnit 或 `http:req`
- 涉及用户主路径、后台关键操作链路、跨模块集成流程时，默认补齐对应 e2e
- 如果暂不具备 e2e 条件，必须在当前任务 `result.md` 明确缺口、风险和补测计划

## 4) QA

- 功能完成前必须验证；单元 / 集成 / 手动 / 浏览器 组合使用
- 主动发现问题再提交，不要把“未验证”包装成“已完成”

## 最小示例

```bash
php bin/w phpunit:run -b Vendor_Module
php bin/w http:req admin/dashboard -b
```

## 禁止

- 功能未验证就提交
- 为功能单独创建测试脚本
- 前端改动跳过 UI 测试
