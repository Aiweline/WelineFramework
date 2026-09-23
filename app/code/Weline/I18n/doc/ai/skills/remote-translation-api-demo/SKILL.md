---
name: remote-translation-api-demo
description: "Download and verify Weline_I18n remote-translation Admin REST PHP/JS api-demo (type=phrase|meta|local_model). Use when user asks 下载demo/测远程翻译/i18n_remote_translation/WELINE_REMOTE_TYPE/api-demo download."
---

# 远程翻译 API Demo 下载与验收

## When To Use

- 用户要**下载** `i18n_remote_translation` Demo 并测试
- 验证 `type=phrase|meta|local_model` pending / collect 对 local_model 的 422
- 仿照本模块做其它 Query 的 `source/api-demo/` 包

## Load First

1. `app/code/Weline/I18n/doc/远程翻译API-Demo下载与验收.md`（**操作权威**）
2. `app/code/Weline/I18n/doc/rest-api-remote-translation.md`
3. `app/code/Weline/I18n/doc/开发/team/remote-translation-rest/contracts.md`
4. 包内 README：`source/api-demo/i18n_remote_translation/README.md`

## Steps

1. 解析本机 `BASE`（`*.test.weline.com`）与 `rest_backend.prefix`
2. 下载 zip：`GET /api/api-demo/download?module=Weline_I18n&demo=i18n_remote_translation&lang=php|js`
3. 后台登录拿 Bearer → 设 `WELINE_BASE_URL` / `WELINE_ADMIN_PREFIX` / `WELINE_ADMIN_TOKEN`
4. `WELINE_REMOTE_TYPE=phrase|meta|local_model` 各跑一遍 PHP `run.php` 与 JS `index.js`
5. 断言：exit 0、`postPending HTTP 200`、`data.type` 匹配、items shape 正确
6. 可选：`type=local_model` 调 collect-start → **422**
7. 若 HTTP 行为旧于源码：`php bin/w server:reload default` 后再测
8. 改核后可先跑 `scripts/smoke-remote-translation-rest.php`（不经 zip）

## Guardrails

- Demo = **Admin REST**，禁止当 Frontend Worker / BinQuery
- 禁止把真实 Token 写入仓库或 zip
- `local_model` **禁止** collect
- WidgetI18n 中文源串 = **phrase**，不是 `@meta::`
- 查询/冒烟默认本机；未明示生产禁止 SSH
- 禁止 `git restore`/`clean` 擦脏工作区

## Output

向用户回报：下载 URL、三 type×PHP/JS 通过表、必要时 collect 422 证据；落盘目录可用 `generated/tmp/api-demo-smoke/`。
