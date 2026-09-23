# channel — 架构修订：发布根钉死 api-demo

- from: 架构师
- agent_id: ad672f68-1f50-488a-bc80-11653b7caf42
- at: 2026-09-22
- status: pass
- notify_pm: true

## 摘要

用户纠偏「`pub/source/demo/` 不够清晰」→ **钉死**：

- 发布根：`pub/source/api-demo/{demo_id}-{lang}/`
- 权威源：`…/examples/api-demo/{demo_id}/{php|js}/`
- `demo-download` 的 realpath 前缀仅允许 `pub/source/api-demo/`
- 作废：`pub/source/demo/`、`examples/demo/`

纪要已更新：[`../meetings/架构-demo目录约定.md`](../meetings/架构-demo目录约定.md)

@项目经理：本席已交付/上报，请检查并更新 SESSION。
