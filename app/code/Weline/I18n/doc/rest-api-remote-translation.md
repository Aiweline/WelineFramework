# 远程协助翻译 REST（I18n）

基路径：`/{api_admin}/i18n/rest/v1/RemoteTranslation/{action}`

鉴权：Admin 后台 Token / Session。示例：`YOUR_ADMIN_TOKEN`。

本机路由别名示例：`i18n/rest/v1/remote-translation/pending`、`…/post-pending`（以 `generated/routers/backend_rest_api.php` 为准）。

Query 核：`w_query('i18n_remote_translation', …)`。

## ACL

| source_id | 方法 |
|-----------|------|
| `Weline_I18n::rest_v1_remote_translation` | 类 |
| `Weline_I18n::rest_v1_remote_translation_pending` | `postPending` |
| `Weline_I18n::rest_v1_remote_translation_ingest` | `postIngest` |
| `Weline_I18n::rest_v1_remote_translation_collect_start` | `postCollectStart` |
| `Weline_I18n::rest_v1_remote_translation_collect_status` | `getCollectStatus` |

## 接口

### POST `…/postPending`

Body：`{ "website_id":0, "locales":["en_US"], "limit":50, "cursor":null }`

- `limit` 钳制 `1..200`
- 未译：非空且 `translate !== source` 视为已译
- 响应：`items` / `next_cursor` / `has_more` / `limit`

### POST `…/postIngest`

Body：`{ "website_id":0, "items":[{ "source","locale","translation" }] }`

- `items` ≤100；字段 ≤8000
- 已译冲突 → `skipped`；非法 → `invalid` + `invalid_items[].reason`
- 成功写入 LocaleDictionary 后按 locale `publishLocale`；**不写模块 CSV**

### POST `…/postCollectStart`

Body：`{ "website_id":0 }`（website 仅审计）

- 返回 `{ "task_id": "<32hex>" }`
- 同安装单飞；已有 active → 422
- 执行 `DictionaryCollectService::collect(..., enqueueAiTranslation: false)`

### GET `…/getCollectStatus?task_id=…`

- 响应：`task_id,status,percent,message,error?,owner_bound`
- `status` ∈ `starting|running|completed|failed|expired`
- 非所有者 / 未知 → **404**
- TTL 24h

## 本机冒烟

```bash
php app/code/Weline/I18n/scripts/smoke-remote-translation-rest.php
# 证据：generated/tmp/remote-translation-rest-smoke.json（须 ok=true）
```

文档深链（后台 API 文档页）：

- Websites 选站：`/zh_Hans_CN/dev/tool/docs/api?module=Weline_Websites&q=远程翻译`
- I18n 远程协助：`/zh_Hans_CN/dev/tool/docs/api?module=Weline_I18n&q=RemoteTranslation`

客户端顺序：

- 缺源词：`collectStart` → poll → `pending` → `ingest`
- 有源词：`pending` → `ingest` → 可选 `collectStart`

## 非目标

- 不打本机 AI cron；远程 collect 不入队站内 AI
- 不默认生产；生产另案且先备份
- 不做 SSE
