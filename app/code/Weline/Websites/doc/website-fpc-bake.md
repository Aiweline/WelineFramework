# website:fpc:bake（站点 Sitemap → FPC 烘焙）

## 一句话

CLI-only：刷新该站 sitemap URL（表）→ 按目标 `--origin` 做本机 connect-only 负载感知暖盘 → 可选打 OfflineBake 包。

## 命令

```bash
php bin/w website:fpc:bake \
  --website=SITE_CODE_OR_ID \
  --origin=https://www.example.com \
  --dir=/tmp/fpc-bake-pkg
```

别名：`website:sitemap-fpc-bake`。

| 参数 | 含义 |
|---|---|
| `--website=` | 站 code 或 id |
| `--origin=` | FPC 冷盘身份（请求 Host） |
| `--dir=` | export 包目录（默认可生成时间戳路径） |
| `-f` / `--full` | 全力（不按负载降并发） |
| `--load-target=70` | 目标 CPU 负载 % |
| `--connect=` | 本机连接面（不得等于 origin host） |
| `--skip-sitemap-refresh` / `--skip-export` / `--dry-run` | 排障开关 |

## 前置

- 本机 `DetectWebsite` 须能把 `--origin/` 解析到所选 `--website`（域名绑定）。
- 可选依赖 `Weline_Seo`（soft-pull `SitemapBakeSourceInterface`）；未装则失败提示。
- URL 源是 `weline_sitemap_url` 活跃行；dev/test `hard_gate` 可能不写可索引 XML，仍可暖盘。

## 归属边界

- 本模块：薄编排 + CLI + 站身份门禁。
- `Weline_Seo`：refresh / listActiveLocs 门面；另有 `sitemap:refresh --website=`。
- `Weline_Framework`：`SitemapFpcBakeEngine` + `LoadTargetConcurrencyController` + OfflineBake export。

交叉文档：[FPC离线烘焙.md](../../Framework/doc/开发/FPC离线烘焙.md)。
