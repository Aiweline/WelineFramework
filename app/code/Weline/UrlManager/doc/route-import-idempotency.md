# 升级后路由同步幂等约束

`ModuleUpgradeExecuteAfterPlugin` 会在 `setup:upgrade` 完成后，将生成路由同步到 `url_manager`。

稳定约束：

- `identify = md5(path + type)` 是路由的唯一冲突依据。
- `UrlManager` 只通过 Framework 的 `ModuleIdentityProviderInterface` 批量解析模块名与 `module_id`；禁止重新引用 `ModuleManager` 内部 Model。
- Provider 不可用时路由导入必须明确失败，不得把模块 ID 默认为 `0` 后静默丢弃路由。
- 每批数据先在内存中按 `identify` 去重，然后使用单语句原子 upsert。
- 禁止改回“先删除、再插入”；并发升级会在两条语句之间形成竞态窗口，导致 `url_manager.identify` 唯一键冲突。
- SQLite/PostgreSQL 必须生成 `ON CONFLICT (identify) DO UPDATE`，MySQL 必须生成等价的 `ON DUPLICATE KEY UPDATE`。
- 已存在但曾被标记删除的路由再次出现时，同步必须将 `is_deleted` 恢复为 `0`。

验证时先执行 `framework:compile`，确认 ModuleManager 已发布身份 Provider；再使用同一组 `setup:upgrade --route` 参数至少连续执行两次，每次都应成功，并且 `COUNT(*) = COUNT(DISTINCT identify)`。
