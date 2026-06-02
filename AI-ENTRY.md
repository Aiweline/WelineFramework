# AI-ENTRY.md

Universal AI entry index for WelineFramework development. This file only routes readers; repository-wide rules live in `dev/ai/global-constraints.md`.

## Reading Order

1. `dev/ai/global-constraints.md`
2. `dev/ai/diagrams/00-INDEX.txt` and relevant architecture diagrams
3. `dev/ai/diagrams/08-module-docs-index.txt` and relevant module docs
4. `dev/ai/skills/_index.md` and only the skills matched to the task
5. Targeted source files for the actual call chain; broad source scans only after docs and indexes are insufficient

## Quick Commands

```bash
php bin/w setup:upgrade [--route]  # Schema/route sync
php bin/w http:request / [-b|-api] # Route test
php bin/w server:start -p 9502 -n ai-test-{unique-id}  # Start test instance
php bin/w server:reload|restart -r # WLS lifecycle for test instance
php bin/w server:stop -n ai-test-{unique-id}  # Stop and cleanup test instance
```

## Resources

- AI general rules: `dev/ai/global-constraints.md`
- Diagrams: `dev/ai/diagrams/00-INDEX.txt`
- Module docs: `dev/ai/diagrams/08-module-docs-index.txt`
- Skills: `dev/ai/skills/_index.md`
- Agent roster: `dev/ai/agent/README.md`
- Extended development guide: `dev/ai/AI-开发与测试指南.md`
