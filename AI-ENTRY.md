# AI-ENTRY.md

**Universal AI Entry Point for WelineFramework Development**

## 🚨 Read This First

1. **MCP bootstrap & rules** → `AGENTS.md`
2. **Capability atlas** → `app/code/Weline/Framework/doc/能力全景.md`（能力 → 归属模块 → 入口 → 证据）
3. **Architecture** → `dev/docs/weline/架构总览.md` + `app/code/Weline/Framework/doc/architecture/`
4. **Module docs** → `app/code/Weline/Ai/doc/文档索引.md` + `app/code/Weline/Ai/doc/模块文档总览.md`
5. **Skills** → MCP `resolve_skill` / `get_skill`；内容运营 `dev/ai-command/**`；模块技能 `app/code/*/*/doc/ai/skills/**`

## 📋 Reading Order

```
Step 0: AGENTS.md（接通 weline_project_intelligence MCP）
Step 1: dev/docs/weline/架构总览.md（框架分层/运行时/路由/ORM/事件）
Step 1b: app/code/Weline/Ai/doc/AI硬规则索引.md（任务路由）
Step 2: app/code/Weline/Ai/doc/文档索引.md → app/code/Weline/{Module}/doc/README.md
Step 3: MCP resolve_skill(task) → get_skill(skill_id)，或 dev/ai-command/{域}/{任务}.md
Step 4: Source code (LAST RESORT)
```

## 🎯 Token Optimization

**Check docs before reading source code.**

- Source code: 3000-6000 tokens
- Architecture docs: 500-1000 tokens (save 70-80%)
- Module docs: 800-1200 tokens (save 80-87%)

## 🏗️ Architecture

| 主题 | 路径 |
|---|---|
| 架构总览（分层/运行时/路由/ORM/事件） | `dev/docs/weline/架构总览.md` |
| 当前架构 / 目标架构 / 模块契约 / 性能预算 | `app/code/Weline/Framework/doc/architecture/01-current.md` · `02-target.md` · `03-module-contract.md` · `04-performance-budget.md` |
| WLS 运行时与验收状态 | `app/code/Weline/Server/doc/WLS架构图.md` · `WLS当前能力与验收状态.md` |
| 能力全景（业务能力 → 模块） | `app/code/Weline/Framework/doc/能力全景.md` |

## 📚 Skills (on-demand)

**权威索引：MCP `resolve_skill(task=...)` → `get_skill(skill_id)`（`mcp-skills.v1`）。** 不再维护静态 skill 文件索引。

| 类型 | 位置 |
|---|---|
| 工作流技能（主题/前端/支付/性能/审图/代码复审/工程团队/翻译/文档/电商顾问/运营） | MCP `resolve_skill` / `get_skill` |
| 内容运营指令（产品优化/详情优化/翻译优化/主图优化/规格修复/新建文章/建站/删站/审图） | `dev/ai-command/{product,blog,sitesetup,theme}/` |
| 模块技能（CDN/Product/Blog/Social/Theme/Visitor/Ai/I18n/Websites/Framework/Admin/ModuleManager/FileManager） | `app/code/Weline/*/doc/ai/skills/**` |
| 技能编写标准 | `app/code/Weline/Ai/doc/AI技能编写标准.md` |

## 🔧 Commands

```bash
php bin/w setup:upgrade              # 全量升级（Schema、路由、注册表等）
php bin/w setup:upgrade --route      # 选填：仅刷新路由（新增/变更控制器后，跳过 Schema）
php bin/w http:request / [-b|-api]   # Route test
php bin/w server:start -p 9502 -n ai-test-{unique-id}  # Start test instance (REQUIRED)
php bin/w server:reload|restart -r   # WLS lifecycle (test instance only)
php bin/w server:stop -n ai-test-{unique-id}  # Stop and cleanup test instance (REQUIRED after testing)
```

## ⚠️ Constraints

**NEVER:** Edit `generated/` | Use `routes.xml` | JS `alert/confirm` | Hardcode text | Alter fields in `Setup/Upgrade.php` | `<?=?>` in `<w:*>` attrs | `<?= __() ?>` in `.phtml` HTML body/attrs | `declare(strict_types=1)` in `.phtml` | WLS `sleep/die/exit` | Write detailed fix reports to root directory | **Test on default port 9501 or reuse instance names** | **Leave test instances running after session ends**

**ALWAYS:** I18n `__('text')` or `<lang>text</lang>` | Placeholders `%{1}` or `%{name}` | **Maintain `i18n/zh_Hans_CN.csv` + `en_US.csv` aligned; run `php bin/w i18n:collect` after CSV/string changes** | ORM chains end with `.fetch()`/`.fetchArray()` | Schema via `#[Col]` + bump `etc/module.php` version + `setup:upgrade` | **Web/UI: Browser self-test use cases before claiming done; end reports with 「交付地址」** | Write fix reports in module's doc/ directory | Update module README with test status | **Start dedicated test instance with unique name (`-p 9502+ -n ai-test-{timestamp|session-id}`)** | **Stop test instance after testing (`server:stop -n {instance-name}`)**

## 📝 Documentation Rules

**Fix Reports:** Write in module directory (e.g., `app/code/Weline/Framework/Setup/Db/doc/FIXES.md`), NOT root directory

**Update Docs:** After fixing bugs, update:
1. Module README with test status
2. Architecture docs if design changed
3. API docs if interface changed

**NO Detailed Process Reports:** Only update requirements and architecture docs, not step-by-step fix logs

## 👥 Multi-Agent Workflow

**Roles:** Tech Lead (you)=dispatch+verify, NO dev | Senior Devs(≤30)=implement | QA=test

**Flow:** Assess→split→assign→parallel dev→test→deliver

**Rules:** Autonomous decisions | Report as "boss" | Utilization≥60% | Auto-reclaim >30min idle | Assess conflict risk

## 🔗 Resources

- **Capability atlas**: `app/code/Weline/Framework/doc/能力全景.md`
- Architecture: `dev/docs/weline/架构总览.md` · `app/code/Weline/Framework/doc/architecture/`
- Module docs: `app/code/Weline/Ai/doc/文档索引.md` · `app/code/Weline/Ai/doc/模块文档总览.md`
- MCP & framework entry: `AGENTS.md` · `app/code/Weline/Ai/Mcp/README.md`
- Skills: MCP `resolve_skill` / `get_skill` · `dev/ai-command/**`
- Full guide: `app/code/Weline/Ai/doc/AI工程交付流程.md` · `dev/docs/weline/开发者入口.md`
