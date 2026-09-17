---
status: ready-for-plan
work_kind: feature
feature_slug: playlist-search
module: Weline_StoreMusic
updated: 2026-09-16
plan_complexity: simple
plan_skip_rationale: 单模块 StoreMusic 店面播放列表面板加客户端过滤，无新扩展点/无 schema，单表面约 1 小时，前后端架构无歧义。
ui_skill_decision: participate
fe_be_scope: storefront_ui + frontend_js + i18n + tests_e2e
clarify_status: agent_resolved
---

# 播放列表搜索

## 澄清记录

| # | 问题 | 决议 |
|---|---|---|
| 1 | 搜索范围？ | 曲名 + 简介（本地过滤，不改歌单顺序/选曲索引） |
| 2 | 落位？ | 播放列表头下方、列表上方全宽搜索框（对齐现有 compartment 节奏） |
| 3 | 空结果？ | 显示「未找到匹配曲目」；清空关键字恢复全列表 |
| 4 | 上下首？ | 仍按完整歌单索引，不按过滤结果跳曲 |

## 用户故事

作为店面访客，我想在进店音乐面板里按关键字筛选播放列表，以便在曲目较多时快速找到想听的歌。

## EARS

1. WHEN 访客打开多曲目面板并在搜索框输入非空关键字，系统 SHALL 仅展示曲名或简介包含该关键字（不区分大小写）的曲目行。
2. WHEN 关键字无匹配曲目，系统 SHALL 显示空态文案且不展示曲目行。
3. WHEN 访客清空搜索框，系统 SHALL 恢复展示全部曲目，且当前播放选中态不变。
4. IF 歌单仅 0–1 首（compartment 隐藏），系统 SHALL 不展示搜索框。

## 用例 UC-1 主成功路径

1. 访客打开进店音乐面板（≥2 曲）。
2. 在搜索框输入某曲名片段。
3. 列表仅显示匹配行；点击匹配行仍可切歌播放。
4. 清空搜索，列表恢复全部曲目。

验收映射：`type=e2e` STOREMUSIC-E2E-SEARCH；Browser WB-OP。

## 非目标

- 不改后台歌单配置、不改 IndexedDB/音源缓存。
- 不做服务端搜索、不做拼音模糊（本版仅子串匹配）。
