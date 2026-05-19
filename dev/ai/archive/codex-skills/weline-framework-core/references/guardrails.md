# WelineFramework Guardrails

Workspace: `E:\WelineFramework\DEV-workspace`

Primary source docs in repo:

- `dev/ai/global-constraints.md`
- `dev/ai/AI-开发与测试指南.md`
- `dev/ai/README.md`

## Must avoid

- Batch replace or one-off bulk scripts rewriting multiple source files (see `dev/ai/global-constraints.md` §5.1)
- Editing `generated/`
- Using `alert()`, `confirm()`, `prompt()`
- Hardcoding visible text
- Doing field CRUD inside setup upgrade scripts
- Writing `routes.xml`
- Dispatching framework events with inline literal payloads when the framework expects variables
- Guessing framework methods such as nonexistent ORM helpers

## Must do

- Use `__()` / `<lang>` and update i18n CSVs for visible text
- Use Model attributes for schema/index changes
- Run `php bin/w setup:upgrade`
- Run `php bin/w setup:upgrade --route` after new controllers/routes
- Use `Env::get('a.b.c', default)` for nested config reads

## Framework reminders

- ORM read/write chains typically require `->fetch()` or `->fetchArray()` to execute
- `save()` does not need `fetch()`
- Backend controllers should follow framework controller/base-controller conventions already used in repo
