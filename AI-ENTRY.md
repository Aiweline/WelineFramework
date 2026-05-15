# AI-ENTRY.md

Universal AI entry index for WelineFramework development.

This file is an index only. The single source of AI rules is:

- `dev/ai/global-constraints.md`

## Read First

1. **AI general rules** -> `dev/ai/global-constraints.md`
2. **Architecture diagrams** -> `dev/ai/diagrams/00-INDEX.txt`
3. **Module docs** -> `dev/ai/diagrams/08-module-docs-index.txt`
4. **Skills** -> `dev/ai/skills/_index.md` (on demand)
5. **Source code** -> last resort after docs and diagrams

## Reading Order

```text
Step 0: dev/ai/global-constraints.md
Step 1: dev/ai/diagrams/00-INDEX.txt + 01-framework-overview.txt
Step 2: dev/ai/diagrams/08-module-docs-index.txt -> app/code/Weline/{Module}/doc/README.md
Step 3: dev/ai/skills/_index.md -> matched dev/ai/skills/{skill}/SKILL.md
Step 4: Source code (LAST RESORT)
```

## Quick Commands

```bash
php bin/w setup:upgrade [--route]  # Schema/route sync
php bin/w http:request / [-b|-api] # Route test
php bin/w server:start -p 9502 -n ai-test-{unique-id}  # Start test instance
php bin/w server:reload|restart -r # WLS lifecycle for test instance
php bin/w server:stop -n ai-test-{unique-id}  # Stop and cleanup test instance
```

Command safety, WLS isolation, documentation rules, adversarial thinking, and multi-agent splitting rules are maintained only in `dev/ai/global-constraints.md`.

## Resources

- AI general rules: `dev/ai/global-constraints.md`
- Diagrams: `dev/ai/diagrams/00-INDEX.txt`
- Module docs: `dev/ai/diagrams/08-module-docs-index.txt`
- Skills: `dev/ai/skills/_index.md`
- Agent roster: `dev/ai/agent/README.md`
- Extended development guide: `dev/ai/AI-开发与测试指南.md`
