# Weline AI Skill Index

本文件只负责技能路由。不要一次性读取全部 `skills/*/SKILL.md`；默认保留 1 到 3 个最相关技能进入上下文。

## 必读顺序

1. `AI-ENTRY.md`
2. `dev/ai/global-constraints.md`
3. 本索引
4. 命中的技能正文
5. 相关模块文档和源码

## 组合触发

- 任意工程任务：遵守 `通用工程师-开发规范与代码质量`。
- 浏览器可见 UI、页面、组件、布局、样式、响应式、状态、可用性或审美：加载命中的 `前端主题工程师-*` + `ui-ux-pro-max`。
- 前端请求、QueryProvider、流式订阅、worker 链路：加载 `前端主题工程师-前端API交互`。
- 验证策略、质量门禁或验收：加载 `QA测试主管-*`；默认禁止新增或更新单元测试、测试用例、E2E spec、回归用例、fixtures 和测试数据。
- `单元测试工程师-*` 只在用户明确要求写/补/改/运行单元测试或测试数据时加载；`E2E自动化工程师-端到端流程测试` 只在用户明确要求 E2E 用例或 E2E 执行时加载。
- 轻量路由、HTTP、Browser 冒烟仍可按验证需要加载 `E2E自动化工程师-路由与UI冒烟验证`，但不得创建或更新 E2E/Playwright 用例文件。
- WLS、Worker、reload/restart、Session Server、SSE：加载 `WLS运行时工程师-*`。
- ACL、后台安全、会话、数据保护：加载 `安全权限工程师-*`。
- README、API、架构、索引、规则沉淀、复盘：加载 `文档知识库工程师-*`。
- CI、发布、部署、环境兼容、Windows 命令安全：加载 `CI发布工程师-*`。涉及已配置 SAAS 部署目标时，默认使用本机 OpenSSH 与 Windows 凭据中的部署 key；无本机 SSH 凭据或非 SAAS 线上目标时，使用 Chrome 扩展接管用户 Chrome 中的 JumpServer / Luna / 宝塔 Web 终端标签页，走 `openTabs -> claimTab -> tab.cua/tab.playwright/tab.clipboard`；禁止内联私钥、禁止 Codex 内置浏览器部署、禁止 OS 层抢占用户鼠标或窗口焦点。
- 代码图谱、调用链、影响分析、重构、索引维护：加载 `.claude/skills/gitnexus/*/SKILL.md` 中命中的 GitNexus 技能。

## 角色路由

| 场景 | 技能 |
|---|---|
| 需求拆分、调度、进度、一级验收 | `技术主管-任务拆分与调度`；`技术主管-一级验收与进度追踪` |
| 框架底层、DI、扩展机制 | `框架核心工程师-框架核心开发` |
| ORM、模型、字段、索引 | `框架核心工程师-ORM与数据模型` |
| 路由、事件、Hook、扩展点 | `框架核心工程师-路由事件与扩展` |
| 命令、代码生成、生成链路 | `框架核心工程师-命令与代码生成` |
| 业务模块功能 | `业务模块工程师-模块开发`；`业务模块工程师-服务层与业务逻辑` |
| 配置、缓存、后台权限 | `业务模块工程师-配置缓存与后台权限` |
| 主题模板、Taglib、Widget、PageBuilder | `前端主题工程师-主题模板开发` |
| 组件、页面、视觉状态 | `前端主题工程师-组件与页面构建`；`ui-ux-pro-max` |
| 前端 API / worker 请求链 | `前端主题工程师-前端API交互` |
| 开发规范、边界、验证证据 | `通用工程师-开发规范与代码质量` |
| i18n、用户提示、可见文案 | `通用工程师-国际化与用户提示` |
| WLS 进程稳定 | `WLS运行时工程师-WLS进程稳定` |
| Session / SSE 运行时 | `WLS运行时工程师-Session与SSE运行时` |
| ACL 与后台安全 | `安全权限工程师-ACL与后台安全` |
| 会话配置与数据保护 | `安全权限工程师-会话配置与数据保护` |
| 验证策略、质量门禁 | `QA测试主管-测试策略治理`；`QA测试主管-质量门禁验收` |
| 单元测试、测试数据、回归 | 仅用户明确要求时加载 `单元测试工程师-单元测试覆盖`；`单元测试工程师-测试数据与回归` |
| E2E、路由、UI 冒烟 | 用户明确要求 E2E 时加载 `E2E自动化工程师-端到端流程测试`；轻量路由/UI 冒烟加载 `E2E自动化工程师-路由与UI冒烟验证` |
| CI、发布、部署、环境兼容 | `CI发布工程师-CI与发布门禁`；`CI发布工程师-环境兼容与命令安全` |
| 文档、知识库、规则沉淀 | `文档知识库工程师-文档规范与变更记录`；`文档知识库工程师-技能索引与知识库`；`文档知识库工程师-会话复盘与规则沉淀` |

## GitNexus 路由

GitNexus 技能保留在 `.claude/skills/gitnexus/`，这里只做引用，不复制正文。

| 场景 | 技能文件 |
|---|---|
| 理解架构、执行流、调用链 | `.claude/skills/gitnexus/gitnexus-exploring/SKILL.md` |
| 修改前影响分析、blast radius | `.claude/skills/gitnexus/gitnexus-impact-analysis/SKILL.md` |
| 调试错误、追踪失败路径 | `.claude/skills/gitnexus/gitnexus-debugging/SKILL.md` |
| rename、extract、move、refactor | `.claude/skills/gitnexus/gitnexus-refactoring/SKILL.md` |
| GitNexus 工具、资源、schema | `.claude/skills/gitnexus/gitnexus-guide/SKILL.md` |
| analyze、status、clean、wiki、list | `.claude/skills/gitnexus/gitnexus-cli/SKILL.md` |

强制规则仍以根目录 `AGENTS.md` 的 GitNexus 区块为准：改函数、类、方法前先做 upstream impact；提交前做 detect_changes。

## 智能体入口

- 智能体名录：`dev/ai/agent/README.md`
- 团队流程：`dev/ai/skills/TEAM_WORKFLOW.md`
- 全局规则：`dev/ai/global-constraints.md`
