# CLAUDE.md

⚠️ **IMPORTANT:** This is a quick reference. Full rules in `AI-ENTRY.md` (read it first if you haven't).

**For other AIs (GPT/Gemini/Cursor):** Start with `AI-README.md` → `AI-ENTRY.md`

## Quick Commands
```bash
php bin/w setup:upgrade [--route]  # Schema/route sync
php bin/w http:request / [-b|-api] # Route test
php bin/w server:reload|restart -r # WLS lifecycle
```

## Core Patterns (see AI-ENTRY.md for details)
- ORM: chains end with `.fetch()`/`.fetchArray()` | Pagination: `.pagination($p,$s)`
- Schema: `#[Col]` + `setup:upgrade` (NEVER edit `generated/` or `Setup/Upgrade.php`)
- WLS: code→`reload` | master→`restart -r` | NO `sleep/die/exit`
- I18n: `__('text')` or `<lang>text</lang>` | Placeholders: `%{1}` or `%{name}`

## Critical Constraints
**NEVER:** Edit `generated/` | Use `routes.xml` | JS `alert/confirm` | Hardcode text | `<?=?>` in `<w:*>` attrs | `declare(strict_types=1)` in `.phtml`

## Resources
- Diagrams: `dev/ai/diagrams/00-INDEX.txt`
- Skills: `dev/ai/skills/_index.md`
- Full guide: `AI-ENTRY.md`
