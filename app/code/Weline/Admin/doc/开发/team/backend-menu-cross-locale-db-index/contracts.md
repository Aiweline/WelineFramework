# contracts — backend-menu-cross-locale-db-index

status: frozen
updated: 2026-09-22

## 机制

- SearchProvider `backend_menu` + `SearchProviderIndexService` / `DatabaseSearchProviderIndexStore`
- 禁止渲染热路径全语 `prefetchWords` / `data-search-text`

## ACL

- 索引：role_id=1 全量可导航菜单（含全启用 locale keywords）
- 查询：execute 后与当前用户 `getMenuTree()` source_id 求交

## 索引文档

- entity_id = source_id
- locale = `''`（交叉检索时 request locale 清空）
- keywords = 全启用 locale 标题 + source 原文
- payload.route / payload.source_name；url 在 execute 时 formatMenuUrl

## 失效

- `menu:collect` 成功后 rebuild
- `Weline_Acl::role_access_cache_invalidated` → rebuild（菜单树代次变化后）
- Admin Setup Upgrade rebuild

## 侧栏 FE

- 空查询：本地当前语 + 路由高亮
- 非空：debounce → `search.search` type=backend_menu area=backend → 按 entity_id/source_id 显隐
