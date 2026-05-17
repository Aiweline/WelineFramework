# CLAUDE.md

Quick index for Claude and Claude-like AI assistants.

Full AI rules are maintained in one place only:

- `dev/ai/global-constraints.md`（第 8 节：layout 仅默认骨架、占位与 Hook/插槽挂载，禁止交互与业务逻辑）

Start with `AI-ENTRY.md`, then read `dev/ai/global-constraints.md` before any implementation or verification work.

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
- AI entry index: `AI-ENTRY.md`
- Diagrams: `dev/ai/diagrams/00-INDEX.txt`
- Module docs: `dev/ai/diagrams/08-module-docs-index.txt`
- Skills: `dev/ai/skills/_index.md`
