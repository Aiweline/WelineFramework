# Visitor Pixel × GTM / GA4 系统设计（冻结）

> 对应实施计划：Visitor Pixel 系统设计。本文为 Phase 0 冻结合同。

## 1. 管线

```text
track → EventDictionary.resolve → 信封
  → System 入库
  → GtmBridge.pushDataLayer   // 唯一 GTM 出口
  → Sandbox.postMessage       // 模块中转（Phase 6）
```

禁止模块/Custom/旧 iframe 旁路 `dataLayer.push` 或野造 ga4 名。

## 2. 字典字段（`etc/event_dictionary.json`）

| 字段 | 说明 |
|------|------|
| weline_event | Weline 标准事件名（snake_case） |
| ga4_event | 映射的 GA4 / GTM 触发事件名 |
| google_recommended | 是否 Google 推荐事件 |
| event_family | 同义族（如 `cta`）；族内任一标记可满足齐全度 |
| require_exact_marker | true 时必须本事件自己的标记 |
| skip_gtm_push | true 时 Pixel **不**向 dataLayer 推（page_view 交给 GTM/GA4 config） |
| required_params | 运行时必填；缺参=黄灯 param_risk，**不拦** GTM |
| markers.classes / attrs | 静态扫描标记 |
| page_scopes | `home` / `content` / `checkout` / `account` / `*` |

`dict_version` = JSON `version` 字段。

## 3. page_type 分类器（§7.1）

| 判定 | page_type |
|------|-----------|
| `is_home` / type=home / handle=home / path=`/` | `home` |
| URL 或 type 含 checkout、cart、order | `checkout` |
| URL 或 type 含 account、login、register、user | `account` |
| 已发布普通页 | `content` |
| 无法判定 | `page_type_unknown`（仅应用 scopes 含 `*` 的事件） |

AI 站：站点首页 → `home`；其余已发布页 → `content`（再套关键词规则）。

巡检 Diff **必须**使用同一分类器。

## 4. event_family（§7.4）

- 同页出现族内任一 marker → 该 **family 需求**算满足（避免 hero_cta 与 cta_click 双红）
- `require_exact_marker: true` 时忽略族合并，逐事件要求标记

## 5. dataLayer 合同（GtmBridge）

```json
{
  "event": "weline_visitor",
  "weline_event": "cta_click",
  "ga4_event": "cta_click",
  "event_id": "wv-...",
  "page_location": "...",
  "page_title": "...",
  "page_path": "...",
  "page_referrer": "...",
  "website_id": "...",
  "website_code": "...",
  "website_url": "...",
  "language": "...",
  "currency": "CNY",
  "session_id": "...",
  "page_id": "...",
  "content_locale": "...",
  "weline_dict_version": "1.0.0",
  "weline_mapping_source": "dictionary",
  "section_code": "theme.homepage.hero",
  "section_event_key": "theme.homepage.hero:cta_click",
  "section_source_status": "ok"
}
```

- page_view：**Pixel 不重复 push**
- CTA 默认 `ga4_event=cta_click`（可被站点 `ctaEventName` 覆盖）
- 电商相关 push 前清键：items / value / transaction_id 等
- 开 GTM 则关 GA4 直连（互斥）
- **Section 溯源（只增）**：交互事件解析最近父级 `section` 的 `weline-code`；`section_source_status` ∈ `ok|missing_section|missing_code|empty_code|n/a`；缺 code **不丢事件**；交互类缺码且 `window.DEV` 时 `console.warn`；`page_view` 无 element 时 status=`n/a`
- 入库：`browser_info.additionalInfo.source` 同源三字段

## 6. 齐全度与一键巡检

| 灯 | 含义 |
|----|------|
| 红 | `missing_marker`（scoped 内无标记） |
| 黄 | `param_risk`（运行时缺参）— 不拦 GTM |
| 绿 | `complete`（本站 scoped 页均有标记） |
| 灰 | `not_applicable`（本站无对应 page_type，不算齐全） |

- 扫描前剥离 `<script>/<style>/<noscript>/<template>`，避免内联事件字典被当成 DOM 标记
- API：`visitor.auditPixelMarkers`；≤500 URL；page_id 去重；优先默认语言
- `fetch_failed_*` 单列，不算缺标记
- 电商事件仅 `checkout` scope；登录/注册仅 `account`
- 缓存键：`website_id` + `dict_version` + URL 清单 hash；TTL 24h；手动一键即重算
- v1 只扫服务端 HTML；客户端后插可能漏检

## 7. 开发面板 Tab「Pixel / GTM」

- **通道条**：dict / GTM / GA4 / forwarding / consent
- **齐全度**：未检查时只显示用途说明 +「一键检查标记」；检查后才展开页数/绿红灯与按事件、按页明细。打开 Tab 会自动拉取上次缓存报告。
- **试发 CTA**：本页 `WelinePixel.track('cta_click')` 管道冒烟，与齐全度巡检无关。
- 实时流预览在试发区下方。

## 8. 真沙箱（Phase 6）

`sandbox="allow-scripts"`（无 same-origin）；只收信封副本；旧 `#sandbox-pixel` 删除。

## 9. 已知边界

- 不扫客户端后插 DOM
- 黄灯不拦 GTM
- 单站一键 ≤500 URL
- page_scopes v1 仅五类

## 10. 热 / 温 / 冷数据层（波次 G，已落地）

> 运营侧实现细节见 `数据分析功能使用指南.md`「报表查询数据源边界」；本节冻结查询边界与删热门禁，防止长窗静默扫热或未校验删热。

### 10.1 三层职责

| 层 | 表 | 默认保留 | 用途 |
|----|----|----------|------|
| 热 | `w_pixel` | `retention_hot_days`（默认 365） | 明细 list/轨迹/短窗报表 |
| 温 | `pixel_stats_hourly` / `pixel_stats_daily` | `retention_warm_days`（默认 1095，且 ≥ 热天数） | 长窗聚合报表 |
| 冷 | `pixel_archive` | 可配启停 `cold_archive_enabled` | 超热明细归档；显式 UI 查询 |

```text
track → w_pixel（热）
  → Cron 小时/日聚合 → pixel_stats_*（温）+ pixel_stats_job_log
  → Retention（仅 job_log daily=success）→ 迁 pixel_archive → 删热
冷明细查询 ≠ 聚合路由：仅 archive-list
```

### 10.2 温表与 dim_hash

- 流量维白名单：`traffic_type` / `channel_code` / `utm_source` / `utm_medium` / `utm_campaign` / `event_name` / `device_category`
- `dim_hash`：有序取值 → 逐维小写去空白 → `\x1f` 连接 → sha1；缺省维用空串；**禁止**默认把高基 `page_path`/`landing_page` 打进温表
- 唯一键：小时 `(hour_bucket, website_id, dim_hash)`；日 `(day_bucket, website_id, dim_hash)`；重跑覆盖式 UPSERT
- 桶时区：站点 TZ（无则 UTC），写入 `tz` 与 `pixel_stats_job_log`
- **会话口径**：日表 `sessions` / `engaged_sessions` / `bounce_sessions` 权威；小时仅 `session_starts`，禁止 SUM 小时当会话数
- 日表另含 `conversions`、`funnel_json`（漏斗摘要，不是新事实源）

### 10.3 聚合 Cron 与 §2.5 校验

| 任务 | 入口 | 节奏 | 写入 |
|------|------|------|------|
| 小时 | `Cron/PixelStatsHourly` + `PixelStatsHourlyAggregateService` | `5 * * * *` | 仅 `pixel_stats_hourly` + job_log |
| 日 | `Cron/PixelStatsDaily` + `PixelStatsDailyAggregateService` | `15 1 * * *` | 仅 `pixel_stats_daily` + job_log |

- `pixel_stats_job_log` 唯一键 `(job_type, bucket, website_id)`；`status` ∈ `pending|running|success|failed`；`check_json` 存校验摘要
- **日校验门禁**：日表 `events` 合计 vs 热表同日 COUNT，相对误差 ≤ **2%** 或绝对差 ≤ **5**；失败记 `failed`
- 聚合与删热禁止同提交；聚合命令**永不**删 `w_pixel`

### 10.4 QueryRouter（热 + 温；冷不接入）

`Service/Report/PixelQueryRouter`：

| 条件 | 路由 |
|------|------|
| 窗 ≤ 热短窗（默认 7 天）且落在热保留内 | `hot` / `w_pixel` |
| 长窗或早于热保留、仍在温保留内 | 默认 `warm_daily`；仅超热且跨度 ≤2 天 → `warm_hourly` |
| 高基维或超温保留 | 抛 `cold archive route is not available`（禁止静默扫热） |

冷明细**不**走本聚合路由，见 §10.6。

### 10.5 归档与 Retention

| 命令 | 行为 |
|------|------|
| `pixel:archive-migrate` | 按 `before`（默认 now−hot_days）分批复制到 `pixel_archive`；`pixel_id` 唯一幂等；**永不删热**；apply 需 `--enable-apply` |
| `pixel:hot-retention` | 仅 `created_at < now−hot_days` **且** 对应站日桶 `job_type=daily` + `status=success` 才「先迁冷再删热」；缺行/failed **不默认放行**；正式跑需 `--enable-apply` **且** `--enable-delete` |

`cold_archive_enabled=0` 时两命令的 apply 均阻断。配置键（`VisitorTrackingConfig` / SystemConfig「热温冷保留策略」）：

- `visitor/tracking/retention_hot_days`（默认 365）
- `visitor/tracking/retention_warm_days`（默认 1095，钳制 ≥ 热天数）
- `visitor/tracking/cold_archive_enabled`（默认 true）

### 10.6 冷查 UI 硬约束（G09）

- 入口：`pixel-dashboard/archive-list`（ACL `pixel_dashboard_archive_list`）
- 取数：`PixelColdArchiveQueryService` → `pixel_archive`
- **硬拒绝**：缺 `website_id` / `all`；时间窗 > **31** 天（含 `90d` 预设）
- `website_id=0` 为合法系统默认站；查询始终分页（默认 50、最大 200）

### 10.7 门禁

波次 G 单测门禁：`test/Unit/gate-wave-g.sh`（schema / Cron / Router / archive / Retention / 冷查 / 保留配置）。
