# Weline Framework BinQuery

BinQuery 是 Weline_Framework Query 子系统对站外开放的官方二进制网关。它不新增独立模块，不替代现有 Query Provider，也不牵扯 REST；站外客户端统一通过 `/bin/query` 访问已经声明为 `external=true` 的 Query operation。

## 入口

- 官方路径：`/bin/query`
- SDK 连接参数：只需要 `domain` 和 `apiKey`
- endpoint 推导：`https://{domain}/bin/query`
- 默认区域：`frontend`
- 协议：`binquery-v1`
- Content-Type：`application/x-weline-query-bin`

## API Key

SDK 里的 `apiKey` 是 `Weline_Api` 第三方应用的 `access_token`。SDK 会把它作为 Bearer token 发送：

```http
Authorization: Bearer {apiKey}
X-Weline-BinQuery-Protocol: binquery-v1
Content-Type: application/x-weline-query-bin
```

这个 `apiKey` 不是永久密钥。默认生命周期：

- 后台/API 创建第三方应用后得到 `client_id` 和 `client_secret`，它们是应用凭据，长期有效但可被禁用、删除或重置。
- 授权 BinQuery scope 后得到一次性 `code`，只用于换 token。
- `POST /api/rest/v1/apps/token` 换出的 `access_token` 才是 SDK `apiKey`，默认有效期 `3600` 秒。
- `refresh_token` 默认有效期 `2592000` 秒，用 `POST /api/rest/v1/apps/refresh` 换新的 access token。

应用安装必须拥有 BinQuery scope。框架识别 `Weline_Framework::binquery`、包含 `binquery` 的 source id，或 route 为 `bin/query` 的授权条目。

## 请求类型

- `connect`：校验 API Key，返回协议版本、默认 area、SDK 能力和可用 provider 数量。
- `query`：查询 `providers/provider/operations/operation/docs/exists`。
- `call`：执行一个外部 Query operation。
- `graph`：执行只读 graph 编排。

## 暴露规则

站外可访问 operation 必须在最终 descriptor 中满足：

- `external=true`
- 默认 frontend 区域下还需要 `frontend=true`
- `mode` 必须显式声明

CDN 缓存只允许公开只读 operation：

- `external=true`
- `mode=read`
- `cache.cdn=true`
- `cache.visibility=public`

## SDK

- PHP：`aiweline/binquery-php`，源码目录 `sdk/binquery-php`
- JS：`@aiweline/binquery`，源码目录 `sdk/binquery-js`

更多示例见 [SDK使用指南.md](SDK使用指南.md)。

SDK 下载与安装说明同时会通过 DeveloperWorkspace API 文档收集事件进入 `/dev/tool/docs/api` 的 `BinQuery SDK` 分组；Framework 只贡献文档数据，不直接改写 `Weline_Api` 的 API 文档生成服务。
