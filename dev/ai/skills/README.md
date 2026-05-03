# Multica Skills for WelineFramework

本目录提供面向 Multica 的角色化技能集合，用于在 WelineFramework 仓库中按职责分工执行任务。

## 设计目标

- 将原有按技术主题组织的技能，重构为按团队角色组织的技能。
- 每个技能目录都可被 Multica 独立导入。
- 保留 WelineFramework 的开发约束、阅读顺序、验证规则和文档边界。
- 让技术主管、专项工程师、QA、CI、文档角色之间的协作边界更清晰。
- 旧的专题技能目录已移除，`dev/ai/skills/` 现在只保留角色化技能和共享说明文件。

## 导入规则

- 目录格式固定为 `dev/ai/skills/{角色}-{技能名}/SKILL.md`
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

## ClawHub Publish

发布脚本位置：

- `tools/publish-multica-skills.mjs`
- `tools/publish-skills-sh.mjs`

本地半自动发布到 ClawHub：

1. `npx clawhub login`
2. `node tools/publish-multica-skills.mjs --dry-run`
3. `node tools/publish-multica-skills.mjs`

本地发布到 Skills.sh：

1. 安装 GitHub CLI：`gh`
2. `gh auth login --web`
3. `node tools/publish-skills-sh.mjs --dry-run`
4. `node tools/publish-skills-sh.mjs`

如果本地已安装 `gh` 但还没有登录，`tools/publish-skills-sh.mjs` 会自动启动 `gh auth login --web` 的浏览器认证流程。

CI 全自动发布到 ClawHub：

- 配置 `CLAWHUB_TOKEN`
- 可选配置 `CLAWHUB_OWNER`
- GitHub Actions 工作流：`.github/workflows/publish-multica-skills.yml`

CI 发布到 Skills.sh：

- 使用 GitHub Actions 内置 `GH_TOKEN`
- 默认发布 tag：`v1.0.${{ github.run_number }}`
- 可通过 `SKILLS_SH_TAG` 覆盖发布 tag

脚本默认目录就是 `dev/ai/skills`，只有在你明确要发布别的目录时，才需要额外传目录参数。

运行脚本时如果缺少登录态或 token，脚本会直接输出下一步操作指南。

ClawHub 对新 skill 有发布频率限制。当前限制为每小时最多 5 个新 skill。脚本会逐个发布本仓库的技能目录；如果触发限制，等待 ClawHub 重置窗口后再次运行 `node tools/publish-multica-skills.mjs` 即可继续。

如果 ClawHub 返回 `Version already exists`，脚本会跳过该 skill 并继续发布后续 skill。这表示对应版本已经发布过，不是登录错误。

脚本会显式传入每个中文 skill 对应的英文 slug，避免 ClawHub CLI 对中文名称推断失败并返回 `--slug required`。

发布脚本生成的 manifest 文件位于 `tools/clawhub-skill-manifest.json`。
