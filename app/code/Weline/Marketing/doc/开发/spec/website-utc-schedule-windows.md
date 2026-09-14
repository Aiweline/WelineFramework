---
status: ready-for-plan
work_kind: feature
feature_slug: website-utc-schedule-windows
module: Weline_Marketing
updated: 2026-09-14
---

# 规格：站点默认时区录入 → UTC 存判

## 分类

- work_kind: feature
- FE/BE: BE 时钟契约 + 后台表单回填/旁注 + 店面/结账判定
- ui_skill_decision: participate（后台 datetime 旁注站点时区）

## 澄清记录

- 录入按 Website `default_timezone` 墙钟；落库与判定用 UTC 瞬间。
- 覆盖：Marketing 活动/规则/券、Promotion 特价主题窗、Theme 布局排期。
- Framework `DateTime\Timezone` 为唯一入口；业务禁止自写 `date()`/`strtotime()` 比窗。

## 用户故事

作为多站运营，我希望按站点默认时区配置活动开始结束，系统以 UTC 存储并判定，以便不同地区服务器上同一场活动开停一致。

## EARS

- When 运营在后台填写活动/规则/券/布局/特价主题的开始或结束时间，系统 shall 按当前 Website 默认时区解释无偏移墙钟，并存为 UTC `Y-m-d H:i:s`。
- When 结账或店面判定规则/券/特价主题/布局是否在窗内，系统 shall 用 UTC now 与库内 UTC 起止比较，不得使用 PHP 进程默认时区。
- When 回填后台 `datetime-local`，系统 shall 将 UTC 转为站点墙钟 `Y-m-d\TH:i`。
- While 业务模块处理「开始结束 / 定时生效」，系统 shall 只经 `Weline\Framework\DateTime\Timezone`，不得私有第二套时钟。
- When 存量无偏移时间迁移，系统 shall 按站点默认时区（无则 UTC）解释后改写为 UTC。
- If 起止任一端为空，系统 shall 将该端视为不限制。

## 用例

### UC1 上海站配置黑五零点开售

1. Website `default_timezone=Asia/Shanghai`。
2. 运营录入 `2026-11-27T00:00`。
3. 库内存 `2026-11-26 16:00:00`（UTC）。
4. UTC now 到达该瞬间后，规则/主题生效。

### UC2 跨时区服务器判定一致

1. 同一 UTC 窗数据。
2. Worker 进程时区分别为 UTC 与 Asia/Shanghai。
3. `isWithinUtcWindow` 结果相同。

### UC3 特价主题窗外不出价

1. 主题 `status=active`，但 UTC 窗外。
2. 店面 ActiveDealResolver 不返回该折扣。

### UC4 布局排期边界

1. 布局 `starts_at`/`ends_at` 为 UTC。
2. `resolveActive` 用 UTC now；`timezone` 列仅审计/回显。

## 非目标

- 订单/支付展示换算、买家个人时区、Store 独立时区、生日「当日」、Cron 全局改站点 TZ。
- 为站点请求调用 `date_default_timezone_set`。
