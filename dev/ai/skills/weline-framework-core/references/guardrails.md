# WelineFramework 开发硬约束

工作空间：`E:\WelineFramework\DEV-workspace`

核心文档：
- `dev/ai/global-constraints.md`
- `dev/ai/AI-开发与测试指南.md`
- `dev/ai/README.md`

## 禁止

- 编辑 `generated/`
- 使用 `alert()`、`confirm()`、`prompt()`
- 硬编码可见文本
- 在 Setup Upgrade 脚本里做字段 CRUD
- 编写 `routes.xml`
- 用字面量数组 dispatch 框架事件（框架期望变量）
- 猜测框架方法（如不存在的 ORM 辅助函数）

## 必须

- 使用 `__()` / `<lang>` 并更新 i18n CSV
- 使用 Model 属性做 schema/index 变更
- 运行 `php bin/w setup:upgrade`
- 新控制器/路由后运行 `php bin/w setup:upgrade --route`
- 用 `Env::get('a.b.c', default)` 读取嵌套配置

## 框架提醒

- ORM 读写链通常需要 `->fetch()` 或 `->fetchArray()` 执行
- `save()` 不需要 `fetch()`
- 后台控制器应遵循仓库中已有的框架 controller/base-controller 约定
