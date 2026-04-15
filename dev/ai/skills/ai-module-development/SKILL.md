---
name: ai-module-development
description: AI 模块开发技能。覆盖场景适配器、模型与注册约定（ScenarioAdapterInterface、AiScenarioAdapter、AdapterScanner、ExtendsData）以及漏注册排查。用于用户提到 AI 适配器、模型、未注册、ai:adapter:scan、Weline_Ai 扩展时。
globs:
  - "app/code/**/Weline/Ai/**/*.php"
  - "app/code/**/extends/module/Weline_Ai/Adapter/*.php"
  - "app/code/**/extends.php"
alwaysApply: false
---

# ai-module-development

## 何时使用

- 用户提到 AI 模块开发、适配器、模型、Provider、场景编排
- 用户反馈“写了适配器但 Ai 模块未注册/不可见/调用不到”
- 涉及 `Weline\Ai\Interface\ScenarioAdapterInterface`、`AiScenarioAdapter`、`AdapterScanner`

## 目标

- 统一 AI 场景适配器的开发规范与注册路径
- 确保“代码存在 -> 扫描入库 -> 后台可见 -> 运行可调用”闭环

## 适配器标准

- 目录：`extends/module/Weline_Ai/Adapter/*.php`（跨模块扩展）或 `app/code/Weline/Ai/Adapter/*.php`（内置）
- 接口：必须实现 `Weline\Ai\Interface\ScenarioAdapterInterface`
- 命名：文件名以 `Adapter.php` 结尾；`getCode()` 全局唯一且稳定
- 数据字段：`name`、`description`、`version`、`supported_model_types`、`param_template`、`examples`

## 注册与扫描约定（重点）

- AI 适配器不是“只放文件就一定生效”，运行期依赖 `AdapterScanner`
- `AdapterScanner` 会读取 `ExtendsData::getExtendedBy('Weline_Ai')` 再扫描外部模块适配器
- 这意味着：外部模块必须确保扩展关系能出现在 `generated/extends.php` 的 `Weline_Ai.extended_by` 中
- 适配器最终需落库到 `ai_scenario_adapter`（模型：`AiScenarioAdapter`）

## 开发流程（执行清单）

1. 新增/修改适配器类并实现接口
2. 确认扩展关系可被 `ExtendsData` 识别（否则不会被扫描）
3. 执行扫描命令：
   - `php bin/w ai:adapter:scan`
   - 需要清理失效记录时：`php bin/w ai:adapter:scan --clean`
4. 验证数据库中存在对应 `code` 且 `is_active=1`
5. 通过后台适配器页或业务入口验证实际可调用

## 漏注册排查流程

- 先查文件：是否存在 `**/extends/module/Weline_Ai/Adapter/*.php`
- 再查扩展关系：该模块是否被记录到 `Weline_Ai` 的 `extended_by`
- 再查扫描结果：`ai:adapter:scan` 输出是否包含目标适配器
- 最后查落库：`ai_scenario_adapter` 是否有对应 `code`

若“文件有、接口也实现，但扫描不到”，优先判断扩展关系缺失，而不是先怀疑适配器实现。

## 模型开发约定

- 模型字段变更：只改 Model 的 `#[Col]`/`#[Index]` 注解，执行 `php bin/w setup:upgrade`
- 禁止手改 `Setup/Upgrade.php` 做字段 CRUD
- ORM 链式查询最终必须 `.fetch()`/`.fetchArray()`

## 交付验收

- 至少 1 个命令行验证：`ai:adapter:scan`
- 至少 1 个运行链路验证：调用业务入口能命中目标适配器
- 明确记录：新增/更新适配器 `code` 列表、是否已入库、是否可调用

## 常见坑

- 只创建了 `extends/module/Weline_Ai/Adapter` 文件，但扩展关系未进入 `ExtendsData`
- 适配器类名与文件名不一致，导致扫描器推断/加载失败
- `getCode()` 变更导致旧记录失联（建议 code 视为兼容性 ID）
