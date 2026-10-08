# Weline_Server::security::resolve_client_ip — 解析客户端 IP

## 事件所有权

权威事件由 `Weline_Server` 发布（`ClientIpEventResolver` / `WorkerPolicyKernel`）。

`Weline_Cdn` 是可选集成方：监听本事件，由各边缘适配器在命中本厂商标头时写入访客真 IP。Server **禁止**硬编码 `CF-Connecting-IP` 等厂商标头名。

## 何时派发

1. `CanonicalClientIdentity::resolve` 完成默认 XFF 从右剥信任代理  
2. 且 `trusted_proxy === true`  
3. 在 `shared_ban` / 速率限制等策略计费之前  

未受信传输 **不派发**（防止公网伪造厂商标头）。

## 负载（DataObject）

| 字段 | 说明 |
|------|------|
| `transport_ip` | 传输 peer |
| `headers` | 小写 header map |
| `trusted_proxy` | 恒为 true（派发前提） |
| `trusted_proxy_cidrs` | 当前策略受信 CIDR |
| `client_ip` | 默认为剥链结果；观察者可覆盖 |
| `resolved_by` | 观察者写入适配器 code；**非空字符串**时 Server 才采信新 `client_ip` |

## 采信规则

- `resolved_by` 为空 → 保持默认剥链 IP  
- `client_ip` 非法 → 保持默认  
- 事件/观察者异常 → fail-open 保持默认  

## 与 Framework `client_ip_keys` 的关系

| | `client_ip_keys` | `resolve_client_ip` |
|--|------------------|---------------------|
| 层 | Framework `ServerBag::getClientIp` | WLS `WorkerPolicyKernel` 封禁身份 |
| 作用 | 合并 `$_SERVER` header key 列表 | 直接给出访客 IP 覆盖 canonical |
| WLS 路径 | 常被 `WLS_CANONICAL_REMOTE_ADDR` 抢先 | **封禁/策略权威** |

橙云下必须同时修好本事件链路，否则仅 Framework keys 无法阻止 POP 误封。
