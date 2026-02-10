# 常见错误速查

| 错误现象 | 根本原因 | 解决方案 |
|---|---|---|
| `Cannot call abstract method ...::setStickyFooter()` | 新增接口方法后，CLI 命名空间下的 `AbstractPrint` 未同步实现 | 在 `Output\Cli\AbstractPrint` 与 `Output\Cli\PrintInterface` 同步添加方法，并在 `printing()` 中渲染底栏 |
| Worker 周期性“健康检查失败 5 次”但 PID 存活 | 健康检查使用监听地址 `0.0.0.0/::` 导致连接失败 | 健康检查改用 `127.0.0.1`（loopback） |
| `env:check` 仍提示 Terraform CLI 未满足 | 安装脚本更新用户 PATH，但当前进程环境未刷新 | 检测脚本优先检查 `%LOCALAPPDATA%\Terraform` 并注入 PATH，或重开终端 |
