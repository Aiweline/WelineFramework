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

## Codex Runtime Safety
- Codex MUST NOT run blocking foreground services or watchers in tool shell commands. This includes `php bin/w server:start ... --no-daemon`, foreground dev servers, file watchers, or any command expected to keep running until manually stopped.
- Long-running WLS/dev services must be started in background/daemon mode with a unique test instance name, then verified with a separate bounded status/request command.
- If live foreground logs are required, use an explicitly backgrounded/hidden helper with bounded inspection commands, never a blocking foreground command in the main Codex shell.

## Core Patterns (see AI-ENTRY.md for details)
- ORM: chains end with `.fetch()`/`.fetchArray()` | Pagination: `.pagination($p,$s)`
- Schema: `#[Col]` + `setup:upgrade` (NEVER edit `generated/` or `Setup/Upgrade.php`)
- WLS Testing: **ALWAYS** start dedicated test instance with unique name (`-p 9502+ -n ai-test-{timestamp|session-id}`) | Default port 9501 is PRODUCTION (DO NOT TOUCH) | **MUST stop test instance after testing**
- WLS Lifecycle: code→`reload` | master→`restart -r` | NO `sleep/die/exit`
- I18n: `__('text')` or `<lang>text</lang>` | Placeholders: `%{1}` or `%{name}`
- Controller flash: **`MessageManager::warning|error|success(__('…'))`** only — not `$this->getMessageManager()->add*` (see `dev/ai/global-constraints.md`)
- `.phtml`: prefer template taglibs (`<notempty>`, `<var>`, `<lang>`, …) over bulk `<?php`/`<?=` where equivalent — see `dev/ai/global-constraints.md`

## Critical Constraints
**NEVER:** Edit `generated/` | Use `routes.xml` | JS `alert/confirm` | Hardcode text | `<?=?>` in `<w:*>` attrs | `declare(strict_types=1)` in `.phtml` | **Run blocking foreground services/watchers in Codex shell (`--no-daemon`, foreground dev servers, watch mode)** | **Test on default port 9501 or reuse instance names** | **Leave test instances running after session ends**

**ALWAYS:** Start dedicated test instance in background/daemon mode with unique name (`-p 9502+ -n ai-test-{unique-id}`) | Verify with bounded status/request commands | **Stop test instance after testing (`server:stop -n {instance-name}`)**

## Resources
- Diagrams: `dev/ai/diagrams/00-INDEX.txt`
- Skills: `dev/ai/skills/_index.md`
- Full guide: `AI-ENTRY.md`
