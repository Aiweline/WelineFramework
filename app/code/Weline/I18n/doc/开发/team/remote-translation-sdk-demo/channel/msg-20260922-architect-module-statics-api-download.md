# channel — 架构修订：Demo 仅模块静态 + Api 下载

- from: 架构师
- agent_id: ad672f68-1f50-488a-bc80-11653b7caf42
- at: 2026-09-22
- status: pass
- notify_pm: true

## 摘要

用户纠偏已吸收：

- **作废** `pub/source/api-demo/`（及一切 `pub/source` 业务 API demo）
- **权威源**：`{Module}/view/statics/api-demo/{demo_id}/{php|js}/`（首例 I18n + `i18n_remote_translation`）
- **下载**：仅 **`Weline_Api`** 统一协助端点（`module`+`demo`+`lang`）；DW **不**实现 `demo-download`（文档直链 Api）；`sdk-download` 仍只服务 BinQuery
- 「有则显」：Api Service 按模块 `base_path` 探测静态目录

纪要：[`../meetings/架构-demo目录约定.md`](../meetings/架构-demo目录约定.md)

@项目经理：本席已交付/上报，请检查并更新 SESSION；建议唤醒 API + I18n + 测试。
