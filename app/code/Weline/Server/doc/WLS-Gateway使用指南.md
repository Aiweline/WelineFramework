# WLS Gateway 使用指南（已退役）

> 状态：历史迁移说明，2026-07-24。Gateway 没有受支持的启动入口，不是当前部署、回退或故障转移选项。

## 现行结论

当前公网链路固定为：

```text
Client
  -> 项目托管 Nginx（TLS 1.3、HTTP/2，HTTP/1.1 自动回退；可用时 HTTP/3）
  -> loopback HTTP/1.1 Keep-Alive
  -> Windows Dispatcher 或 macOS/Linux Direct Worker
```

公网 80/443 只能由本项目托管 Nginx 所有。当前 `server:start` 在创建 Master/Worker 前拒绝外部边缘、`--no-nginx`、`--no-ssl`、Caddy、Gateway、WLS-native/TLS 与 Protocol Edge。不得运行旧 `gateway:start` 命令，也不得新增 `wls.gateway` 或 `app/etc/gateway.php` 配置。

现行安装、启动和验证方式见 [WLS 模式部署指南](WLS模式部署指南.md)。

## 历史身份

旧 Gateway 曾被设计为基于 SNI 的单进程 TCP 反向代理，用一个公网端口按域名转发到多个项目高端口。自动扫描 `var/server/instances/`、手工 routes/default、通配域名和独立 `gateway.php` 都属于旧设计，现行实例发现和 Nginx owner/config generation 契约不采用这些数据。

以下内容只用于识别遗留部署：

- 进程或日志名包含 `gateway`。
- 旧配置出现 `wls.gateway.listen/routes/default`。
- 旧运维记录出现 `gateway:start`。
- 公网 443 owner 是 PHP Gateway，而不是项目托管 Nginx。

发现这些特征时应迁移，不能把 Gateway 重新启用为兼容路径。

## 迁移映射

| 旧 Gateway 概念 | 现行替代 |
|---|---|
| Gateway 监听公网 443 | 项目托管 Nginx 持有公网 HTTP/HTTPS 端口 |
| SNI routes/default | 托管 Nginx 配置 generation 与项目 owner 绑定 |
| 项目直接监听 TLS 高端口 | WLS 仅监听 loopback 明文 H1 回源端口 |
| Gateway 扫描实例文件 | Master Registry 管进程；Nginx owner/live probe 管公网事实 |
| Gateway 处理 TLS 字节 | Nginx 终结 TLS 1.3 与 H2/H1/H3 |
| Gateway 手工守护 | `server:start/stop` 管理 WLS 与项目托管 Nginx 生命周期 |

迁移后必须重新验证 Nginx owner/config generation、证书绑定的 TLS 1.3、H2/H1 fresh WLS health；只有 Nginx 具备 HTTP/3/QUIC 且 HTTP/3-only 请求真实到达同一 WLS health 时才能发布 H3 READY。

## 遗留清理边界

停止或重启流程可以识别并回收属于同项目、同实例的旧 Gateway 残留 PID/端口；这是清理兼容，不代表 Gateway 可再次启动。未知或外部进程仍按 fail-closed 规则保留并报告，不按端口盲杀。

Gateway 的旧优势对比、生产建议、配置示例和“未来增强”均已撤销。任何新部署、性能调优或协议验证都只针对项目托管 Nginx。
