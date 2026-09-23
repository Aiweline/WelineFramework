# 远程翻译 API Demo：下载与验收（AI 操作说明）

> 面向后续 Agent：用户说「下载 demo / 测一下远程翻译 / 验证 i18n_remote_translation」时按本文执行。  
> 契约权威：`doc/开发/team/remote-translation-rest/contracts.md`；REST 面：`doc/rest-api-remote-translation.md`。  
> 技能入口：`doc/ai/skills/remote-translation-api-demo/SKILL.md`（`get_skill` / 宿主 Read）。

## 何时用

- 验证可下载 PHP/JS Demo 包是否可跑
- 验证 `type=phrase|meta|local_model` 的 pending（及 collect 对 local_model 的 422）
- 新模块仿照首例做 `source/api-demo/{demo_id}/{php|js}/` 时对照目录与下载约定

## 钉死事实

| 项 | 值 |
|----|-----|
| 归属模块 | `Weline_I18n` |
| demo_id | `i18n_remote_translation`（与 Query provider 同名；descriptor `'demo'=>true`） |
| 权威源 | `app/code/Weline/I18n/source/api-demo/i18n_remote_translation/{php,js}/` + 根/`php`/`js` 内 README |
| 下载协助 | `Weline_Api`：`GET /api/api-demo/download?module=Weline_I18n&demo=i18n_remote_translation&lang=php\|js` |
| 业务 REST | Admin Backend REST（**不是** Frontend Worker / BinQuery） |
| Query 核 | `w_query('i18n_remote_translation', …)` |
| Path | **不新开**；仍用 pending / ingest / collect*；用 body `type` 扩面 |
| ACL | `Weline_I18n::rest_v1_remote_translation_*`（三 type 共用） |

### `type`

| type | pending | ingest | collect* |
|------|---------|--------|----------|
| `phrase`（默认） | 词典，排除 `@meta::%` | LocaleDictionary + publishLocale | 允许 |
| `meta` | 仅 `@meta::%` | 同上 | 允许 |
| `local_model` | Local 未译字段行 | Local upsert（冲突 skip） | **422** |

店面 `WidgetI18n::label('中文')` → **phrase**，不是 `@meta::`。

## 下载

本机 Host 优先 `{project_hash}.test.weline.com`（例：`https://p05113ef3.test.weline.com:9555`）。

```text
{BASE}/api/api-demo/download?module=Weline_I18n&demo=i18n_remote_translation&lang=php
{BASE}/api/api-demo/download?module=Weline_I18n&demo=i18n_remote_translation&lang=js
```

```bash
mkdir -p generated/tmp/api-demo-smoke && cd generated/tmp/api-demo-smoke
BASE='https://p05113ef3.test.weline.com:9555'   # 按本机实际 Host 替换
curl -skL -o i18n_remote_translation-php.zip \
  "$BASE/api/api-demo/download?module=Weline_I18n&demo=i18n_remote_translation&lang=php"
curl -skL -o i18n_remote_translation-js.zip \
  "$BASE/api/api-demo/download?module=Weline_I18n&demo=i18n_remote_translation&lang=js"
unzip -oq i18n_remote_translation-php.zip
unzip -oq i18n_remote_translation-js.zip
# 期望包内：run.php|index.js + README.md + composer.json|package.json
```

- HTTP **200** + Zip；`realpath` 必须落在模块 `source/api-demo/` 下（Api 侧卡死）。
- **禁止**把真实 Token 写进 zip / 源码 / 文档。

## 鉴权（Admin Bearer）

Demo 调的是 **rest_backend** 前缀下的 Admin REST：

```bash
API=$(php -r 'echo (require "app/etc/env.php")["router"]["area_routes"]["rest_backend"]["prefix"]??"";')
# 登录拿 token（路径以 generated/routers/backend_rest_api.php 为准）
# POST {BASE}/{API}/api/rest/v1/backend/auth/login
# Body: {"username":"…","password":"…"}  → data.token
```

环境变量（脚本已认）：

| 变量 | 必填 | 说明 |
|------|------|------|
| `WELINE_BASE_URL` | 是 | 站点根，无尾斜杠 |
| `WELINE_ADMIN_PREFIX` | 是 | `rest_backend.prefix` |
| `WELINE_ADMIN_TOKEN` | 是 | 后台 Bearer |
| `WELINE_WEBSITE_ID` | 否 | 默认 `0` |
| `WELINE_LOCALES` | 否 | 默认 `en_US`（逗号分隔） |
| `WELINE_REMOTE_TYPE` | 否 | `phrase`\|`meta`\|`local_model`，默认 `phrase` |
| `WELINE_TLS_INSECURE` | 否 | `1` 强制跳过 TLS；JS 对 `*.test.weline.com` / localhost **默认放宽**（对齐 PHP `SSL_VERIFYPEER=false`） |

## 跑 Demo（默认只到 pending）

```bash
export WELINE_BASE_URL='https://…' WELINE_ADMIN_PREFIX="$API" WELINE_ADMIN_TOKEN='…'
export WELINE_WEBSITE_ID=0 WELINE_LOCALES=en_US

# PHP × 三 type
for t in phrase meta local_model; do
  WELINE_REMOTE_TYPE=$t php i18n_remote_translation-php/run.php
done

# JS × 三 type
for t in phrase meta local_model; do
  WELINE_REMOTE_TYPE=$t node i18n_remote_translation-js/index.js
done
```

### 通过标准

每轮须同时满足：

1. 进程 exit **0**
2. 日志出现 `=== postPending HTTP 200 ===`
3. JSON `data.type` **等于**当前 `WELINE_REMOTE_TYPE`
4. `data.items` 为数组；shape：
   - phrase/meta：`source` / `module` / `locale`；meta 的 `source` 以 `@meta::` 开头；phrase 不得泄漏 `@meta::`
   - local_model：`local_model` / `local_id_field` / `record_id` / `field` / `source` / `locale`

可选补刀（HTTP）：

```bash
# local_model collect → 422
curl -sk -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"website_id":0,"type":"local_model"}' \
  "$BASE/$API/i18n/rest/v1/remote-translation/collect-start"
# 期望 code=422，msg 含「不支持词典 collect」
```

`ingest` / `collect`（phrase|meta）在脚本里是**注释示例**，默认不跑，避免误写词典；需要时再解开。

## 不经下载的本机 Query 冒烟

改完 Service/Query 后可先跑（不依赖 zip）：

```bash
php app/code/Weline/I18n/scripts/smoke-remote-translation-rest.php
# 证据：generated/tmp/remote-translation-rest-smoke.json → ok=true
# 覆盖：phrase/meta/local_model pending、collect+local_model 422、phrase ingest skip
```

契约 UT：

```bash
php vendor/bin/phpunit \
  app/code/Weline/I18n/test/Unit/Query/I18nRemoteTranslationQueryProviderContractTest.php \
  --no-configuration
```

## WLS 热代码

改了 PHP 后若 HTTP 仍像旧行为（例：local_model pending 500 / undefined method）：

```bash
php bin/w server:reload default
```

再重试 Demo。CLI `w_query` 通常立刻用新代码；HTTP 走 Worker，需 reload。

## 页内 Demo（无需下载 Token）

后台 API 文档登录后：`api.demo` id = `remote-translation-assist-demo`（`RemoteTranslationApiDemoDescriptor`）。  
Worker 文档投影：`example.demos` + `demo_auth_hint`（含 `type` / `WELINE_REMOTE_TYPE` 提示）。

## 新模块仿照清单

1. 权威源只放 `{Module}/source/api-demo/{demo_id}/{php|js}/`（禁 `pub/source`、禁 `view/statics/api-demo`）
2. Query descriptor：顶层 `'demo'=>true`（默认同名 demo_id）
3. php/js 目录放 README（zip 只打 lang 目录，根 README 不会进包除非复制进 lang 目录）
4. 脚本用环境变量拿 Token；本地 HTTPS 自签与 PHP curl 行为对齐
5. 下载 URL 由 Api 投影，勿手写平行下载端点

目录约定全文：`doc/开发/team/remote-translation-sdk-demo/meetings/架构-demo目录约定.md`。

## 禁止

- 把生产/本机真实 Token 写入仓库或 zip
- 把 Demo 当成 Frontend Worker / `query-bin` 鉴权面
- `type=local_model` 时调 collect*
- 未明示生产时 SSH 测线上
- 为对齐 HEAD 用 `git restore`/`clean` 擦脏工作区

## 相关路径速查

| 用途 | 路径 |
|------|------|
| PHP/JS 源 | `source/api-demo/i18n_remote_translation/` |
| 薄 Rest | `Api/Rest/V1/RemoteTranslation.php` |
| Query | `extends/module/Weline_Framework/Query/I18nRemoteTranslationQueryProvider.php` |
| Assist | `Service/RemoteDictionaryAssistService.php` |
| LocalModel | `Service/LocalModelTranslation/LocalModelTranslationService.php`（`remotePending`/`remoteIngest`） |
| 页内 demo | `Service/RemoteTranslationApiDemoDescriptor.php` |
| 下载服务 | `Weline_Api` → `Service/ApiDemoPackageService.php` |
| 冒烟脚本 | `scripts/smoke-remote-translation-rest.php` |
