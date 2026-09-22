# contracts — remote-translation-rest（冻结）

## 包络

框架 `BackendRestController::success/error`：`{success,code,msg|message,data,...}`。成功 **code=200**。

`api_admin`：读本机 `app/etc/env.php` → `router.area_routes.rest_backend.prefix`（历史键 `api_admin` 可能为空）；冒烟 Token 用环境变量 / Installation，**禁止**写入文档明文生产密钥。文档示例用 `YOUR_ADMIN_TOKEN`。

## Path 表（真实形态）

模块 router：文档写 `weline_websites` / `weline_i18n` 为意图名；**本机路由注册实际为** `websites` / `i18n`（见 `generated/routers/backend_rest_api.php`，与既有 Provisioning 一致）。客户端以编译后的 router 为准。

| HTTP | Path（相对 api_admin，本机 router） | Controller::method | 入参 |
|------|------------------------|--------------------|------|
| GET | `/websites/rest/v1/RemoteTranslationCatalog/getWebsites` | `RemoteTranslationCatalog::getWebsites` | 无 |
| GET | `/websites/rest/v1/RemoteTranslationCatalog/getLanguages` | `RemoteTranslationCatalog::getLanguages` | query `website_id` int |
| POST | `/i18n/rest/v1/RemoteTranslation/postPending` | `RemoteTranslation::postPending` | body JSON |
| POST | `/i18n/rest/v1/RemoteTranslation/postIngest` | `RemoteTranslation::postIngest` | body JSON |
| POST | `/i18n/rest/v1/RemoteTranslation/postCollectStart` | `RemoteTranslation::postCollectStart` | body JSON |
| GET | `/i18n/rest/v1/RemoteTranslation/getCollectStatus` | `RemoteTranslation::getCollectStatus` | query `task_id` |

无 path `:id` 段。

## ACL source_id（钉死）

| source_id | 用途 |
|-----------|------|
| `Weline_Websites::rest_v1_remote_translation_catalog` | 类 |
| `Weline_Websites::rest_v1_remote_translation_catalog_websites` | getWebsites |
| `Weline_Websites::rest_v1_remote_translation_catalog_languages` | getLanguages |
| `Weline_I18n::rest_v1_remote_translation` | 类 |
| `Weline_I18n::rest_v1_remote_translation_pending` | postPending |
| `Weline_I18n::rest_v1_remote_translation_ingest` | postIngest |
| `Weline_I18n::rest_v1_remote_translation_collect_start` | postCollectStart |
| `Weline_I18n::rest_v1_remote_translation_collect_status` | getCollectStatus |

App Token Installation 仅登记上表（可按环境拆读写 Token）。

## Query ops（I18n provider `i18n_remote_translation`）

| operation | mode | auth | backend_acl.source | external | frontend |
|-----------|------|------|--------------------|----------|----------|
| `remoteTranslationPending` | read | backend | `Weline_I18n::rest_v1_remote_translation_pending` | false | false |
| `remoteTranslationIngest` | write | backend | `Weline_I18n::rest_v1_remote_translation_ingest` | false | false |
| `remoteTranslationCollectStart` | write | backend | `Weline_I18n::rest_v1_remote_translation_collect_start` | false | false |
| `remoteTranslationCollectStatus` | read | backend | `Weline_I18n::rest_v1_remote_translation_collect_status` | false | false |

薄 Rest 仅 `w_query('i18n_remote_translation', op, params)`。Websites Rest 调 `w_query('websites','getWebsiteList'|'getWebsiteLanguageCodes')`。

## Schema

### getWebsites data

```json
{ "items": [ { "website_id": 0, "code": "default", "name": "…", "default_language": "zh_Hans_CN", "status": 1 } ] }
```

### getLanguages data

```json
{ "website_id": 0, "locales": [ { "code": "zh_Hans_CN", "name": "简体中文", "is_default": true } ] }
```

空 codes → `locales: []`（不回退全球）。未知 website_id → 404 风格业务错误。

### postPending body

```json
{ "website_id": 0, "locales": ["en_US"], "limit": 50, "cursor": null }
```

- `limit`：默认 50，钳制 `1..200`
- `cursor`：不透明；服务端为 base64url(JSON`{"o":offset}`)；响应 `next_cursor` / `has_more`
- `website_id`：仅语种门禁；词库全局
- 未译：`NOT (TRIM(translate)<>'' AND translate<>word)`
- 条目：`{ "source","module","locale" }`（每个 locale 各出一行 pending）

站外 locale → 422。

### postIngest body

```json
{
  "website_id": 0,
  "items": [ { "source": "你好", "locale": "en_US", "translation": "Hello" } ]
}
```

- `items` 上限 **100**；`source`/`translation` 长度上限 **8000**
- 已有非空且 `translation !== source` → **skipped**
- 空 translation / 非法 locale / 超长 → **invalid**（原因码：`empty_translation`|`locale_not_allowed`|`empty_source`|`too_long`|`duplicate_in_batch`）
- 成功写入 LocaleDictionary + **publishLocale**（批量按受影响 locale）；**禁止**写模块 CSV
- 响应：`{ written, skipped, invalid, invalid_items?:[{index,reason}] }`（默认不回显全文）

### collect 状态机

`status` ∈ `starting|running|completed|failed|expired`（映射 Resumable 语义子集）。

- `postCollectStart` → `{ task_id }`（32 hex CSPRNG）；可选 `website_id` 仅审计
- `getCollectStatus` → `{ task_id, status, percent, message, error?, owner_bound }`
- 非所有者 / 未知 task → **统一 404**（防枚举）
- TTL **24h**；同安装 **单飞**：已有 active collect → 422
- 执行：`DictionaryCollectService::collect(..., enqueueAiTranslation: false)`；compile 后既有 republish 观察者链保持

## 错误码（业务）

| 场景 | HTTP/业务倾向 |
|------|----------------|
| 未鉴权 | 401 |
| 缺 ACL | 403 |
| website 不存在 | 404 |
| locale 越界 / 校验失败 | 422 |
| task 不存在/越权 | 404 |
| collect 并发冲突 | 422 |
| items/limit 超限 | 422 |

## 安全钉死值

- website 授权：持有上述 ACL 的 Admin/App Token 可访问全部 website_id（多站同后台）；未知站 404
- 生产写入：本机冒烟禁默认生产；生产须明示+备份（运维另案）
- 凭据：文档占位符；不放宽 CORS
- 审计：pending/ingest/collect 打 w_log（actor/installation/website_id/counts，无全文 Token）

## 客户端顺序

- 缺源词：`collectStart`→poll→`pending`→`ingest`
- 有源词：`pending`→`ingest`→可选 `collectStart`
