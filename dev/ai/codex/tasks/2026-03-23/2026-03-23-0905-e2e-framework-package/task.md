# Task: e2e framework package

- Task ID: 2026-03-23-0905-e2e-framework-package
- Started: 2026-03-23 09:05
- Status: in_progress
- Owner: Codex
- Source: user: 用例测试，抽象e2e封包，自动收集模块e2e用例并统一地址/代理/认证差异

## Goal

- 给框架测试模块抽象一层可复用的 E2E 封包，统一处理模块用例自动收集、实时运行时地址解析、稳定代理入口、后台/API 认证与 URL helper。
- 修正现有 E2E 用例中硬编码地址、硬编码后台前缀、错误假设 9981/`/admin` 可直达等问题，让用例尽量只关注业务行为。

## Scope

- In scope:
- `tests/e2e` 下的 Playwright 配置、收集脚本、启动脚本、公共 helper / 封包实现
- 挑选并修正一批当前明显错误的 E2E 用例，使其接入统一封包
- 如有必要，补充 `modules.json` / 文档说明以支撑自动发现与使用方式
- Out of scope:
- 修改业务模块自身页面逻辑以迎合错误用例
- 处理与本任务无关的现有工作树脏文件

## Constraints

- 优先保持现有 Playwright 使用方式，允许相对路径与外部绝对地址并存
- E2E 运行时必须优先读取实时服务器信息，不能继续默认假设 `http://127.0.0.1:9981`
- 尽量把框架差异收口在封包内，而不是散落到各个 spec

## Related Plans

- `docs/dev/E2E测试用例模块化方案.md`
- `docs/dev/E2E测试用例模块化实施总结.md`

## Related Files

- `tests/e2e/collect-tests.js`
- `tests/e2e/playwright.config.js`
- `tests/e2e/start.js`
- `tests/e2e/specs/backend/helpers/ai-workbench.js`
- `tests/e2e/specs/backend/*.spec.js`
- `app/code/*/test/e2e/**/*.spec.js`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
