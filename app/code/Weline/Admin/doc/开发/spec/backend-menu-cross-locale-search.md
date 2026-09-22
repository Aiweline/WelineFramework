---
status: validated
work_kind: feature
feature_slug: backend-menu-cross-locale-search
module: Weline_Admin
updated: 2026-09-22
---

# 后台菜单多语言交叉搜索（DB 索引实现）

## 实现（2026-09-22）

- **禁止**渲染热路径全语 `prefetchWords` / `data-search-text` HTML 烘焙（曾导致 WLS 后台 10–20s）。
- **必须**在 `renderMenu` 缓存未命中时对**当前 UI locale** 批量 `Parser::prefetchWords(菜单标题)`，否则非中英语种侧栏会回退中文 source（模块 CSV 仅 zh/en）。
- 交叉可搜词离线写入 `Weline_Search` `SearchProviderDocument`（indexer=`backend_menu`），对齐 Faq Provider。
- 顶栏 / 侧栏查询走 `SearchProviderIndexService::search`；侧栏 `nav-filter` 非空查询 debounce 调 `search.search`，按 `source_id` 显隐。
- ACL：全量索引 + execute 与当前角色菜单树求交。

## 澄清记录

| # | 问题 | 答案 | 时间 |
|---|------|------|------|
| 1 | 交叉搜索范围（侧栏 / 顶栏万能搜索） | **都要** | 2026-03-24 |
| 2 | 参与交叉的语言集合 | **后台已启用的全部 locale**（索引 keywords） | 2026-03-24 |
| 3 | 打开页是否同步烘焙 | **否**；仅 rebuild / 查询时 | 2026-09-22 |

## EARS

1. WHEN 打开后台页，系统 SHALL 亚秒级渲染侧栏且菜单节点不含全语 `data-search-text`。
2. WHEN 侧栏输入当前语言菜单名，系统 SHALL 本地过滤命中。
3. WHEN 侧栏输入其它已启用语言菜单名，系统 SHALL 经索引远程过滤命中（展示仍为当前语言）。
4. WHEN 顶栏搜菜单，系统 SHALL 走 DB 索引交叉命中。
5. WHEN menu:collect / ACL 失效 / Admin Upgrade，系统 SHALL rebuild `backend_menu`。
6. IF 角色无菜单 ACL，THEN 索引命中不得暴露该菜单。

## 前后端范围

| 层 | 触点 | 是否改 |
|----|------|--------|
| BE | MenuRenderService 去热路径烘焙；IndexDocumentBuilder；Provider | 是 |
| BE | menu:collect / event / Upgrade rebuild | 是 |
| FE | Theme `nav-filter` debounce + BinQuery search | 是 |
| UI | 无视觉改版 | skip |

## 团队目录

`doc/开发/team/backend-menu-cross-locale-db-index/`
