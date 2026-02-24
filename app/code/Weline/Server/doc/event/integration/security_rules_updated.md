# Weline_Server::integration::security_rules_updated

当 WLS 安全规则保存后触发，用于通知 CDN 等模块同步边缘规则。

事件数据：

- `rules` 原始保存规则
- `merged_rules` 合并默认后的完整规则
- `instance` 实例名

