# 前端席收口 · newsletter-subscribe（2026-09-22）

| 字段 | 值 |
|------|-----|
| 席位 | **前端** |
| role | 前端 |
| result | **closed** |
| 时间 | 2026-09-22T11:05:00+08:00 |
| 版本 | Newsletter `1.0.3` |

## 任务 A — 后台主题化

- `backend/campaign/config.phtml` → `w-backend-page` + `w-card` + `w-field` / `w-input` / `w-select` / `w-button`；`data-testid=newsletter-campaign-config`
- `backend/subscriber/index.phtml` → 同上 + `w-table-wrap` / `w-table`；`data-testid=newsletter-subscriber-listing`
- 禁止裸 h1+form/table、无 `border-collapse` 硬编码；间距用 `--weline-space-*` / `--w-gap`

## 任务 B — footer-above

- Theme `partials/footer/default.phtml` accept 含 `footer-newsletter`
- `footer-container` 模板无 `footer-newsletter` 槽
- `widget.php` injection：`slot=footer-above` / `area=content`
- 合同测：`NewsletterWidgetOwner` + `FooterAboveTrustBadges` + `FooterContainer` → **OK (17 tests, 163 assertions)**
- `widget:refresh`：已完成（exit 0）

## 任务 C — 前台轻扫

- `footer-newsletter` / `newsletter-popup`：样式均为 `var(--weline-*)` Token，无 hex → **已合规**（未改交互）

## 验收证据（curl 自登 admin/admin）

| URL | HTTP | 标记 |
|-----|------|------|
| [有奖配置](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/newsletter/backend/campaign/config) | 200 | `newsletter-campaign-config` + `w-card` + `w-field` |
| [订阅名单](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/newsletter/backend/subscriber/index) | 200 | `newsletter-subscriber-listing` + `w-table` |

Cursor ide-browser / chrome-devtools 本回合不可用；以带会话 curl HTML 抽检替代截图。Browser 关闭：N/A。

## 交付地址

- [邮件订阅有奖配置](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/newsletter/backend/campaign/config)
- [邮件订阅名单](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/newsletter/backend/subscriber/index)
