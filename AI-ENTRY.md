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
php bin/w query:help [provider|WeShop_Product] [operation]  # w_query provider/module help
php bin/w http:request / # Frontend route test
php bin/w server:start -p 9502 -n ai-test-{unique-id}  # Start test instance
php bin/w server:reload # Reload test instance
php bin/w server:restart -r # Restart test instance when master-level changes require it
php bin/w server:stop -n ai-test-{unique-id}  # Stop and cleanup test instance
```

Git commit + dual push（用户明确要求提交时；`origin`=Gitee，`github`=GitHub）：

```bash
git status && git diff && git log -5 --oneline
git add <files>
git commit -m "..."
git push origin HEAD
git push github HEAD
```

## Critical Domain Invariants

- 零号站点是框架安装时自动创建的系统默认站点：`website_id = 0`、`code = default`。
- 一切 `website_id = 0` 的站点语义都只能解释为系统默认站点，不能解释为“未选择站点”“空站点”“无效 ID”或待新建站点。
- 普通业务站点使用正整数 ID；判断站点是否存在时必须区分“参数缺失”和“显式传入 0”。
- 涉及站点、Dashboard、Theme target、CMS、SEO、Visitor、URL 解析、域名绑定、配置作用域等逻辑时，`0` 必须作为合法站点 ID 参与查询、保存、渲染和迁移。
- 代码中禁止用 `empty($websiteId)`、`$websiteId <= 0`、`$websiteId > 0`、`getId()` 真值判断等方式把零号站点过滤掉；应使用显式字段存在性、`website_id >= 0` 或 `code = default` 判断。

## Resources

- AI general rules: `dev/ai/global-constraints.md`
- Diagrams: `dev/ai/diagrams/00-INDEX.txt`
- Module docs: `dev/ai/diagrams/08-module-docs-index.txt`
- Skills: `dev/ai/skills/_index.md`
- Agent roster: `dev/ai/agent/README.md`
- Extended development guide: `dev/ai/AI-开发与测试指南.md`
