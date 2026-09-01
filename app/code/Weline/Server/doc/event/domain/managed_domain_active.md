# Weline_Server::domain::managed_domain_active

WLS 确认某 Host 进入服务面（启动配置中的 `host` / `public_host` / `ssl_domain`）时触发。

## 订阅方

- `Weline_Websites::SyncWlsManagedDomainActive` — 若 DomainPool 缺失则补写；已存在则不覆盖。

## 与证书事件的关系

- 证书新签发仍走 `certificate_issued`。
- 启动复用已有证书时，`SslCertificateService::publishCertificateIssuedNotification()` 会补发 `certificate_issued`，由 `SyncHttpsStatus` 写入域名池 HTTPS 字段。
