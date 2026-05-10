# AGENTS.md

⚠️ **IMPORTANT:** This is a quick reference. Full rules in `AI-ENTRY.md` (read it first if you haven't).

**For other AIs (GPT/Gemini/Cursor):** Start with `AI-README.md` → `AI-ENTRY.md`

## Quick Commands
```bash
php bin/w setup:upgrade [--route]  # Schema/route sync
php bin/w http:request / [-b|-api] # Route test
php bin/w server:start -p 9502 -n ai-test-{unique-id}  # Start test instance (REQUIRED)
php bin/w server:reload|restart -r # WLS lifecycle (test instance only)
php bin/w server:stop -n ai-test-{unique-id}  # Stop and cleanup test instance (REQUIRED after testing)
```

## Core Patterns (see AI-ENTRY.md for details)
- ORM: chains end with `.fetch()`/`.fetchArray()` | Pagination: `.pagination($p,$s)`
- Schema: `#[Col]` + `setup:upgrade` (NEVER edit `generated/` or `Setup/Upgrade.php`)
- WLS Testing: **ALWAYS** start dedicated test instance with unique name (`-p 9502+ -n ai-test-{timestamp|session-id}`) | Default port 9501 is PRODUCTION (DO NOT TOUCH) | **MUST stop test instance after testing**
- WLS Lifecycle: code→`reload` | master→`restart -r` | NO `sleep/die/exit`
- I18n: `__('text')` or `<lang>text</lang>` | Placeholders: `%{1}` or `%{name}`
- `.phtml`: prefer template taglibs (`<notempty>`, `<var>`, `<lang>`, …) over bulk `<?php`/`<?=` where equivalent — see `dev/ai/global-constraints.md`

## Critical Constraints
**NEVER:** Edit `generated/` | Use `routes.xml` | JS `alert/confirm` | Hardcode text | `<?=?>` in `<w:*>` attrs | `declare(strict_types=1)` in `.phtml` | **Test on default port 9501 or reuse instance names** | **Leave test instances running after session ends**

**ALWAYS:** Start dedicated test instance with unique name (`-p 9502+ -n ai-test-{unique-id}`) | **Stop test instance after testing (`server:stop -n {instance-name}`)**

## Resources
- Diagrams: `dev/ai/diagrams/00-INDEX.txt`
- Skills: `dev/ai/skills/_index.md`
- Full guide: `AI-ENTRY.md`
