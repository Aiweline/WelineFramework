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
- Frontend API: browser frontend-backend business interfaces **MUST** use bin-query / `weline-api` (`Weline.Api.*`) | NEVER native Ajax/XHR/fetch/axios
- WLS Testing: **ALWAYS** start dedicated test instance with unique name (`-p 9502+ -n ai-test-{timestamp|session-id}`) | Default port 9501 is PRODUCTION (DO NOT TOUCH) | **MUST stop test instance after testing**
- WLS Lifecycle: code→`reload` | master→`restart -r` | NO `sleep/die/exit`
- I18n: `__('text')` or `<lang>text</lang>` | Placeholders: `%{1}` or `%{name}`

## Critical Constraints
**NEVER:** Edit `generated/` | Use `routes.xml` | Native Ajax/XHR/fetch/axios for frontend-backend business APIs | JS `alert/confirm` | Hardcode text | `<?=?>` in `<w:*>` attrs | `declare(strict_types=1)` in `.phtml` | **Test on default port 9501 or reuse instance names** | **Leave test instances running after session ends**

**ALWAYS:** Use bin-query / `weline-api` (`Weline.Api.resource()/graph()/stream()`) for browser business requests | Start dedicated test instance with unique name (`-p 9502+ -n ai-test-{unique-id}`) | **Stop test instance after testing (`server:stop -n {instance-name}`)**

## Delivery
- After every development/fix/deploy delivery, always give the user the related addresses in the final response: runnable page URLs, backend/admin URLs, API endpoints, doc paths/URLs, PR/commit/release URLs, and the test instance URL when one was started.
- If no live URL is available, still list relevant route/path addresses and clearly state what is required to access them, such as starting WLS or logging into the backend. If there is truly no accessible address, explicitly say `无可访问链接`.

## Resources
- Diagrams: `dev/ai/diagrams/00-INDEX.txt`
- Skills: `dev/ai/skills/_index.md`
- Full guide: `AI-ENTRY.md`
