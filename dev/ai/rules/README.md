# Rules Relocated

历史 `.mdc` 规则已迁移到：

- `dev/ai/archive/rules/`

当前规则总入口只有一个：

- `dev/ai/global-constraints.md`

`dev/ai/skills/_index.md` 只作为技能路由索引，不维护总则正文。

仍保留于本目录的 **Cursor `alwaysApply` 规则**：见各 `.mdc`（例如 `no-batch-code-modification.mdc` 禁止批量替换与批量脚本改代码；`layout-files-convention.mdc` 布局文件仅骨架/占位/挂载点、禁止交互与业务逻辑；`view-template-taglibs.mdc` 视图标签约定；`message-manager-static.mdc` 控制器 Flash 须用 `MessageManager::warning|error|success(__('…'))`；`i18n-default-source-zh.mdc` 用户可见文案默认须为简体中文）。总则正文以 `dev/ai/global-constraints.md` 为准（产品经理 -> 架构师 -> 高级全栈闭环见第 0 节，layout 见第 8 节，批量改码见第 5.1 节）。
