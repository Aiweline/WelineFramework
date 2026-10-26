# Weline_Websites::crawler_policy_saved

站点爬虫 allow/block 策略保存后触发。

## 触发时机

`WebsiteCrawlerPolicyService::saveForWebsite()` 完成 `entries_json` 落库并同步 WLS `domain_overrides.crawler_block` 之后。

## 数据

| 字段 | 类型 | 说明 |
|---|---|---|
| `website_id` | int | 网站 ID，默认站为 0 |
| `rule` | array | 归一化后的策略：`enabled`、`block_duration`、`entries` |
| `connection` | `ConnectionFactory|null` | 当前写事务连接；观察者应使用 `afterCommit()` 注册网络/文件副作用 |

## 观察者约定

- 不允许在保存请求的主事务内直接发起 Cloudflare HTTP 调用。
- 使用 `TransactionCoordinatorInterface::afterCommit($connection, $key, $callback)`。
- 观察者失败不得抛回网站保存流程；边缘同步状态由观察者自行记录。
