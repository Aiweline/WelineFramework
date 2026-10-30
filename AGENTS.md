# Weline AI 统一入口

本仓库的 **Weline 项目智能 MCP**（`weline_project_intelligence`，源码 `app/code/Weline/Ai/Mcp`，本地 STDIO，无 OAuth）提供技能索引、代码地图与**领域硬规则下发**。

**控制中心 GUI** 为独立仓（与本框架同级，不合仓）：[Gitee aiweline/weline-gui](https://gitee.com/aiweline/weline-gui) · [多端安装包 Releases](https://gitee.com/aiweline/weline-gui/releases/tag/v0.1.0-packages) · [macOS DMG](https://gitee.com/aiweline/weline-gui/releases/download/v0.1.0-packages/Weline-Control-Center-0.1.0-macos-aarch64.dmg) · [Windows setup](https://gitee.com/aiweline/weline-gui/releases/download/v0.1.0-packages/Weline-Control-Center-0.1.0-windows-x64-setup.exe) · [Linux DEB](https://gitee.com/aiweline/weline-gui/releases/download/v0.1.0-packages/Weline-Control-Center-0.1.0-linux-amd64.deb) · 本机可选 `../框架GUI`。

**编码与改文件仍用宿主原生编辑工具**（Read / Write / ApplyPatch / Shell 等）。MCP **没有**写仓工具；但工程任务在 MCP 已挂载/可挂载时**必须**先 `prepare_project` 并遵守 `hard_constraints`。

## 调用范围（先判再调）

| 任务类型 | 是否调用 MCP |
|----------|--------------|
| **非编码**：闲聊、身份/概念问答、与本仓实现无关的说明、纯口头建议 | **通常跳过**；打招呼 `hi`/`你好` 或指令「提取技能」须列技能/指令目录 |
| **内容运营技能**：产品优化 / 详情优化 / 翻译优化 / 主图优化 / 新建文章 / 审查文章 / 规格修复 等 | **跳过 MCP**（`content_ops_skills_skip_mcp`）：宿主 Read `dev/ai-command/**` + 模块 `doc/ai/skills/**`；禁止 prepare / 技能索引 |
| **编码/工程**：改代码或模块文档、诊断/评审本仓、部署规划、功能验收收口 | **强制**：ensure（若需）→ `prepare_project` → 读并遵守 `hard_constraints` → 宿主原生编辑；按需 `resolve_task_context` / `get_skill`。**MCP 挂不上先自装**（`mcp_call_scope` SELF-INSTALL）：任何 AI 读到本文，若 MCP 未挂载到自己会话，必须按 `host_mcp_install` 指引用自己宿主的机制给自己装上（未知宿主自行定位配置 + `known_targets`），启用后新会话验证工具；只有证明装不上才降级 Read `AI硬规则索引.md` 并说明失败原因；不得编造规则、不得让用户手改 Settings、不得手写编辑器规则文件 |

权威细则：`app/code/Weline/Ai/doc/AI硬规则索引.md`（`mcp_call_scope`）与 MCP `hard-constraints.v1`。

**工程团队**：工程任务默认团队；除非 `work_scale=tiny`，否则按涉及职位配置真实子智能体；验收须 UC 闭环。

## 仓库认知（cognition）

仓库认知由 Weline MCP 的 `cognition` 工具提供：按文件 F/R/A/S 压缩条目，按模块分片、按需加载，不常驻全量索引。工程任务：`prepare_project` 后按需 `cognition action=query`（module/path/query）；受管对象达到稳定状态后 `action=maintain` 一次；`overview`/`status` 查看条目与过期数。外部认知工具已退役，不再安装、挂载或调用。

## 挂载与冷启动

工程会话开始时，Agent **自行**确认 MCP 是否挂载，不要让用户去 Settings 手写配置，不要调用 Cursor `mcp_auth`。

```bash
php app/code/Weline/Ai/Mcp/scripts/ensure-project-guidance.php
```

读 JSON 里的 **`mcp_init_check`**、`host_editor_rules` 与 **`agent_next_action`**：

| `mcp_init_check.verdict` | 含义 | Agent 做什么 |
|--------------------------|------|--------------|
| `ready` | 本地 STDIO 引导就绪 | **必须** `prepare_project` 刷新索引与 `hard_constraints`（工程任务） |
| `host_install_needed` | 宿主未挂上 MCP | **Agent 自装**：执行 `host_mcp_install.hosts[<宿主>].install.steps` → 宿主内启用 → 新会话 → 重跑 ensure |
| `host_repair_needed` | 代次/工具目录过期 | 按 `agent_next_action` 修复后重跑 ensure |
| `blocked` | 不可继续（如分支） | 按 `blocker` 处理（如在 `master` 则 `git switch dev`） |

ensure 会写出 MCP 生成的 Cursor alwaysApply 门禁：`.cursor/rules/weline-mcp-coldstart.mdc`（禁止手改当规则源）。

**自装优先于降级（HARD，`mcp_call_scope` SELF-INSTALL）**：任何 AI 编辑器读到本文，若 MCP 未挂载到自己会话，**先给自己装上**——按 `host_mcp_install` 的 `registration`（command/args/env）写进本宿主的 MCP 配置（仅当语义不等价时），在宿主内启用，新开一个会话验证 `mcp_required_tool` 全部可见。宿主不在支持表内（`primary_host=unknown`）不等于无事可做：自行定位本宿主的 MCP 配置位置（参考 `known_targets`）。禁止让用户去 Settings 手改、禁止 Reload Window、禁止手写编辑器规则文件。

**MCP 未挂载且自装被证明不可能时**（如宿主以不可修改的 MCP 白名单拉起 Agent）：宿主 Read `app/code/Weline/Ai/doc/AI硬规则索引.md` 继续开发，并如实说明尝试过的安装路径与失败原因；不得假装已遵守 MCP，不得借此绕过验收门禁。

规范正文只维护在 `app/code/Weline/Ai/doc/`、`app/code/Weline/Framework/doc/` 和各模块 `doc/`。

## Git 策略

**脏改不可丢弃（P0 最高级，`preserve_dirty_workspace`）**：本章最高级，优先于本文件其余条款与交付速度、期限。宿主 Agent Shell **禁止**为对齐 HEAD 或「清场」而 `git checkout --`、`git restore`、`git clean`、`git stash` 擦未提交修改。编辑须 **dirty-load** 当前磁盘脏改再改；**禁止**用对话/其它会话的旧版本写回（会话间相互覆盖）。改前改后各取一次 `shasum -a 256` 进收尾汇报；违规立即停手、留现场、当回合报告并记台账，同会话再犯即停止全部文件写入。权威：`app/code/Weline/Ai/doc/AI硬规则索引.md`。

**智能编辑器协议只提交本文件 `AGENTS.md`。** 不要提交各编辑器私有协议或 MCP 注册文件（如 `.cursor/`、`.cursorrules`、`.cursorignore`、`CLAUDE.md`、`.mcp.json`、`.codex/`、`.vscode/mcp.json`、`.github/copilot-instructions.md` 等）。MCP 挂载与 `.cursor/rules` 冷启动门禁由 Agent 在本机按 ensure 指引生成。

**宿主编辑器规则（强制，MCP `host_editor_rules_mcp_generated_only`）**：工程规则只维护在 MCP `hard-constraints.v1` 与仓库文档；Cursor/Codex 等规则文件**禁止 Agent 手写**，仅可由 MCP 生成器产出（换项目否则找不到）。权威：`app/code/Weline/Ai/doc/AI硬规则索引.md`。


## 禁止脚本批量替换（HARD · 2026-10-28 用户指定，2026-10-30 加仓内常驻镜）

**改文件必须亲力亲为：一个文件、一次定点编辑。禁止任何「脚本 / 命令批量替换、批量改写」，不论规模多大。**

1. **禁止清单**（源文件、配置、文档、模板一律适用）：`sed -i`、`awk` 就地改写、`perl -pi`、`xargs` + 编辑命令、`find … -exec` 改写、`grep -rl … | xargs sed`、`php -r` / `python -c` / `python3 - <<PY` 遍历重写、一次性生成再批量落盘的迁移脚本、replace-all 宏、编辑器全局查找替换代跑批量改动。
2. **正确做法**：逐个文件 `Read` → 宿主定点编辑工具（Edit / Write / ApplyPatch）→ 改完立即重读复核。批量任务 = 循环执行该流程 N 次，每次只碰一个文件；文件多时分批推进并逐批汇报。
3. **为什么**：正则匹配面不可控，一个错误模式会同时损坏大量文件（误删方法、破坏编码与换行、夹带他会话在飞改动），且当场看不出、事后才暴雷。**实测教训（2026-10-30）**：用 `re.sub` 批量清理调试注释，把 `FullPageCacheCoordinator.php` 的三个方法（`hasCompleteFrozenStorefrontScope`、`legacyPreRouterFpcAllowed`、`namespaceKeyDecorator`）一并吞掉，`php -l` 初查未暴露，靠运行期 `Call to undefined method` 才发现，最终只能从 `git show HEAD:` 整文件恢复。
4. **例外（缺一不可）**：用户原话明确点名允许脚本批量操作；此时先备份全部目标文件到 `dev/backup/<相对路径>.<时间戳>`，先在 1 个文件试运行并贴 diff 给用户过目，确认后才扩量，结束后逐文件抽查复核并列出受影响文件清单。
5. **违规定性**：用脚本批量替换改了文件——即使结果碰巧正确——也算违规。立即停手、保留现场、在收尾汇报单列「批量替换违规记录」（受影响文件 + 是否已备份 + 处置），不得用 git 回滚掩盖。
6. **本条为何写两份**：权威在 Weline MCP（`HardConstraintsCatalog::no_script_batch_replace`）+ 宿主全局镜 `~/.dsh/AGENTS.md`「禁止脚本批量替换」。全局文件体积达 59KB，超过宿主 64KB 注入预算时会被整体省略（`omitted ~/.dsh/AGENTS.md`），规则随即失效且无任何提示——这是 2026-10-30 违规的机制性原因。本仓 `AGENTS.md` 必然注入，故在此加常驻镜兜底；两处口径冲突以 MCP `hard_constraints` 为准。

## 前端版心 / 页面画布（硬规则）

- 版心只认两个 token：`--weline-container-gutter`（gutter）与 `--weline-layout-content-max-width`（版心外框）；容器唯一实现是 `.w-container` / `.w-theme-content-width`。
- Header 内容、正文、Footer、breadcrumb、bottom-slot 必须**同构**（同一 token、同一数学），禁止 `calc(100% - Npx)` 与按断点各写 padding。
- 页面底色/纸纹只设 `--weline-page-canvas-*` 五个 token，禁止直接写 `body{background-image}` 或用 `!important` 压骨架。
- 风格用**风格文件/`data-theme`** 选择，禁止把厂商名（如 amazon）写进元素 class 或全局 CSS 文件名。
- 权威细则：`app/code/Weline/Theme/doc/theme-css-architecture-vs-style.md`「版心与页面画布契约」。

## 诚实性门禁（HARD · 2026-10-25 用户指定）

**核心原则：凡是没有证据的「完成」，一律视为未完成。** 与全局规则 `~/.dsh/AGENTS.md` 同效。

1. **证据门禁**：任何「已完成 / 已通过 / 已验证」的汇报，必须附机器证据：命令 + exit code + 关键 stdout 原文片段（贴原文，禁止转述）。缺证据 = 未完成，该汇报作废，不得伪称已交付。
2. **禁止转述与形容词**：描述结果禁止用「正常 / 成功 / 没问题 / 已检查」等形容词与转述，只允许事实引用：diff、文件行号原文、URL、命令输出原文。
3. **禁止编造具体信息**：涉及路径、行号、URL、版本号、数据时，必须实际读取/执行后再回答；读不到就明说「读不到」并标注 `未验证`。把推测、臆想内容写成已验证事实 = 违规。
4. **职责分离**：验收方必须自己跑验证命令，不采信执行方（执行器 / 子智能体）的自述回执。执行器说「通过」不算通过。
5. **门禁自检**：用户说「门禁自检」→ 当场给出上述全部证据；拿不出即认违规并补流程。
6. **伪报从严**：伪报验证 / 伪称已交付，比越界更严重：同一会话伪报 1 次即停手收回自做，并记入经验台账（`add --source=user`）。
