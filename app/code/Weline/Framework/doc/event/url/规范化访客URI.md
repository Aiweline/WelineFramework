# Weline_Framework_Url::normalize_visitor_uri

事件名：`Weline_Framework_Url::normalize_visitor_uri`

## 用途

在 `Url` 解析当前请求、锁定访客 origin、做网站匹配 / SEO decode **之前**派发。  
观察者可把带命名空间的访客 URI 拆成：

| 字段 | 含义 |
|------|------|
| `uri` | 入站原始 URI（只读参考） |
| `origin_uri` | 访客可见 origin（地址栏 / 链接生成 / 缓存键身份） |
| `routing_uri` | 路由与网站匹配用的工作 URI（可剥前缀） |

典型消费：Theme 真实预览 `/~preview/{token}/…` —— origin 保留挂载前缀，routing 剥成店面 remainder（可继续 `/~site/{code}/…`）。

站点探测、Scope 安装、start-page 网站上下文**必须**使用 `routing_uri`（或 `Url::applyVisitorUriNormalizeToUrl`），禁止用仍带挂载前缀的 origin 去选站。

## 触发

```php
// 共享入口（Url::parser / Server start-page / DetectWebsite）
$normalized = Url::normalizeVisitorUri($uri);
// $normalized['routing_uri'] → 网站匹配与路由
// $normalized['origin_uri']  → 访客地址栏 / 链接生成身份
```

Framework 读取回写后的 `origin_uri` / `routing_uri`：origin 写入 `WELINE_ORIGIN_REQUEST_URI`；routing 与 origin 不同时替换工作 `$uri`。

## 边界

| 允许 | 禁止 |
|------|------|
| 剥已知命名空间前缀写 `routing_uri` | 在此做业务授权 / 写 Cookie |
| 把完整挂载 path 保留在 `origin_uri` | 把正式店面 path 改写成预览 path（生成侧另走 `url_generate_params`） |
| 轻量标记 RequestContext / Env | 重 DB / 远程调用 |
| 调用方用 routing 做站点探测 | 用带 `/~preview/` 的 ORIGIN 选站 |

## 修订

- 2026-10-08：首版。配合 Theme `/~preview/{token}/` 真实预览命名空间。
- 2026-10-08：抽出 `Url::normalizeVisitorUri` / `applyVisitorUriNormalizeToUrl`；站点探测强制 routing。
