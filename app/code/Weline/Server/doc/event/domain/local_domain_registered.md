# Weline_Server::domain::local_domain_registered

WLS 将托管本地域名（`*.test.weline.com`、`*.weline.localhost` 等）写入本机 hosts 成功后触发。

## 触发时机

- `server:start` 自动补 hosts
- `php bin/w server:hosts:add <domain>`
- `w_query('server', 'hostsAdd', ...)`

## 数据契约

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `domain` | string | 完整域名 |
| `ip` | string | hosts 映射 IP（本地开发域固定 127.0.0.1） |
| `status` | string | `added` / `repaired` / `already_exists` / `external_satisfied` |
| `is_new` | bool | 是否本次新写入 |
| `is_standard_project_host` | bool | 是否为 `p{hash}.test.weline.com` 标准项目 Host |
| `source` | string | 固定 `wls_hosts` |

## 订阅方

- `Weline_Websites::SyncWlsLocalDomainPool` — upsert `DomainPool`，供建站域名选择器使用。
