# Multica Skills for WelineFramework

本目录提供面向 Multica 的角色化技能集合，用于在 WelineFramework 仓库中按职责分工执行任务。

## 设计目标

- 将原有按技术主题组织的技能，重构为按团队角色组织的技能。
- 每个技能目录都可被 Multica 独立导入。
- 保留 WelineFramework 的开发约束、阅读顺序、验证规则和文档边界。
- 让技术主管、专项工程师、QA、CI、文档角色之间的协作边界更清晰。

## 导入规则

- 目录格式固定为 `dev/ai/multica-skills/{角色}-{技能名}/SKILL.md`
- 目录名与 `SKILL.md` frontmatter 中的 `name` 必须完全一致
- 技能名使用中文
- `SKILL.md` 正文使用英文，便于 Multica 路由与复用

## 先读顺序

1. `AI-ENTRY.md`
2. `dev/ai/diagrams/00-INDEX.txt`
3. `dev/ai/diagrams/08-module-docs-index.txt`
4. `CLAUDE.md`
5. 本目录中的角色技能
6. Source code as the last resort

## 共享约束

- Do not edit `generated/` directly.
- Do not use `routes.xml`.
- Do not use JavaScript `alert`, `confirm`, or `prompt`.
- Do not hardcode user-facing text.
- Use i18n for user-facing text.
- Do not add `declare(strict_types=1)` inside `.phtml`.
- Do not use `sleep`, `die`, or `exit` in WLS runtime-sensitive paths.
- Do not write detailed fix reports to the repository root.
- Write fix reports in the related module `doc/` directory.
- Update module README after bug fixes.
- Update architecture docs when design changes.
- Update API docs when interfaces change.
- Do not test AI changes on default WLS port `9501`.
- Always create a dedicated WLS test instance on port `9502+`.
- Always use a unique AI test instance name such as `ai-test-{timestamp}`.
- Always stop the dedicated AI test instance after validation.

## 目录导航

- `[_index.md](_index.md)`：Multica 路由索引
- `[ROLE_SKILL_BINDING.md](ROLE_SKILL_BINDING.md)`：角色与原技能映射
- `[TEAM_WORKFLOW.md](TEAM_WORKFLOW.md)`：团队协作流程
- `[MIGRATION_REPORT.md](MIGRATION_REPORT.md)`：迁移说明与缺失源记录

