# Weline AI Rules Pack

本文件是所有 AI 进入 WelineFramework 仓库时的压缩规则包。它只做加载契约和权威源映射，不复制长规则正文。

## Must Load

1. `AI-ENTRY.md`
2. `dev/ai/AI-RULES-PACK.md`
3. `dev/ai/global-constraints.md`
4. `dev/ai/diagrams/00-INDEX.txt`
5. `dev/ai/skills/_index.md`
6. 命中的 1 到 3 个 `dev/ai/skills/*/SKILL.md`
7. 若涉及模块，再读 `dev/ai/diagrams/08-module-docs-index.txt` 和模块 `doc/AI-INDEX.md`，然后按该入口继续读 `doc/README.md` 与专项文档

源码、测试、配置和历史材料只能在上述上下文不足或任务明确要求时继续读取。

## Authority Map

| Surface | Authority |
|---|---|
| 全局硬约束、默认流程、Git、验证、WLS、前端请求、文档边界 | `dev/ai/global-constraints.md` |
| 技能选择和角色路由 | `dev/ai/skills/_index.md` |
| 专项执行规则 | 对应 `dev/ai/skills/*/SKILL.md` |
| 智能体名录和协作上报 | `dev/ai/agent/README.md` |
| 架构和模块入口 | `dev/ai/diagrams/00-INDEX.txt`、`08-module-docs-index.txt`、模块 `doc/AI-INDEX.md` |
| Codex 插件打包 | `dev/ai/codex/plugins/weline-codex-plugin/` |
| 任务过程证据 | `dev/ai/codex/tasks/**` |
| 历史规则、旧计划、旧报告 | `dev/ai/archive/**` |

冲突时以更具体、更新、离执行面更近的源为准：模块文档优先于历史计划；命中技能优先于通用建议；`global-constraints.md` 优先于旧规则镜像。

## Hard Guardrails

- 不修改 `generated/`、编译模板、`routes.xml`，也不手改 schema 生成物。
- 业务前端请求必须走 bin-query / `weline-api` / `Weline.Api.*`，禁止原生 Ajax/XHR/fetch/axios 直连业务接口。
- 用户可见文本默认 i18n；占位符用 `%{1}` 或 `%{name}`。
- WLS 测试只用独立实例和 `9502+` 端口；测试后必须 stop。
- 不默认新增或更新单元测试、E2E spec、fixtures、测试数据或测试脚本，除非用户明确要求。
- 涉及页面、路由、接口、运行时或浏览器可见行为时，必须给出真实入口或命令验证；Browser 被阻塞要说清阻塞点。
- 提交只 stage 本次目标文件；提交后按仓库规则推 `origin` 和 `github`；跨项目、分仓、分项必须有明确口令或路径。
- 口令 `分仓`、`分项` 只触发各自技能，不因近似词扩展范围。
- 用户纠错和复盘只沉淀可复用规则；项目经验写本项目，跨项目行为才写全局。

## Compacting Contract

- 根文件只做指针：`AGENTS.md`、`AI-README.md`、`AI-ENTRY.md`、`CLAUDE.md` 不承载长规则正文。
- `global-constraints.md` 只放跨角色硬约束和默认流程，不放案例流水账。
- 技能只保留 Role、When To Use、Load First、Responsibilities/Workflow、Validation/Output；长示例进 reference、archive 或任务目录。
- `skills/_index.md` 只做路由，不复制技能正文。
- `dev/ai/codex/tasks/**` 是任务证据，不是默认提示词。
- `dev/ai/archive/**` 是历史资料，不默认加载。
- 新增规则先去重：能合并到总则、对应技能或索引的，不新建同义规则。

## Delivery Checklist

- 改动前确认 git root、当前分支和 remote。
- 文档/规则改动用 `git diff -- dev/ai/...` 复核范围。
- 用 `rg` 验证入口仍能找到本规则包、`global-constraints.md`、`skills/_index.md` 和命中技能。
- 提交前只 stage 本次规则包、入口、技能索引、插件入口和任务记录。
- 合并到 `dev` 前确认无未提交目标改动遗留；保留无关脏工作区，不强行清理。
