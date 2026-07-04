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
- 前端请求、QueryProvider、流式订阅、worker 链路：加载 `前端主题工程师-前端API交互`；前后端业务接口只能走 bin-query / `weline-api`，禁止原生 Ajax/XHR/fetch。
- 验证策略、质量门禁或验收：加载 `QA测试主管-*`；默认禁止新增或更新单元测试、测试用例、E2E spec、回归用例、fixtures 和测试数据。
- `单元测试工程师-*` 只在用户明确要求写/补/改/运行单元测试或测试数据时加载；`E2E自动化工程师-端到端流程测试` 只在用户明确要求 E2E 用例或 E2E 执行时加载。
- 轻量路由、HTTP、Browser 冒烟仍可按验证需要加载 `E2E自动化工程师-路由与UI冒烟验证`，但不得创建或更新 E2E/Playwright 用例文件。
- WLS、Worker、reload/restart、Session Server、SSE：加载 `WLS运行时工程师-*`。
- Weline 全局面板、输入 `weline`、WLS Performance、`WLS 服务` tab、query-bin 卡慢、静态资源缓存、明确预览才绕过缓存：加载 `WLS运行时工程师-WLS面板性能诊断`。
- SEO 面板、输入 `weline`、`SEO` tab、SEO inspector、SEO 解析器、head/meta/canonical/hreflang/JSON-LD/H 标签、多搜索平台矩阵、控制台 SEO 报告：加载 `SEO面板诊断`。
- 访问面板、Pixel、像素事件、GA4 转发、CTA 事件、同意模式、流量过滤、转发规则：加载 `前端主题工程师-前端API交互` 并查看 `Weline_Visitor` 文档。
- 生产环境的 Weline 面板必须通过 `dev_tool.panel.enable_in_prod` + token/token_hash 门禁；即使输入 `weline`，也要先验证 token。
- ACL、后台安全、会话、数据保护：加载 `安全权限工程师-*`。
- README、API、架构、索引、规则沉淀、复盘：加载 `文档知识库工程师-*`。
- CI、发布、部署、环境兼容、Windows 命令安全：加载 `CI发布工程师-*`。涉及已配置 SAAS 部署目标时，默认使用发布目标仓库 `E:\公司\远程\src\weline` 的本机 OpenSSH 与 Windows 凭据中的部署 key；无本机 SSH 凭据时停止并报告阻塞；禁止内联私钥、禁止 Codex 内置浏览器部署、禁止 OS 层抢占用户鼠标或窗口焦点。
- **Weline_Deploy 站点部署 / Webhook / `deploy:release` / `deploy:webhook:setup`**：加载 `CI发布工程师-部署发布系统`（`.codex/skills/deploy-release-system/SKILL.md` 为英文摘要）。公网随机路径 `~wh~` + ModuleRouter `Controller/Router.php`；密码 `deploy:webhook:setup` 生成，`--force` / `--rotate-path` 轮换。
- **自定义公网 URL、随机路径、路由别名、ModuleRouter、`Controller/Router.php`、`RouterInterface`**：加载 `框架核心工程师-路由事件与扩展`（含模块 Router 与静态控制器路由分工）。
- 代码图谱、调用链、影响分析、重构、索引维护：加载 `.claude/skills/gitnexus/*/SKILL.md` 中命中的 GitNexus 技能。

## 角色路由

| 场景 | 技能 |
|---|---|
| 需求拆分、调度、进度、一级验收 | `技术主管-任务拆分与调度`；`技术主管-一级验收与进度追踪` |
| 框架底层、DI、扩展机制 | `框架核心工程师-框架核心开发` |
| ORM、模型、字段、索引 | `框架核心工程师-ORM与数据模型` |
| 路由、事件、Hook、扩展点 | `框架核心工程师-路由事件与扩展`（**模块 `Controller/Router.php`** = ModuleRouter 自定义 URL 匹配） |
| 命令、代码生成、生成链路 | `框架核心工程师-命令与代码生成` |
| 业务模块功能 | `业务模块工程师-模块开发`；`业务模块工程师-服务层与业务逻辑` |
| 配置、缓存、后台权限 | `业务模块工程师-配置缓存与后台权限` |
| 第三方支付方式、支付 Provider、支付配置模板、checkout 支付模板 | `.codex/skills/payment-provider-development/SKILL.md` |
| 主题模板、Taglib、Widget | `前端主题工程师-主题模板开发` |
| GuoLaiRen 供应商模块 | `app/code/GuoLaiRen` 已整体迁移到 `E:\公司\远程\src\weline`；源仓库不再支持该 vendor，切换到目标仓库处理 |
| 组件、页面、视觉状态 | `前端主题工程师-组件与页面构建`；`ui-ux-pro-max` |
| 前端 API / worker 请求链 | `前端主题工程师-前端API交互` |
| 开发规范、边界、验证证据 | `通用工程师-开发规范与代码质量` |
| i18n、用户提示、可见文案 | `通用工程师-国际化与用户提示` |
| WLS 进程稳定 | `WLS运行时工程师-WLS进程稳定` |
| WLS 面板性能诊断、输入 `weline` 进入 `WLS 服务` tab、框架性能瓶颈、静态资源缓存、明确预览绕过缓存 | `WLS运行时工程师-WLS面板性能诊断` |
| SEO 面板诊断、输入 `weline` 进入 `SEO` tab、SEO inspector、多平台搜索引擎矩阵、控制台 SEO 报告 | `SEO面板诊断` |
| Session / SSE 运行时 | `WLS运行时工程师-Session与SSE运行时` |
| ACL 与后台安全 | `安全权限工程师-ACL与后台安全` |
| 会话配置与数据保护 | `安全权限工程师-会话配置与数据保护` |
| 验证策略、质量门禁 | `QA测试主管-测试策略治理`；`QA测试主管-质量门禁验收` |
| 单元测试、测试数据、回归 | 仅用户明确要求时加载 `单元测试工程师-单元测试覆盖`；`单元测试工程师-测试数据与回归` |
| E2E、路由、UI 冒烟 | 用户明确要求 E2E 时加载 `E2E自动化工程师-端到端流程测试`；轻量路由/UI 冒烟加载 `E2E自动化工程师-路由与UI冒烟验证` |
| CI、发布、部署、环境兼容 | `CI发布工程师-CI与发布门禁`；`CI发布工程师-环境兼容与命令安全` |
| Weline_Deploy、Webhook、`deploy:release`、生产 WLS 部署 | `CI发布工程师-部署发布系统` |
| **口令「分仓」**：DEV→weline 分仓、git tag 递增、双端推送、Packagist 刷新；**「分仓+模块名」只处理该模块** | **仅**加载 `dev/ai/skills/CI发布工程师-分仓发布/SKILL.md`；脚本在 `dev/tools/fencang/`；Codex 另见 `.codex/skills/fencang-release/SKILL.md`；无口令不得触发 |
| **口令「分项」**：当前核心仓提交并推送当前核心分支后，让 Windows `E:\WelineFramework\Framework-Official` 固定分项，或 macOS `/Users/weline/Project/Official` 下自动发现的带 `bin/w` 分项，运行 `php bin/w core:update -b <branch>` 同步核心代码；子项目只提交/推送框架更新产生的变更，业务路径或脏工作区阻塞该站点；用户只说「分项」默认 `dev`，说「分项 <分支>」则处理该分支；站点 WLS 运行中则 reload | **仅**加载 `dev/ai/skills/CI发布工程师-分项更新/SKILL.md`；Windows 脚本 `dev/tools/fenxiang/fenxiang-update.ps1`，macOS 脚本 `dev/tools/fenxiang/fenxiang-update-mac.sh`；Codex 另见 `.codex/skills/fenxiang-update/SKILL.md`；无口令不得触发 |
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

强制规则仍以根目录 `AGENTS.md` 的 GitNexus 区块为准：改函数、类、方法前先做 upstream impact；提交前做 detect_changes。用户明确要求提交时，commit 后须连续 `git push origin HEAD` 与 `git push github HEAD`（见 `global-constraints.md` 第 13 节）。

## 智能体入口

- 智能体名录：`dev/ai/agent/README.md`
- 团队流程：`dev/ai/skills/TEAM_WORKFLOW.md`
- 全局规则：`dev/ai/global-constraints.md`
