---
name: 文档知识库工程师-文档规范与变更记录
description: Update Weline module README, architecture, API, usage, or durable behavior documentation when a verified contract or user/developer workflow changes. Use for owning documentation work; not for task logs, root-level fix diaries, or internal changes with no documentation impact.
---

# Documentation ownership

## Workflow

1. Identify the audience and changed contract: setup/usage, public API, architecture, operations, or user-visible behavior.
2. Locate the narrowest current owner: module README/doc, API contract, architecture document, skill, index, or global rule.
3. Verify every claim against the final source and validation evidence.
4. Update only the affected sections. Link to another owner instead of copying its full rule or workflow.
5. Put execution logs, one-off troubleshooting, and acceptance evidence in the task record; promote only durable outcomes into product/framework documentation.
6. Check links, examples, commands, version-sensitive claims, and navigation from the owning index.

## Output

- owning document and audience;
- concise behavior/contract change;
- supporting paths or examples needed by future users;
- validation performed and any intentionally unchanged documentation.

Do not create a new document merely because code changed. Update README/API/architecture material only when its public setup, behavior, interface, or design contract changed.
