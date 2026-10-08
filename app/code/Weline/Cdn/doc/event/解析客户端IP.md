# CDN 监听：Weline_Server::security::resolve_client_ip

权威事件文档：`Weline_Server/doc/event/security/解析客户端IP.md`。

## 本模块职责

观察者 [`ResolveClientIpObserver`](../../Observer/ResolveClientIpObserver.php)：

1. 确认 `trusted_proxy`（Server 未受信时本不应派发；二次守卫）  
2. 遍历 `AdapterResolver::getAllAdapters()`  
3. 调用 `AdapterInterface::resolveClientIpFromHeaders($headers)`  
4. **第一个**返回合法 IP 的适配器胜出 → 写 `client_ip` + `resolved_by=<adapterCode>`  

## Cloudflare

[`Cloudflare::resolveClientIpFromHeaders`](../../Adapter/Cloudflare.php) 读取：

- `cf-connecting-ip`（优先）  
- `true-client-ip`（企业站可选）  

无头返回 `null`，Server 继续默认 XFF 剥链。

## not_to_do

- 观察者内禁止封禁 / 调边缘 API  
- 禁止在热路径查 CDN 账户表  
- Server 禁止硬编码 CF 头名（厂商标头只在适配器）
