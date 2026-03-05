# 后台用户与超管权限检查

## 框架规则（代码约定）

1. **user_id = 1**：视为超管，**无需** `user_role` 表记录即可登录，`BackendUser::getRole()` 会虚拟为 `role_id=1`。
2. **其他 user_id**：必须在 **用户-角色关联表** 中有记录，否则登录时提示「您的账户尚未分配角色」并拒绝登录。
3. **role_id = 1**：ACL 中视为超级管理员角色（跳过权限校验）。

## 涉及表（前缀以你库为准，常见为 `m_`）

| 表名（可能带前缀） | 说明 |
|-------------------|------|
| `m_backend_user` 或 `backend_user` | 后台用户表，主键 `user_id` |
| `m_backend_acl_user_role` 或 `backend_acl_user_role` | 用户-角色关联表，字段 `user_id`, `role_id` |
| `m_weline_acl_role` 或 `weline_acl_role` | 角色表，`role_id=1` 一般为超管角色 |

## 在 SQL 转储里自查

在 `dev\weline_test_2026-03-05_17-45-48_pgsql_data.sql` 中搜索：

1. **COPY 或 INSERT 的“用户表”**  
   搜索：`backend_user` 或 `m_backend_user`，确认：
   - 是否有 `user_id=1` 的记录（有则该用户为超管，不依赖角色表）。
   - 命令创建的用户是哪个 `user_id`（例如 2、3…）。

2. **COPY 或 INSERT 的“用户-角色表”**  
   搜索：`backend_acl_user_role` 或 `user_role` 或 `acl_user_role`，确认：
   - 每个 **非 1** 的 `user_id` 是否至少有一条 `(user_id, role_id)` 记录。
   - 若希望某用户为超管，需有 `role_id=1`（且角色表里存在 `role_id=1`）。

3. **角色表**  
   搜索：`weline_acl_role` 或 `acl_role`，确认是否存在 `role_id=1`（超管角色）。

## 若“命令创建的用户”不是超管

- 若该用户 **user_id=1**：按当前代码已是超管，无需改权限。
- 若该用户 **user_id≠1** 且没有角色：
  - 在 **用户-角色关联表** 中为该用户插入一条 `role_id=1`（超管）。
  - 或导入后到后台「用户管理」给该用户分配角色。

### 修复用 SQL 示例（需按实际表名改）

```sql
-- 为 user_id=2 分配超管角色（role_id=1），表名请按实际替换
INSERT INTO m_backend_acl_user_role (user_id, role_id)
VALUES (2, 1)
ON CONFLICT DO NOTHING;
```

PostgreSQL 若该表有 UNIQUE(user_id, role_id)，可用 `ON CONFLICT (user_id, role_id) DO NOTHING`。

## 小结

- **超管** = `user_id=1` 或 拥有 `role_id=1` 的关联记录。
- 命令/界面“只创建用户、不分配角色”时，该用户不会有角色，登录会被拒。
- 检查 SQL 转储时重点看：每个非 1 的 `user_id` 在用户-角色表里是否都有对应 `role_id`。
