---
status: ready-for-plan
work_kind: feature
feature_slug: locale-tab-dblclick-temp-fill
module: Weline_MediaManager
updated: 2026-09-16
---

# 语言胶囊双击临时补齐单语

## Clarify

| 问题 | 结论 |
|------|------|
| 触发 | 详情侧「多语言翻译」语言胶囊 **双击** |
| 「临时」语义 | 仅补缺**当前被双击语言**；写入 `STATE_DRAFT` + `ORIGIN_MACHINE`（与一键补缺同源，不覆盖已有文案） |
| 单击 | 仍只切换语言与表单，不触发 AI |
| 源语言 / 已有内容 | 跳过 AI；仅切换；已有内容 toast 提示已跳过 |
| 非目标 | 改一键全量补缺；改 cron；Ollama 本地拉起；表单未保存预览专用 API |

## User story

作为媒体运营，我希望在文件详情里双击某个「未翻译」语言胶囊，即可只为该语言做草稿级 AI 补缺，而不必点「一键补缺」跑全量语言。

## EARS

1. WHEN 用户双击非源语言且该语言无文案的胶囊, the system SHALL 仅对该 `target_locale` 调用缺口翻译并落库为草稿，再刷新工作台。
2. WHEN 用户单击语言胶囊, the system SHALL 仅切换 `activeLocale` 与表单，SHALL NOT 发起翻译。
3. IF 被双击语言已有文案 OR 为目标即源语言, the system SHALL 跳过翻译并提示，不得覆盖已有文案。
4. WHILE 单语补缺进行中, the system SHALL 展示与一键补缺一致的 busy/overlay，并禁用 Tab/表单。
5. WHEN `asset_translate_missing` 携带 `target_locales`, the system SHALL 只处理列表内语言（规范化后），不得默认扩成全量 installed locales。

## Use cases

### UC1 双击未翻译语言（主成功）

1. 选中已有源语言文案的文件。
2. 双击 `en_US · 未翻译`。
3. Overlay「正在补缺…」→ 成功 toast → 胶囊去掉「未翻译」，表单显示译文（草稿）。

### UC2 单击切换

1. 单击 `fr_FR · 未翻译`。
2. 仅切换表单为空；不发起 AI。

### UC3 已有内容跳过

1. 双击已有 `en_US`。
2. 提示已跳过；文案不变。

### UC4 忙碌互斥

1. 一键补缺进行中再双击语言。
2. 忽略或 no-op；busy 结束后可再试。

## 验收

- 定向契约：Connector 透传 `target_locales`；`manager.js` 含 `dblclick` 单语补缺。
- 本机 Browser：详情侧双击未翻译语言可见 busy → 补齐（需 AI 可用时）或错误可见。
