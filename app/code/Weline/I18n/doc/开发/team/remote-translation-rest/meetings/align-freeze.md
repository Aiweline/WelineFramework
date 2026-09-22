# 对齐冻结会 — remote-translation-rest

- 主持：测试（项目经理代收口）
- 参会依据：五席汇审 + API 完整性审查
- 日期：2026-09-22
- 状态：**closed**

## 决议

1. 主责 `Team:API:`；Query 核 + 薄 Admin REST；归属模块落盘。
2. 契约以 `contracts.md` 为准（Path/schema/ACL/状态机/限流已钉死）。
3. 可执行 UC 见下文；验收按 contracts 冒烟清单。
4. 未再开施工前不得改 Path/ACL 名（变更须回本会）。

## 可执行 UC（EARS）

1. WHEN 持合法 Admin Token 调 `GET …/RemoteTranslationCatalog/getWebsites`，SHALL 返回含 website_id/code/name/default_language/status 的列表。
2. WHEN 调 `GET …/RemoteTranslationCatalog/getLanguages?website_id=`，SHALL 仅返回该站语种；空 codes 不回退全球目录。
3. WHEN `POST …/RemoteTranslation/postPending` 且 locales ⊆ 站语种，SHALL 只返回未译词（非空且≠源串之反），带 cursor 分页。
4. WHEN pending 含站外 locale，SHALL 业务失败（code≠200），不泄露词条。
5. WHEN `POST …/postIngest` 目标已有非空且≠源串译文，SHALL skip；空译/非法 → invalid；成功写入并 publish。
6. WHEN `POST …/postCollectStart`，SHALL 返回 task_id；轮询 `getCollectStatus` 至 completed|failed；远程路径不入队 AI。
7. WHEN 无 Token / 缺 ACL，SHALL 拒绝。
8. IF 主路径跑通，SHALL 可观测 written/skipped/invalid 与 task 终态，且不写模块 CSV。

## 立场摘要

测试/架构/API/i18n/安全汇审异议项已吸收进 contracts；本会关闭后允许 API 施工。
