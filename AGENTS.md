# AGENTS.md

Quick index for AI assistants working in this repository.

Full AI rules are maintained in one place only:

- `dev/ai/global-constraints.md`（layout 约定见第 8 节；Cursor 同步 `.cursor/rules/layout-files-convention.mdc`）

The Codex global rules are merged into that file as part of the unified repository-wide constraints, including Browser-first frontend validation, no premature test solidification, the explicit ban on fallback/patch-style code, and the ban on batch replace / batch script rewrites (edit file-by-file; see §5.1).

For other AIs (GPT/Gemini/Cursor): start with `AI-README.md` -> `AI-ENTRY.md` -> `dev/ai/global-constraints.md`.

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
