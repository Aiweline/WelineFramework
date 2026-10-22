# Weline AI 统一入口

本仓库的 **Weline 项目智能 MCP**（`weline_project_intelligence`，源码 `app/code/Weline/Ai/Mcp`，本地 STDIO，无 OAuth）提供技能索引、代码地图与**领域硬规则下发**。

**控制中心 GUI** 为独立仓（与本框架同级，不合仓）：[Gitee aiweline/weline-gui](https://gitee.com/aiweline/weline-gui) · [多端安装包 Releases](https://gitee.com/aiweline/weline-gui/releases/tag/v0.1.0-packages) · [macOS DMG](https://gitee.com/aiweline/weline-gui/releases/download/v0.1.0-packages/Weline-Control-Center-0.1.0-macos-aarch64.dmg) · [Windows setup](https://gitee.com/aiweline/weline-gui/releases/download/v0.1.0-packages/Weline-Control-Center-0.1.0-windows-x64-setup.exe) · [Linux DEB](https://gitee.com/aiweline/weline-gui/releases/download/v0.1.0-packages/Weline-Control-Center-0.1.0-linux-amd64.deb) · 本机可选 `../框架GUI`。

**编码与改文件仍用宿主原生编辑工具**（Read / Write / ApplyPatch / Shell 等）。MCP **没有**写仓工具；但工程任务在 MCP 已挂载/可挂载时**必须**先 `prepare_project` 并遵守 `hard_constraints`。

## 调用范围（先判再调）

| 任务类型 | 是否调用 MCP |
|----------|--------------|
| **非编码**：闲聊、身份/概念问答、与本仓实现无关的说明、纯口头建议 | **通常跳过**；打招呼 `hi`/`你好` 或指令「提取技能」须列技能/指令目录 |
| **内容运营技能**：产品优化 / 详情优化 / 翻译优化 / 主图优化 / 新建文章 / 审查文章 / 规格修复 等 | **跳过 MCP**（`content_ops_skills_skip_mcp`）：宿主 Read `dev/ai-command/**` + 模块 `doc/ai/skills/**`；禁止 prepare / 技能索引 |
| **编码/工程**：改代码或模块文档、诊断/评审本仓、部署规划、功能验收收口 | **强制**：ensure（若需）→ `prepare_project` → 读并遵守 `hard_constraints` → 宿主原生编辑；按需 `resolve_task_context` / `get_skill`。MCP 挂不上则宿主 Read `AI硬规则索引.md`，不得编造规则 |

权威细则：`app/code/Weline/Ai/doc/AI硬规则索引.md`（`mcp_call_scope`）与 MCP `hard-constraints.v1`。

## AOCI 与 Weline MCP（互补）

并列挂载 AOCI-CODE（`aoci`）。**Weline MCP** 管硬规则 / 技能 / `prepare_project` / 验收；**AOCI** 管认知索引 / Baseline / 漂移。工程主链仍走 Weline；禁止用 AOCI 替代或绕过 `hard_constraints`。**本机无 AOCI 程序时，ensure / `prepare_project` 必须自动安装**；装好后按 `aoci_installation.mcp_registration` 挂宿主。**工程强制认知（HARD DEFAULT USE，非可选）**：prepare 后动手前必须 `aoci_rules`（无可靠完整认知再 `aoci_overview`）；禁止跳过却假装已有认知；内容运营与闲聊豁免。细则：`aoci_complements_weline_mcp` → `规则/00-MCP调用与会话.md` §1b / `AOCI自动安装.md`。

## 挂载与冷启动

工程会话开始时，Agent **自行**确认 MCP 是否挂载，不要让用户去 Settings 手写配置，不要调用 Cursor `mcp_auth`。

```bash
php app/code/Weline/Ai/Mcp/scripts/ensure-project-guidance.php
```

读 JSON 里的 **`mcp_init_check`**、`host_editor_rules` 与 **`agent_next_action`**：

| `mcp_init_check.verdict` | 含义 | Agent 做什么 |
|--------------------------|------|--------------|
| `ready` | 本地 STDIO 引导就绪 | **必须** `prepare_project` 刷新索引与 `hard_constraints`（工程任务） |
| `host_install_needed` | 宿主未挂上 MCP | 执行 `host_mcp_install` steps → 重跑 ensure |
| `host_repair_needed` | 代次/工具目录过期 | 按 `agent_next_action` 修复后重跑 ensure |
| `blocked` | 不可继续（如分支） | 按 `blocker` 处理（如在 `master` 则 `git switch dev`） |

ensure 会写出 MCP 生成的 Cursor alwaysApply 门禁：`.cursor/rules/weline-mcp-coldstart.mdc`（禁止手改当规则源）。

挂载成功时：`prepare_project`（仓库根 + 稳定 `client_session_id`）→ **读并遵守** `hard_constraints` → 按需 `resolve_task_context` / `get_skill`。

**MCP 未挂载或检索失败时**：宿主 Read `app/code/Weline/Ai/doc/AI硬规则索引.md` 继续开发；不得假装已遵守 MCP，不得借此绕过验收门禁。

规范正文只维护在 `app/code/Weline/Ai/doc/`、`app/code/Weline/Framework/doc/` 和各模块 `doc/`。

## Git 策略

**脏改不可丢弃（严重，`preserve_dirty_workspace`）**：宿主 Agent Shell **禁止**为对齐 HEAD 或「清场」而 `git checkout --`、`git restore`、`git clean`、`git stash` 擦未提交修改。编辑须 **dirty-load** 当前磁盘脏改再改；**禁止**用对话/其它会话的旧版本写回（会话间相互覆盖）。权威：`app/code/Weline/Ai/doc/AI硬规则索引.md`。

**智能编辑器协议只提交本文件 `AGENTS.md`。** 不要提交各编辑器私有协议或 MCP 注册文件（如 `.cursor/`、`.cursorrules`、`.cursorignore`、`CLAUDE.md`、`.mcp.json`、`.codex/`、`.vscode/mcp.json`、`.github/copilot-instructions.md` 等）。MCP 挂载与 `.cursor/rules` 冷启动门禁由 Agent 在本机按 ensure 指引生成。

**宿主编辑器规则（强制，MCP `host_editor_rules_mcp_generated_only`）**：工程规则只维护在 MCP `hard-constraints.v1` 与仓库文档；Cursor/Codex 等规则文件**禁止 Agent 手写**，仅可由 MCP 生成器产出（换项目否则找不到）。权威：`app/code/Weline/Ai/doc/AI硬规则索引.md`。

<!-- aoci:begin -->
## AOCI 仓库认知

`aoci.txt` 是本仓面向模型的认知索引：每个受管对象一条 Entry，用 F/R/A/S 表达职责、重要关系、对外契约与非显然约束；Header + 全部 Entry 构成完整索引，受管内容变化时只维护受影响条目。

工程任务（内容运营与闲聊豁免）：

1. **会话开始**：`aoci_rules` 取当前运行合同；无可信完整认知再 `aoci_overview`，返回 `continuation_required` 时原样续 `next_cursor` 直到 `completed=true`，中途不得插业务动作。
2. **上下文压缩后**：先前认知视为不可靠，用 `refresh_reasons=["context_compaction"]` 重新完整 `aoci_overview`，不得用 `check_only` 代替。
3. **改完受管对象**：最终稳定状态只调一次 `aoci_maintain`；候选由 Host 模型按证据独立创作，经 `aoci_update_entry` 整批提交，保留 `source_sha256` / `candidate_id`。
4. **证据不足**：用 `aoci_report` 登记，不得猜写、套模板或为消待办而编造认知。
5. **只读问答、未改受管对象**：无需维护；用户明确禁止写 `.aoci` / `aoci.txt` 时以用户限制为准。

语义创作、Onboarding、Curation、恢复等专项流程只按实时 Guide 与工具说明执行，本文件不重建流程；接入状态用 `aoci doctor` 检查。
<!-- aoci:end -->
