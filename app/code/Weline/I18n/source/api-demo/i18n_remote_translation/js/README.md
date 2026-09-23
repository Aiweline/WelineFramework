# i18n_remote_translation — Admin REST API Demo

可下载示例：用**后台 Admin Token**调用远程协助翻译 Admin REST（选站 → 语种 → 未译 → 录入；收集可选）。

**禁止**把真实 Token 写进本目录或提交到 Git。只用环境变量。

## 环境变量

| 变量 | 含义 | 示例 |
|------|------|------|
| `WELINE_BASE_URL` | 站点根 URL（无尾斜杠） | `https://p05113ef3.test.weline.com:9555` |
| `WELINE_ADMIN_PREFIX` | 后台 REST 前缀（`env.php` → `router.area_routes.rest_backend.prefix`） | `api_admin` 或实际前缀 |
| `WELINE_ADMIN_TOKEN` | 后台登录拿到的 Bearer Token | （勿写入文件） |

可选：

| 变量 | 默认 | 含义 |
|------|------|------|
| `WELINE_WEBSITE_ID` | `0` | 目标 website_id |
| `WELINE_LOCALES` | `en_US` | 逗号分隔目标语种 |
| `WELINE_REMOTE_TYPE` | `phrase` | `phrase` \| `meta` \| `local_model` |
| `WELINE_TLS_INSECURE` | 自动 | 设为 `1` 可强制跳过 TLS 校验；对 `*.test.weline.com` / `localhost` JS Demo 默认放宽（与 PHP curl 对齐） |

## `type` 说明

| type | pending 源 | ingest 写回 | collect* |
|------|------------|-------------|----------|
| `phrase`（默认） | 词典词条，排除 `@meta::%` | LocaleDictionary + publishLocale | 允许 |
| `meta` | 仅 `@meta::…` 键 | 同上 | 允许 |
| `local_model` | Local 表未译字段行 | Local 表 upsert（冲突 skip） | **422**（勿调用） |

店面 `WidgetI18n::label('中文源')` 属于 **phrase**（中文源串），**不是** `@meta::`。

## 路径（相对 `/{WELINE_ADMIN_PREFIX}/`）

| 步骤 | Method | Path |
|------|--------|------|
| 可选网站 | GET | `/websites/rest/v1/remote-translation-catalog/websites` |
| 网站语种 | GET | `/websites/rest/v1/remote-translation-catalog/languages?website_id=0` |
| 取未译 | POST | `/i18n/rest/v1/remote-translation/pending` |
| 录入译文 | POST | `/i18n/rest/v1/remote-translation/ingest` |
| 启动收集 | POST | `/i18n/rest/v1/remote-translation/collect-start` |
| 收集状态 | GET | `/i18n/rest/v1/remote-translation/collect-status?task_id=…` |

完整 URL 形如：

```text
{WELINE_BASE_URL}/{WELINE_ADMIN_PREFIX}/websites/rest/v1/remote-translation-catalog/websites
{WELINE_BASE_URL}/{WELINE_ADMIN_PREFIX}/i18n/rest/v1/remote-translation/pending
```

鉴权头：`Authorization: Bearer {WELINE_ADMIN_TOKEN}`。

## 请求示例

### phrase pending

```json
{ "website_id": 0, "locales": ["en_US"], "limit": 5, "cursor": null, "type": "phrase" }
```

响应条目：`{ "source", "module", "locale" }`。

### meta pending

```json
{ "website_id": 0, "locales": ["en_US"], "limit": 5, "type": "meta" }
```

条目 `source` 形如 `@meta::theme.foo`。

### local_model pending

```json
{ "website_id": 0, "locales": ["en_US"], "limit": 5, "type": "local_model" }
```

条目：

```json
{
  "local_model": "Weline\\…\\FooLocalDescription",
  "local_id_field": "entity_id",
  "record_id": 42,
  "field": "name",
  "source": "汉服上衣",
  "locale": "en_US"
}
```

### ingest（phrase）

```json
{
  "website_id": 0,
  "type": "phrase",
  "items": [{ "source": "你好", "locale": "en_US", "translation": "Hello" }]
}
```

### collect（仅 phrase/meta）

```json
{ "website_id": 0, "type": "phrase" }
```

`type=local_model` → HTTP/业务 **422**。

## 运行

### PHP

```bash
cd php
export WELINE_BASE_URL='https://YOUR_HOST'
export WELINE_ADMIN_PREFIX='YOUR_API_ADMIN_PREFIX'
export WELINE_ADMIN_TOKEN='YOUR_ADMIN_TOKEN'
# 可选：export WELINE_REMOTE_TYPE=meta
php run.php
```

### JS (Node ≥ 18)

```bash
cd js
npm install
export WELINE_BASE_URL='https://YOUR_HOST'
export WELINE_ADMIN_PREFIX='YOUR_API_ADMIN_PREFIX'
export WELINE_ADMIN_TOKEN='YOUR_ADMIN_TOKEN'
# 可选：export WELINE_REMOTE_TYPE=local_model
npm start
```

默认只跑到 **pending**（带 `type`）；`ingest` / `collect` 在脚本里以注释示例保留，避免误写生产数据。  
三 type 冒烟：分别设 `WELINE_REMOTE_TYPE=phrase|meta|local_model` 各跑一次 pending。

## 说明

- Query Provider：`i18n_remote_translation`（`demo => true`，ops `frontend=true`，`external=false`，`auth=backend`）。
- 页内 Backend REST Demo：`api.demo` id = `remote-translation-assist-demo`（API 文档登录后操作，无需本包 Token）。
- 下载 zip 由 `Weline_Api` 协助端点打包本目录 `php/` / `js/`，不污染 BinQuery / `pub/source`。
- Demo 鉴权提示：本包调用 **Admin REST**，需后台 Token；勿与 Frontend Worker / BinQuery 鉴权面混用。
- **Agent 验收步骤**：模块文档 [`doc/远程翻译API-Demo下载与验收.md`](../../../doc/远程翻译API-Demo下载与验收.md)；技能 `doc/ai/skills/remote-translation-api-demo`。
