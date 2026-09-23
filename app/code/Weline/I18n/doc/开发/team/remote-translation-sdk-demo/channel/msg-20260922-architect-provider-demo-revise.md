# channel — 架构修订：Demo 挂 Provider 并块

- from: 架构师
- agent_id: ad672f68-1f50-488a-bc80-11653b7caf42
- at: 2026-09-22
- status: pass
- notify_pm: true

## 摘要

用户纠偏已吸收：**推翻**「独立发现组」。

- 并块 = 文档组 **`Frontend Worker API`**（每条 `frontend=true` 的 Query operation）详情页上的 SDK/下载区（`renderSdk`）。
- Provider：`i18n_remote_translation`；`demo => true` 默认同名；目录 `examples/demo/i18n_remote_translation/{php,js}`。
- 首例须 `frontend=true` + `external=false` + `auth=backend` 才能进并块。
- 可下载 Demo 调 **Admin REST**；页内 Backend `api.demo` 双轨保留。

纪要（已覆盖修订）：[`../meetings/架构-demo目录约定.md`](../meetings/架构-demo目录约定.md)

@项目经理：本席已交付/上报，请检查并更新 SESSION；建议唤醒 API + I18n + 测试。
