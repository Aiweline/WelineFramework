# AI-ENTRY.md

Universal AI entry index for WelineFramework development. This file only routes readers; repository-wide rules live in `dev/ai/global-constraints.md`.

## Reading Order

1. `dev/ai/global-constraints.md`
2. `dev/ai/diagrams/00-INDEX.txt` and relevant architecture diagrams
3. `dev/ai/diagrams/08-module-docs-index.txt` and relevant module docs
4. `dev/ai/skills/_index.md` and only the skills matched to the task
5. Targeted source files for the actual call chain; broad source scans only after docs and indexes are insufficient

## Precedence

- `AGENTS.md` and `CLAUDE.md` are index/entry files only.
- `dev/ai/global-constraints.md` is the repository-wide single source of truth for AI rules.
- If any older prompt text, copied task brief, or secondary document conflicts with the global constraints, follow `dev/ai/global-constraints.md`.
- **真机验收**：禁止未测报完成；每次修改都要测；局部功能函数可用单测，整体功能须 WebUI。权威：`global-constraints.md` §5 No untested “done” / §10.1；Cursor：`.cursor/rules/real-device-acceptance.mdc`。
- **功能闭环（顺藤摸瓜）**：做一个功能就立刻测；主路径每个分支通透，不通先记下→修→测通→再回主流程；闭环后建议用户做 E2E 固化。权威：`global-constraints.md` §5 Feature closed-loop / §10.1。
- **Model 字段 → 模块版本**：改 Model Schema 声明（`#[Col]`/`#[Table]`/索引等）必须同步上调该模块 `etc/module.php` 的 `"version"`，再 `setup:upgrade`。权威：`global-constraints.md` §4；Cursor：`.cursor/rules/module-version-on-model-schema.mdc`。
- **核心框架改动 → 提示合并**：凡改 `app/code/Weline/**`，结案前必须向用户提示跨仓对齐，附决策表（文件→建议码→理由），确认后再合入。**每次只合本会话改过的文件**，禁止把会话外/全树漂移当候选。权威：`global-constraints.md` §7；Cursor：`.cursor/rules/core-project-sync.mdc`。
- **回灌**：精确口令「回灌」= 已给框架仓时可修核→合入推送→`update:core` 回当前站→验证；未给框架仓或未说「回灌」禁止。权威：`global-constraints.md` §7；技能：`CI发布工程师-回灌验证`；Cursor：`.cursor/rules/huiguan-core-update.mdc`。
- **禁止 PHP 超全局**：业务代码不得直接读写 `$_GET`/`$_POST`/`$_REQUEST`/`$_COOKIE`/`$_FILES`/`$_SERVER`；用 `Request` / `WelineEnv`。权威：`global-constraints.md` §4；Cursor：`.cursor/rules/no-php-superglobals.mdc`。
- **跨模块 → Event 解耦**：禁止模块间强制引用对方具体类；通知/副作用用 Event/Observer，读取用 Interface 或 `w_query()`。权威：`global-constraints.md` §4；Cursor：`.cursor/rules/cross-module-event-decoupling.mdc`。
- **Framework 核心 → 抽象 + 事件**：`app/code/Weline/Framework/**` 只做平台抽象并提供中立事件/契约；禁止非框架业务语义进入核心；模块接入靠 Observer。权威：`global-constraints.md` §4；Cursor：`.cursor/rules/framework-core-abstraction-events.mdc`。
- **部署默认 pre**：用户说「部署」默认只部署预发 `/home/weline-test`；生产 `/home/weline` 须明示。权威：`global-constraints.md` §12；Cursor：`.cursor/rules/ssh-mcp-deploy.mdc`。

## Quick Commands

```bash
php bin/w setup:upgrade [--route] [-m Module_Name]  # Schema/route sync（Model 字段变更须先升 etc/module.php version）
php bin/w http:request / # Frontend route test
php bin/w server:start -p 9502 -n ai-test-{unique-id}  # Start test instance
php bin/w server:reload # Reload test instance
php bin/w server:restart -r # Restart test instance when master-level changes require it
php bin/w server:stop -n ai-test-{unique-id}  # Stop and cleanup test instance
```

## SAAS 远端操作（SSH MCP）

已配置 SAAS 目标（`43.205.103.113`）**默认且必须**通过 Cursor **SSH MCP（`ssh-mcp`）** 操作，**禁止** Shell 频繁 `ssh`/`scp`：

1. `listConnections` → 复用 **`weline-saas`**
2. 若无连接或已断开：按 `dev/ai/config/ssh-mcp-weline-saas.json` 调用一次 `connect`
3. 传文件：`batchUploadFiles` / `uploadFile`；远端命令：`executeCommand`
4. **「部署」默认 = pre** `/home/weline-test`；**生产** `/home/weline` **须用户明示**（部署生产 / deploy prod 等）
5. 细则：`.cursor/rules/ssh-mcp-deploy.mdc`、`dev/ai/global-constraints.md` §12。

## Resources

- AI general rules: `dev/ai/global-constraints.md`
- Diagrams: `dev/ai/diagrams/00-INDEX.txt`
- Module docs: `dev/ai/diagrams/08-module-docs-index.txt`
- Skills: `dev/ai/skills/_index.md`
- Agent roster: `dev/ai/agent/README.md`
- Extended development guide: `dev/ai/AI-开发与测试指南.md`
