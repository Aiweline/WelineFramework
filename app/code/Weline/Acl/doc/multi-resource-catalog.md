# ACL 多资源编目

## 概述

ACL 资源编目支持菜单、HTTP/REST、bin-query、后台可恢复任务与运维操作。`source_id` 支持多标签+code：`Vendor_Module::tag1:tag2:code`。

## 命名

- `resource_type`：资源类型（menu/http/rest/query/resumable_task/operation）
- `backend_acl.kind`：仅策略枚举 `source|param_map|self`（不要混用）
- 存储 `type`：`menus`/`pc`/`api`/`query`/`task`/`operation`

## backend_acl 声明

```php
'backend_acl' => [
    'kind' => 'source',
    'tags' => ['query', 'media'],
    'code' => 'ai_draw',
],
// → Weline_MediaManager::query:media:ai_draw

'default_backend_tags' => ['query'],
```

`kind=self`：**不进入 ACL 目录**（Legacy controller bridge 例外，有安全风险，后续单开需求收口）。

## 升级命令链

```bash
php bin/w framework:compile   # 或由 setup:upgrade --route 在 catalog 收集前强制刷新
php bin/w setup:upgrade --route
```

`--route` 在读 `query_providers.php` 前会校验/刷新 Query 编译产物；`server:reload` **不算** catalog 刷新。

ResumableTask 无 compile 产物：upgrade 直接扫描各模块 `etc/resumable_tasks.php`。

## 鉴权双轨

| 表面 | 服务 | 语义 |
|---|---|---|
| HTTP 路由 | RouteBefore + WhiteAclSource + AclService | 未保护路径可放行 |
| Query / Worker / task | ResourceAuthorizationService | 资源必须存在且启用；超管仍 fail-closed（资源不存在则拒） |

禁止把 Query 并进路由白名单语义。

## 标签整包赋权

- 取消标签 = 删除该标签路径前缀下全部叶子 `role_access`
- upgrade 按 `role_tag_grant` **只补新、不回收**
- `role_id=1` 禁止标签订阅
- 验收必须用**非超管**角色

## MediaManager 迁移

- `::ai_draw` → `::query:ai_draw`
- `::ai_draw_save` → `::query:ai_draw_save`
- 收集器内 RENAME_MAP 会先迁 `role_access` 再孤儿清理旧 id

## 角色双视图（D-6 / D-12 / D-13）

- **菜单维**：`type=menus` + 挂载的 `pc/api`（侧栏仍只消费 menus）；jsTree 可勾选
- **标签维**：`query|task|operation` 与带标签的 source；**同样使用 jsTree 可勾选树**（标签节点整包赋权，叶子可单独勾选/取消）；取消标签仍按 `tag_path` 前缀收回全部叶子
- 页面级共享 `Set<source_id>` 为唯一真相；保存 `ids[]` = 叶子集 ∪ menus 祖先闭包，并同请求重写 `role_tag_grant`
- 禁止用当前可见树的 `jstree.get_selected` 当全集；工具栏（展开/折叠/全选/清空/搜索）作用于当前维度
- **筛选按维度分治**：菜单维按顶层分组 `li[data-module]` 过滤；标签维先按 `data-module`/`data-type` 过滤叶子，再按"是否含可见叶子"上卷父标签节点（支持多级标签）。类型下拉需同时包含菜单类型（menus/pc/api）与标签类型（query/task/operation），由 `type_list` 汇总；叶子 `data-module` 为空时回退 `source_id` 的模块前缀。切换维度后自动 `applyFilters()` 使当前筛选作用于新维度

## 资源列表双页（模块维 / 标签维）

- **模块资源**：`GET /acl/backend/acl`（`Acl::getIndex`）— 独立搜索 + 类型 + `module-manager:module:select`
- **标签资源**：`GET /acl/backend/acl/by-tag`（`Acl::getByTag`）— 独立搜索 + 类型 + `acl:tag:select` 多选
- 两页顶部可互相切换；表格内标签 chip → 标签维，模块名 → 模块维
- 菜单：`Weline_Acl::acl_source` / `Weline_Acl::acl_source_by_tag`；`acl/backend/acl/tag` 仍为标签元数据管理

## 生命周期（D-8～D-11）

1. `--route` 前强制 Query compile / manifest 硬失败
2. LiveSourceSet 在 `AfterRouteCollectionAclDiff` 计算前并入
3. partial orphan 仅删除 touched modules 内不在 live set 的非 user 行
4. `role_tag_grant` upgrade **只补新、不回收**；`role_id=1` 跳过

## 验证命令

```bash
php bin/w framework:compile
php bin/w setup:upgrade --route
php bin/w setup:schema:check -m Weline_Acl
```

后台入口：

- ACL 资源列表：`/acl/backend/acl`（模块筛选使用 `<w:module-manager:module:select>`）
- 角色授权：`/acl/backend/acl/role/assign?id={role_id}`（模块筛选同样使用 `<w:module-manager:module:select>`，选项来自本页权限树中的 `module_list`）
- 权限标签：`/acl/backend/acl/tag`
