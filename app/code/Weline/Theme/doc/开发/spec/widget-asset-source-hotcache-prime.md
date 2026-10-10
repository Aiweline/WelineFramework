# widget-asset-source-hotcache-prime

status: ready-for-plan → construction

## 用户故事

作为店面访客，在换语种/换页冷切时，不应因部件 CSS/JS 源袋空而额外付出 ~2s 墙钟。

## EARS

- When soft-clear 或投影池空袋后首次打开非默认语 PLP，the system shall 以 HotCache HIT 或 ≤200ms 的 widget_asset builder 服务 `theme.widget_asset_source`。
- When 同 URL 第二次请求（`_wb_nc`），the system shall 不出现大额 widget_asset builder。
- When `cache:clear`（非 -f 亦可），the system shall 预热 widget asset source 键并回报条数。

## UC

| id | 验收 |
|----|------|
| UC-asset-1a | 空袋后 `de_DE/products` hit1：widget_asset builder Σ≤200ms 或仅 shared_read |
| UC-asset-1b | hit2 无大额 widget_asset builder |
| UC-asset-2 | Clear/warmer warmed>0 |

## 架构

- mechanism / owning / not_to_do：见团队 align-freeze 与计划正文。
