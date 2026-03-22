# Websites AI工作台 Epic 2：核心持久化模型与服务

- Started: 2026-03-22 14:30
- Status: completed
- Related Plan:
  - `dev/ai/codex/AI工作台/Websites-AI建站工作台-任务拆解.task.md`
  - `dev/ai/codex/AI工作台/Websites-AI建站工作台-接口草图.md`
- Depends On:
  - commit `df84162f` (`Add Websites AI workbench registries`)

## Goal

落地 Epic 2 的核心持久化层：

1. 新增 `AiSiteBuilderSession`
2. 新增 `AiSiteBuilderMessage`
3. 新增 `AiSiteBuilderArtifact`
4. 新增 `AiSiteBuilderEvent`
5. 新增围绕四个模型的服务封装，供后续控制器 / SSE / provider 直接使用
6. 以 TDD 方式补齐服务级单测

## Working Scope

- 目标目录：
  - `app/code/Weline/Websites/Model/*`
  - `app/code/Weline/Websites/Service/AiWorkbench/*`
  - `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/*`
  - `dev/ai/codex/AI工作台/Websites-AI建站工作台-进度.md`
- 暂不进入：
  - 后台控制器与 UI
  - SSE 控制器
  - 默认 provider 的会话编排
  - Theme source 真实实现

## Design Notes

1. session 只保存平台共识字段，不保存 PageBuilder 私有字段。
2. message / artifact / event 分表，避免 session 过载。
3. artifact 用 `(session_id, artifact_type, artifact_code)` 唯一语义做 upsert。
4. event 以 append-only 方式服务 SSE 与审计。
5. 先做服务封装，不把控制器逻辑提前混入。

## Progress Log

- 2026-03-22 14:30
  - Epic 1 已提交。
  - 开始收集 `Model #[Col]` 规范、PageBuilder 现有会话模型与服务模式。
  - 准备先写 Epic 2 服务级测试，再落模型与服务。
- 2026-03-22
  - 已完成四个核心持久化模型：`AiSiteBuilderSession`、`AiSiteBuilderMessage`、`AiSiteBuilderArtifact`、`AiSiteBuilderEvent`。
  - 已完成四个服务封装：`SessionService`、`MessageService`、`ArtifactService`、`EventStreamService`。
  - 已补齐 `ArtifactServiceTest` 与 `EventStreamServiceTest`，并修正测试基类 `setUp()/tearDown()` 可见性以匹配 `TestCore`。
  - `setup:upgrade -m Weline_Websites --yes` 首次执行被现存生成文件 `generated/routers/backend_pc.php` 的语法损坏阻塞；隔离旧生成文件后再次执行升级成功。
  - Epic 2 定向 PHPUnit 已通过，当前可作为 Epic 3 控制器 / SSE 接入的持久化基础。

## Verification Plan

- `php bin/w setup:upgrade -m Weline_Websites --yes`
- `php vendor/bin/phpunit app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/SessionServiceTest.php app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/MessageServiceTest.php app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/ArtifactServiceTest.php app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/EventStreamServiceTest.php`
- 必要时对新增 PHP 文件执行 `php -l`

## Verification Result

1. 已对 Epic 2 新增模型、服务、测试文件执行 `php -l`，全部通过。
2. 已执行 `php bin/w setup:upgrade -m Weline_Websites --yes`。
3. 已执行：
   `php vendor/bin/phpunit --no-coverage --display-phpunit-deprecations --display-deprecations app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/SessionServiceTest.php app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/MessageServiceTest.php app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/ArtifactServiceTest.php app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/EventStreamServiceTest.php`
4. 结果：
   - `4 tests / 53 assertions`
   - 业务测试全部通过
   - 仍有 1 条 PHPUnit 配置层 deprecation：`phpunit.xml` 使用了过时 schema，与 Epic 2 代码无关

## Risks

1. 真实数据库单测需要注意清理，避免污染已有数据。
2. `setup:upgrade` 过程中已有系统 warning 较多，需要区分本轮阻塞项与现存噪音。
3. 若 service 接口现在设计过大，会拖慢 Epic 3 控制器层接入。
4. 当前环境曾出现 `generated/routers/backend_pc.php` 生成物损坏，后续若再次触发相同异常，优先清理旧路由生成物后重跑升级，而不是修改业务源码。

## Next

1. 进入 Epic 3：补 Websites AI 工作台后台控制器、JSON API 与 SSE 控制器。
2. 在 Epic 3 中复用当前四个服务，不让控制器直接操作模型。
3. 为会话顶部域名处理状态、聊天消息流、artifact 页面草稿编辑提供控制器层契约。
