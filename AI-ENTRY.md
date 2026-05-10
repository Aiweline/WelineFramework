# AI-ENTRY.md

**Universal AI Entry Point for WelineFramework Development**

## 🚨 Read This First

1. **Architecture diagrams** → `dev/ai/diagrams/00-INDEX.txt`
2. **Module docs** → `dev/ai/diagrams/08-module-docs-index.txt`
3. **Framework rules** → `CLAUDE.md`
4. **Skills** → `dev/ai/skills/_index.md` (on-demand)

## 📋 Reading Order

```
Step 1: dev/ai/diagrams/00-INDEX.txt + 01-framework-overview.txt
Step 2: dev/ai/diagrams/08-module-docs-index.txt → app/code/Weline/{Module}/doc/README.md
Step 3: CLAUDE.md
Step 4: dev/ai/skills/{skill}.md (on-demand)
Step 5: Source code (LAST RESORT)
```

## 🎯 Token Optimization

**Check docs before reading source code.**

- Source code: 3000-6000 tokens
- Diagrams: 500-1000 tokens (save 70-80%)
- Module docs: 800-1200 tokens (save 80-87%)

## 🏗️ Architecture Diagrams

**Index:** `dev/ai/diagrams/00-INDEX.txt`

01=Overview | 02=WLS | 03=Routing | 04=ORM | 05=Events | 06=Module | 07=Request | 08=Module Docs

## 📚 Skills (on-demand)

**Index:** `dev/ai/skills/_index.md`

ORM→database-model-standards | Routing→weline-routing | Events→extension-points | WLS→runtime-and-process | Theme→theme-development | Components→frontend-components | I18n→i18n-internationalization | Query→unified-query-provider | ACL→acl-permission-system

## 🔧 Commands

```bash
php bin/w setup:upgrade [--route]  # Schema/route sync
php bin/w http:request / [-b|-api] # Route test
php bin/w server:start -p 9502 -n ai-test-{unique-id}  # Start test instance (REQUIRED)
php bin/w server:reload|restart -r # WLS lifecycle (test instance only)
php bin/w server:stop -n ai-test-{unique-id}  # Stop and cleanup test instance (REQUIRED after testing)
```

## ⚠️ Constraints

**NEVER:** Edit `generated/` | Use `routes.xml` | JS `alert/confirm` | Hardcode text | Alter fields in `Setup/Upgrade.php` | `<?=?>` in `<w:*>` attrs | `declare(strict_types=1)` in `.phtml` | WLS `sleep/die/exit` | Write detailed fix reports to root directory | **Test on default port 9501 or reuse instance names** | **Leave test instances running after session ends**

**ALWAYS:** I18n `__('text')` or `<lang>text</lang>` | Placeholders `%{1}` or `%{name}` | `.phtml` prefer template taglibs (`<notempty>`, `<var>`, `<lang>`, etc.) over bulk raw PHP — see `dev/ai/global-constraints.md` | ORM chains end with `.fetch()`/`.fetchArray()` | Schema via `#[Col]` + `setup:upgrade` | Write fix reports in module's doc/ directory | Update module README with test status | **Start dedicated test instance with unique name (`-p 9502+ -n ai-test-{timestamp|session-id}`)** | **Stop test instance after testing (`server:stop -n {instance-name}`)**

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

- Diagrams: `dev/ai/diagrams/00-INDEX.txt`
- Module docs: `dev/ai/diagrams/08-module-docs-index.txt`
- Framework: `CLAUDE.md`
- Skills: `dev/ai/skills/_index.md`
- Agent roster: `dev/ai/agent/README.md`
- Full guide: `dev/ai/AI-开发与测试指南.md`
