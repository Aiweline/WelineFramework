# 站级能力天花板扩展事件（Acl 不感知 Website）

Acl **不得**依赖 `Weline_Websites`。站级授权包由 Websites 监听下列事件实现。

| 事件 | 触发点 | 数据 |
|------|--------|------|
| `Weline_Acl::role_acl_entries_after` | `AclService::getRoleAclEntries` | `role_id`, `entries`（可写回） |
| `Weline_Acl::super_admin_bypass_check` | 超管短路前 | `role_id`, `allow_bypass`（可写 false） |
| `Weline_Acl::role_access_save_before` | Role `postAssign` 保存前 | `role_id`, `website_id`, `source_ids`, `allowed`, `message` |
| `Weline_Acl::role_listing_filter` | 角色列表/创建前 | `role_model`, `website_id` |
| `Weline_Acl::acl_assignment_rows_after` | 分配树/标签行 | `role_id`, `website_id`, `rows`（可写回） |

`Role.website_id` / `BackendUser.website_id` 仅是整型归属字段，不引入 Website 模块类。
