# 常见错误速查

| 错误现象 | 根本原因 | 解决方案 |
|---|---|---|
| `Cannot call abstract method ...::setStickyFooter()` | 新增接口方法后，CLI 命名空间下的 `AbstractPrint` 未同步实现 | 在 `Output\Cli\AbstractPrint` 与 `Output\Cli\PrintInterface` 同步添加方法，并在 `printing()` 中渲染底栏 |
| Worker 周期性“健康检查失败 5 次”但 PID 存活 | 健康检查使用监听地址 `0.0.0.0/::` 导致连接失败 | 健康检查改用 `127.0.0.1`（loopback） |
| `env:check` 仍提示 Terraform CLI 未满足 | 安装脚本更新用户 PATH，但当前进程环境未刷新 | 检测脚本优先检查 `%LOCALAPPDATA%\Terraform` 并注入 PATH，或重开终端 |
| `SQLSTATE[23503]` 安装阶段 `m_backend_acl_user_role` 外键失败 | 安装种子数据硬编码 `user_id=2`，但 `m_backend_user` 中不存在该用户 | 安装时先验证用户存在再分配角色，避免硬编码跨表外键 ID |
| `SQLSTATE[42601] ... syntax error at or near ":"`（Pgsql） | 参数名规范化后 SQL 与 `bound_values` 键不一致，`exec()` 回退时占位符未被替换 | 规范化后保持 SQL 与绑定键一致（同步状态或使用同一份规范化结果） |
