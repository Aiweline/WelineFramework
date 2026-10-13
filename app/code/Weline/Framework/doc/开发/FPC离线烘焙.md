# FPC 离线烘焙（防窜站）

## 一句话

**离线包 ≠ 烘焙渲染。** `fpc:bake:export|import` 只打包/导入**已有** host-scoped 冷盘；真正 HTTP 暖盘请用 `website:fpc:bake`（或 cron Storefront Warmup）。

一站一包：查找身份 = `scheme://host[:port]/path`（含语种路径）；包内相对路径只当文件指针；导入只落 **`generated/html/live/{host}/…/index.html[.br]`**（代际指针：`live`→`generations/{gen}`；Nginx try_files / WLS html-disk 权威树），Shared L2 指针也必须是 **live 逻辑路径**（禁止 `generations/{id}` 绝对路径）；**禁止**再写 `router-fpc-payloads` / `FpcPayloadPathIndex::remember`；**禁止** path-only / 跨 `website_code` / 跨 origin。

运行时权威冷盘：`FpcHtmlDiskStore`（`generated/html` + live/previous 代际）。整树失效 = 软 rotate 到空代际（保留 previous 可回滚），**禁止**硬铲 live。主题发版 = staging 种子自 live → 暖 → promote。遗留 `var/cache/router-fpc-payloads` 仅 hydrate/purge 迁移期兼容。

## 暖盘 → 导出闭合（推荐）

```bash
# 站点编排：同步 sitemap 表 URL → 本机 connect-only 暖盘（Host=目标 origin）→ 自动 export
php bin/w website:fpc:bake \
  --website=SITE_CODE \
  --origin=https://www.example.com \
  --dir=/tmp/fpc-bake-pkg
# 可选：-f 全力；--load-target=70；--connect=https://127.0.0.1:9555；--dry-run；--skip-export
```

归属：编排壳 `Weline_Websites`；引擎 `Weline_Framework\Http\Fpc\Bake\*`；sitemap 源 `Weline_Seo` 门面。

## CLI（纯打包）

```bash
# 导出（先 HTTP 暖盘，或已有 host-scoped 冷盘）
php bin/w fpc:bake:export --website=SITE_CODE --origin=https://example.com --dir=/tmp/fpc-bake-pkg \
  --url=https://example.com/ --url=https://example.com/en_US/

# 导入（站名 + origin 必须与包精确一致；默认只写冷盘+索引）
php bin/w fpc:bake:import --website=SITE_CODE --origin=https://example.com --dir=/tmp/fpc-bake-pkg
```

别名：`fpc:bake-export` / `fpc:bake-import`。

## 负载语义双轨（勿混用）

| 机制 | 场景 | 语义 |
|---|---|---|
| `StorefrontWarmupBusyGate` | cron / `cache:clear` 切片暖 | 忙则**停**（默认 abort≈70%） |
| `LoadTargetConcurrencyController` | `website:fpc:bake` | 负载**钉**在目标附近（默认 target≈70%）；`-f` 全力 |

**禁止**用 BusyGate 实现「保持 70%」；**禁止**把 LoadTarget 塞进 cron Orchestrator。

Bake HTTP 为 **connect-only**：TCP 只打本机/连接面，`Host` 钉目标 origin；并带 `X-Forwarded-Proto` / `X-Forwarded-Port`（https→443），避免直连 Worker 非标口（如 `:9555`）把 FPC index key 写成 `host:9555` 导致 export `no_entries`；**禁止**失败后直连生产 host（不同于 Warmup `getAbsolute` fallback）。

## 多语言 / 货币

不同 URL（如 `/` 与 `/en_US/`）各一条 entry，携带 `lang`/`currency`。Cookie 语种不分叉（与现网 FPC 一致）。未暖过的语种不会出现在包内。

## 商品变更清理（多语）

`UrlMatrixExpander` 按 **path 语种/货币前缀** 扩 purge URL（默认无前缀 + `/en_US/…` 等），与离线烘焙 / FPC 冷盘键对齐。  
商品/目录 `purge_fpc_urls` 会清掉对应中英冷盘；不再依赖已废弃的 `?_wl=&_wc=` 查询标记。

## 不做什么

- 裸拷贝 `var/cache/router-fpc-*`（绝对 BP 不可移植）
- 导入时改 HTML host / 跨站 remap
- 复用源站 Shared `@ns/` 物理 key 充当目标 HIT
- 把 export/import 当成会发起 HTTP 渲染

## 归属

- 离线包：`Weline_Framework` · `Http\Fpc\OfflineBake\*` · `Http\Console\Fpc\Bake\Export|Import`
- 负载感知暖盘引擎：`Weline_Framework` · `Http\Fpc\Bake\*`
- 站点命令：`Weline_Websites` · `website:fpc:bake`
