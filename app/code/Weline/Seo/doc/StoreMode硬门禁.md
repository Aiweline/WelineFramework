# Store mode SEO hard gate（TASK-P1D-004-SEO）

## 规则

| store_mode | robots.txt | sitemap | 页面 robots meta | HTTP 响应头 |
|---|---|---|---|---|
| `dev` / `test` | 强制 `Disallow: /`，无 Sitemap 行 | 空 urlset | 强制 `noindex,nofollow`（配置不可关） | 强制 `X-Robots-Tag: noindex, nofollow` |
| `normal` | 按站点协议配置 | 按规范输出 | 按配置/页面默认 | 不施加 Store-mode 强制值 |

## 入口

- `Weline\Seo\Service\StoreModeSeoHardGate`
- `Weline\Seo\Service\StoreModeSeoResponseDecorator`
- `Weline\Seo\Observer\StoreModeSeoResponseObserver`
- `RobotsTxtRenderer` / `SitemapProtocolRenderer` / `PageSeoContextResolver`

最终响应观察器覆盖 WLS `run_after`、FPM `response_ready` 与 FPC
`cache_hit_response`。仅前台 `GET`/`HEAD` 且非静态/媒体请求施加响应头。

验证：`/robots.txt`、`/sitemap.xml`、前台页面 HTML 与响应头（需 WLS 且请求
Scope 的 store_mode 为 dev/test）；同时以 normal Store 对照，确认没有被硬门禁改写。
