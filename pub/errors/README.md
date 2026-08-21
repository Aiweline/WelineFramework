# HTTP 状态回执页（pub/errors）

站点可覆盖本目录下的 PHP 模板，定制各 HTTP 状态码页面。

## 解析顺序

1. 事件 `Weline_Framework_Http::error_page_render`（观察者写入 `html` 可整页覆盖）
2. `pub/errors/{code}.php`（如 `404.php`）
3. `pub/errors/default.php`
4. `ErrorPageRenderer` 内置 HTML

JSON 客户端（`Accept: application/json` 优先）返回 JSON，不走 HTML 模板。

## 模板变量

由 `Weline\Framework\Http\ErrorPageRenderer` 注入：

| 变量 | 说明 |
|---|---|
| `$statusCode` / `$code` | HTTP 状态码 |
| `$statusText` | 标准英文短语 |
| `$message` / `$msg` | 运行时消息 |
| `$pageTitle` / `$pageLead` / `$pageHint` | 文案 |
| `$homeHref` | 首页链接 |
| `$requestId` | 请求 ID（若有） |
| `$detail` / `$isDev` | 开发态细节 |
| `$accent` | `neutral` / `warn` / `danger` |

专用模板可覆盖 `$pageTitle` 等后 `require __DIR__ . '/_shell.php';`。

## 已提供模板

`400` `401` `403` `404` `410` `429` `500` `502` `503` 以及 `default.php`。
