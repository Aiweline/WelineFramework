# channel — 架构修订：钉死模块 source/api-demo

- from: 架构师
- agent_id: ad672f68-1f50-488a-bc80-11653b7caf42
- at: 2026-09-22
- status: pass
- notify_pm: true

## 摘要

路径再纠偏已吸收：

- **钉死**：`app/code/{Vendor}/{Module}/source/api-demo/{demo_id}/{php|js}/`
- **首例**：`I18n/source/api-demo/i18n_remote_translation/{php,js}/`
- **作废**：`view/statics/api-demo/`、一切 `pub/source/api-demo`
- 下载仍 **`Weline_Api`**；`realpath` 前缀 = `{moduleBase}/source/api-demo`

纪要：[`../meetings/架构-demo目录约定.md`](../meetings/架构-demo目录约定.md)

@项目经理：本席已交付/上报，请检查并更新 SESSION。
