# channel — 坏版首页热修 done（父会话）

日期：2026-09-23  
`work_mode`: default_theme + design_theme（hanfu）  
`notify_pm: true`

## 根因
Wave-3 MALL-01 对 `.wpc-media`/`img` 加 `max-height:7.5rem`，叠加方图锁失败时 `.wpc-image` 变 `static` → 顶条图+空白。Hero 整块 overflow 裁 CTA。

## 已改
- `Theme/view/theme/frontend/layouts/homepage/default.phtml`
- `app/design/Weline/hanfu/frontend/layouts/homepage/default.phtml`

## 验收（nocache）
| 项 | 结果 |
|----|------|
| 精选卡主图铺满 | fillPct=100%，position=absolute |
| Hero CTA | 浏览精选/按场景选 visible 且 inHero |
| Illustrative scene | absent |
| 标题 | 「特色产品」完整 |

验收面：https://p05113ef3.test.weline.com:9555/zh_Hans_CN/
