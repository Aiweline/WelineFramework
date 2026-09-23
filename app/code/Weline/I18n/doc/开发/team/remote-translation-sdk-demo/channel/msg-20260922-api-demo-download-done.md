# channel — Team:API: API Demo 下载协助已交付（REST 修正收口）

- from: Team:API:
- agent_id: 31236d64-e551-41d8-863b-f2e9fd997290
- at: 2026-09-22
- status: pass
- notify_pm: true

## 摘要

按架构 [`../meetings/架构-demo目录约定.md`](../meetings/架构-demo目录约定.md) 完成 **Weline_Api** 统一 Demo 下载协助 + Worker 并块投影 + DW/Theme 渲染；并修正 `/api/` = `rest_frontend` 与模块 router `api` 冲突。

### 交付

| 项 | 落点 |
|----|------|
| Service | `Weline\Api\Service\ApiDemoPackageService` |
| 下载入口 | `GET /api/api-demo/download?module=&demo=&lang=php\|js`（`Api\ApiDemo::getDownload` · `FrontendRestController`） |
| 路由 | Framework `collectFrontendApiDemoRouteAlias`（剥外层 `api/` → `api-demo/download`） |
| 鉴权 | `PublicApiAuthRouteMatcher` DEMO 白名单（公开 zip，无 Token） |
| 文档投影 | `ApiDocService::generateFrontendWorkerApis` → `example.demos` + `demo_auth_hint` |
| 前端 | DW `api-docs.js` + Theme `weline-developer-api.js`（`hasDemos` / Demo 下载） |
| 权威源 | 只读 `{moduleBase}/source/api-demo/` |
| 首例包 | `I18n/source/api-demo/i18n_remote_translation/{php,js}/` |
| UT | ApiDemo 契约 + `PublicApiAuthRouteMatcherTest::testMatchesApiDemoDownloadPublicRoute` → **OK** |
| HTTP 冒烟 | php/js 均 `200 application/zip`（2337 / 2024 bytes） |
| 版本 | Api `1.0.8` / Framework `2.5.152` |

### 冒烟 URL

- https://p05113ef3.test.weline.com:9555/api/api-demo/download?module=Weline_I18n&demo=i18n_remote_translation&lang=php
- https://p05113ef3.test.weline.com:9555/api/api-demo/download?module=Weline_I18n&demo=i18n_remote_translation&lang=js

@项目经理：本席已交付/上报，请检查并更新 SESSION。
