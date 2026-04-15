---
name: weline-framework-skill-router
description: WelineFramework 任务技能路由。当任务涉及多个领域或不确定该用哪个技能时，根据关键词路由到对应开发技能。命中后只读取目标技能正文，不批量读取全部技能。触发词：混合框架任务、模块、WLS、Session、前端、路由、ACL、事件、测试、PageBuilder。
globs: []
alwaysApply: false
---

# Weline Framework Skill Router

本技能用于 `E:\WelineFramework\DEV-workspace`。

## 何时使用

- 任务涉及多个开发领域，不确定该用哪个技能
- 需要根据关键词快速路由到对应技能
- 想压缩上下文，只在命中后再读取目标技能正文

## 路由规则

### 通用开发

| 场景/关键词 | 技能 | 路径 |
|---|---|---|
| 计划、拆任务、pre-plan、plan.md、task.md | planning | `dev/ai/skills/planning/SKILL.md` |
| dev/ai/codex、ACTIVE.md、任务状态、任务进度、resume | codex-task-workspace | `dev/ai/skills/codex-task-workspace/SKILL.md` |
| AI 模块、AI 适配器、ScenarioAdapterInterface、ai:adapter:scan、未注册适配器、AiScenarioAdapter | ai-module-development | `.cursor/skills/ai-module-development/SKILL.md` |
| 通用模块开发、register.php、Backend Controller | module-development | `dev/ai/skills/module-development/SKILL.md` |
| 代码生成规范、strict_types、ObjectManager | code-generation-standards | `dev/ai/skills/code-generation-standards/SKILL.md` |
| env.php、SystemConfig、PHP 扩展 | config-and-env | `dev/ai/skills/config-and-env/SKILL.md` |
| 路由、URL、getUrl、getBackendUrl | weline-routing | `dev/ai/skills/weline-routing/SKILL.md` |

### 数据层

| 场景/关键词 | 技能 | 路径 |
|---|---|---|
| Model、ORM、`#[Col]`、`#[Table]` | database-model-standards | `dev/ai/skills/database-model-standards/SKILL.md` |
| QueryProvider、w_query、模块间查询 | unified-query-provider | `dev/ai/skills/unified-query-provider/SKILL.md` |

### 运行时

| 场景/关键词 | 技能 | 路径 |
|---|---|---|
| WLS、Worker、server:start、reload、restart | runtime-and-process | `dev/ai/skills/runtime-and-process/SKILL.md` |
| Session、登录态、认证 | session-development | `dev/ai/skills/session-development/SKILL.md` |

### 前端/视图

| 场景/关键词 | 技能 | 路径 |
|---|---|---|
| 主题、phtml、CSS、JS、模板 | theme-development | `dev/ai/skills/theme-development/SKILL.md` |
| tpl、view/tpl、编译模板、模板覆盖、源模板定位 | template-source-editing | `dev/ai/skills/template-source-editing/SKILL.md` |
| Block、Taglib、Widget、DataTable、自定义标签、标签创建、标签技能、内置JS、内置CSS、主题色变量 | frontend-components | `dev/ai/skills/frontend-components/SKILL.md` |
| 翻译、i18n、`__()` | i18n-internationalization | `dev/ai/skills/i18n-internationalization/SKILL.md` |

### 扩展/集成

| 场景/关键词 | 技能 | 路径 |
|---|---|---|
| 事件、dispatch、event.xml、Hook、Extends | extension-points | `dev/ai/skills/extension-points/SKILL.md` |
| menu.xml、ACL、`#[Acl]` | acl-permission-system | `dev/ai/skills/acl-permission-system/SKILL.md` |
| 缓存、CacheFactory | cache-usage | `dev/ai/skills/cache-usage/SKILL.md` |

### 测试/质量

| 场景/关键词 | 技能 | 路径 |
|---|---|---|
| PHPUnit、http:req、`php bin/w e2e:run`（UI/E2E/冒烟）、Playwright | testing | `dev/ai/skills/testing/SKILL.md` |
| 调试日志、agent_log | debug-logging | `dev/ai/skills/debug-logging/SKILL.md` |

### 特殊场景

| 场景/关键词 | 技能 | 路径 |
|---|---|---|
| PageBuilder、style、layout | pagebuilder-style-templates | `dev/ai/skills/pagebuilder-style-templates/SKILL.md` |
| Sticker、w:sticker | weline-sticker | `dev/ai/skills/weline-sticker/SKILL.md` |
| SSE、EventSource | sse-streaming | `dev/ai/skills/sse-streaming/SKILL.md` |
| visitor、pixel | visitor-pixel | `dev/ai/skills/visitor-pixel/SKILL.md` |
| Windows 命令、引号转义 | windows-command-quoting | `dev/ai/skills/windows-command-quoting/SKILL.md` |

## 使用流程

1. 从任务提取关键词
2. 查询本路由表，找到最匹配的技能
3. 只读取命中的 `SKILL.md`
4. 同时命中多个场景时，只保留最相关的 1~3 个技能

## 禁止

- 批量读取 `dev/ai/skills/*/SKILL.md`
- 场景已命中却不读取目标技能正文
- 无关技能一起加载进上下文
