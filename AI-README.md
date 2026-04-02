# README for AI Assistants

**If you are an AI assistant (Claude, GPT, Gemini, Cursor, etc.) working on this codebase:**

👉 **START HERE:** Read `AI-ENTRY.md` first.

`AI-ENTRY.md` is the universal entry point for ALL AI assistants. It contains:
- Mandatory reading order
- Token optimization strategy
- Architecture quick reference
- Critical constraints (including WLS test instance isolation)
- Multi-agent workflow
- Learning path for new AIs

**Do NOT skip this step.** Reading `AI-ENTRY.md` will save you 60-80% tokens and 5-10x time.

⚠️ **CRITICAL:** Default WLS port 9501 is PRODUCTION. Always start dedicated test instance with unique name (`php bin/w server:start -p 9502+ -n ai-test-{unique-id}`) for AI testing sessions. **MUST stop test instance after testing** (`php bin/w server:stop -n ai-test-{unique-id}`).

---

**Quick links:**
- Universal AI entry: `AI-ENTRY.md` ⭐
- Framework rules: `CLAUDE.md`
- Architecture diagrams: `dev/ai/diagrams/00-INDEX.txt`
- Module docs index: `dev/ai/diagrams/08-module-docs-index.txt`
- Skills index: `dev/ai/skills/_index.md`
