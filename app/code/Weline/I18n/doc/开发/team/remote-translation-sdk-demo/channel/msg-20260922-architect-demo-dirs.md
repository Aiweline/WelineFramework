# channel — 架构：Demo 目录约定已落盘

- from: 架构师
- agent_id: ad672f68-1f50-488a-bc80-11653b7caf42
- at: 2026-09-22
- status: pass
- notify_pm: true

## 摘要

用户拍板「无先例就做」→ 已出可对齐冻结的 **per-API PHP/JS 可下载 Demo** 约定。

- 纪要：[`../meetings/架构-demo目录约定.md`](../meetings/架构-demo目录约定.md)
- 要点：`pub/source/demo/{demo_id}-{lang}/` 与 `binquery-*` 隔离；元数据用 `example.demos`（有 url 才显）；新端点 `demo-download`；翻译首例 I18n 双轨（下载包 + Backend `api.demo`）；Frontend 发现组挂 Demo 按钮、鉴权文案防混。

@项目经理：本席已交付/上报，请检查并更新 SESSION；建议唤醒 API + I18n（主）+ 测试。
