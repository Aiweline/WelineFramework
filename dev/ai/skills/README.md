# Multica Skills for WelineFramework

本目录提供面向 Multica 的角色化技能集合，用于在 WelineFramework 仓库中按职责分工执行任务。

## 设计目标

- 将原有按技术主题组织的技能重构为按团队角色组织的技能。
- 每个技能目录都可以被 Multica 独立导入。
- 保留 WelineFramework 的开发约束、阅读顺序、验证规则和文档边界，并统一引用 `dev/ai/global-constraints.md`。
- 明确技术主管、专项工程师、QA、CI、文档角色之间的协作边界。
- 将开发规范、代码质量、国际化与用户提示等跨角色执行方式沉淀为 `通用工程师-开发规范与代码质量`；总则正文仍以 `dev/ai/global-constraints.md` 为准。

## 导入规则

- 目录格式固定为 `dev/ai/skills/{角色}-{技能名}/SKILL.md`。
- 目录名必须与 `SKILL.md` frontmatter 中的 `name` 完全一致。
- 技能名使用中文。
- `SKILL.md` 正文使用英文，便于 Multica 路由与复用。
- 专业技能不得复制总则正文；跨角色规则统一维护在 `dev/ai/global-constraints.md`。

## 先读顺序

1. `AI-ENTRY.md`
2. `dev/ai/global-constraints.md`
3. `dev/ai/diagrams/00-INDEX.txt`
4. `dev/ai/diagrams/08-module-docs-index.txt`
5. 当前目录下命中的角色技能
6. Source code as the last resort

## 共享约束

共享约束不在本文件重复维护。所有技能必须遵守：

- `dev/ai/global-constraints.md`

## 智能体名录

- 智能体入口：`dev/ai/agent/README.md`
- 每个智能体文件包含“指令”和“Skill”两部分。
- 所有工程智能体都必须遵守 `dev/ai/global-constraints.md`，并加载 `通用工程师-开发规范与代码质量` 作为共识技能。
- 专业技能按智能体前缀组织，例如 `框架核心工程师-*`、`文档知识库工程师-*`。

## 目录导航

- `[_index.md](_index.md)`：Multica 路由索引
- `[ROLE_SKILL_BINDING.md](ROLE_SKILL_BINDING.md)`：角色与原技能映射
- `[TEAM_WORKFLOW.md](TEAM_WORKFLOW.md)`：团队协作流程
- `[MIGRATION_REPORT.md](MIGRATION_REPORT.md)`：迁移说明与来源记录

## ClawHub Publish

- 发布脚本：`tools/publish-multica-skills.mjs`
- Skills.sh 发布脚本：`tools/publish-skills-sh.mjs`

本地发布到 ClawHub：

1. `npx clawhub login`
2. `node tools/publish-multica-skills.mjs --dry-run`
3. `node tools/publish-multica-skills.mjs`

本地发布到 Skills.sh：

1. 安装 GitHub CLI：`gh`
2. `gh auth login --web`
3. `node tools/publish-skills-sh.mjs --dry-run`
4. `node tools/publish-skills-sh.mjs`

Skills.sh 发布脚本会自动生成 `tools/.skills-sh-publish/skills/{english-slug}/SKILL.md` 临时目录，再调用 GitHub skill publisher。ClawHub 发布脚本仍直接发布 `dev/ai/skills`。

CI 自动发布：

- ClawHub：配置 `CLAWHUB_TOKEN`，可选 `CLAWHUB_OWNER`。
- Skills.sh：使用 `GH_TOKEN` 或 `GITHUB_TOKEN`。
- 当前默认 Skills.sh tag：`weline-skills-v1.1.1`。
