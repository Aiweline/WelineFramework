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

三 type 共用上表 ACL；**不新开 Path**。

## `type`

`type ∈ {phrase, meta, local_model}`；缺省 **`phrase`**。

| type | pending | ingest | collect* |
|------|---------|--------|----------|
| `phrase` | 词典，排除 `@meta::%` | LocaleDictionary + publishLocale | 允许 |
| `meta` | 仅 `@meta::%` | 同上 | 允许 |
| `local_model` | Local 未译字段 | Local upsert（冲突 skip） | **422** |

店面 `WidgetI18n::label('中文')` → **phrase**，不是 `@meta::`。

## 接口

### POST `…/postPending`

Body：`{ "website_id":0, "locales":["en_US"], "limit":50, "cursor":null, "type":"phrase" }`

- `limit` 钳制 `1..200`
- 响应：`items` / `next_cursor` / `has_more` / `limit` / `type`
- phrase/meta 条目：`{source,module,locale}`
- local_model 条目：`{local_model,local_id_field,record_id,field,source,locale}`

### POST `…/postIngest`

Body：`{ "website_id":0, "type":"phrase", "items":[{ "source","locale","translation" }] }`

- `items` ≤100；字段 ≤8000
- phrase：`source` 不得以 `@meta::` 开头；meta 必须
- local_model items：`local_model,local_id_field,record_id,field,locale,translation`（`source` 可选）
- 已译冲突 → `skipped`；非法 → `invalid` + `invalid_items[].reason`
- phrase/meta 成功写入后按 locale `publishLocale`；**不写模块 CSV**

### POST `…/postCollectStart`

Body：`{ "website_id":0, "type":"phrase" }`

- `type=local_model` → **422**
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

**AI / 下载包验收操作说明（权威步骤）：**  
[`远程翻译API-Demo下载与验收.md`](远程翻译API-Demo下载与验收.md)  
技能：`doc/ai/skills/remote-translation-api-demo/SKILL.md`（触发：下载 demo / 测远程翻译 / `WELINE_REMOTE_TYPE`）。

可下载 Demo（三 type pending）：

```bash
export WELINE_REMOTE_TYPE=phrase   # 或 meta / local_model
# 见 source/api-demo/i18n_remote_translation/
# 下载：/api/api-demo/download?module=Weline_I18n&demo=i18n_remote_translation&lang=php|js
```

文档深链（后台 API 文档页）：

- Websites 选站：`/zh_Hans_CN/dev/tool/docs/api?module=Weline_Websites&q=远程翻译`
- I18n 远程协助：`/zh_Hans_CN/dev/tool/docs/api?module=Weline_I18n&q=RemoteTranslation`

客户端顺序：

- phrase/meta 缺源词：`collectStart` → poll → `pending` → `ingest`
- phrase/meta 有源词：`pending` → `ingest` → 可选 `collectStart`
- local_model：仅 `pending` → `ingest`（勿 collect）

## 非目标

- 不打本机 AI cron；远程 collect 不入队站内 AI
- 不默认生产；生产另案且先备份
- 不做 SSE
