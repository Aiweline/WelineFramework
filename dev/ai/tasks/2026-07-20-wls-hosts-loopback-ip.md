# WLS 本地域名 hosts 固定回环 IP

- 日期：2026-07-20
- 状态：已修复并静态验证
- 模块：`Weline_Server` / `Weline_Websites`

## 问题

`*.weline.test` / `*.local.test` 应固定解析到本机回环 `127.0.0.1`，但 `HostsFileManager` 只要域名已存在就跳过，不校验 IP。若 hosts 中混入局域网/其它 IP，启动与 `hosts:add` 不会纠正；入口还允许传入任意 `--ip` / `params.ip`。

## 修复

1. `HostsFileManager::resolveIpForDomain()`：托管需写 hosts 的本地域名强制 `127.0.0.1`，不读取局域网/公网 IP
2. `addDomain()`：已存在但 IP 错误时 rewrite/repair，不再当作 `already_exists` 跳过
3. `server:hosts:add` / `w_query('server','hostsAdd')` / `LocalWelineHostsSyncService`：忽略非回环传入 IP
4. 文档：`app/code/Weline/Server/doc/计划-AI建站-w_query与本机hosts.md`

## 验证

- `php -l` 通过改动文件
- Reflection 单元脚本：强制 IP、错误 IP 纠正、正确 IP 跳过
- 无可访问链接（本机 hosts 写入逻辑，非网页可见行为）
