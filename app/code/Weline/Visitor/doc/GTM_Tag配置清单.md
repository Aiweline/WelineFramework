# GTM Tag 配置清单（标准）

代码只保证 `dataLayer` 推送 `event=weline_visitor`。请在 GTM 后台按本清单配置 Tag / Trigger。

## 前置

1. 站点开启 `visitor/tracking/gtm_enabled` 并填写 `GTM-XXXX`
2. 确认 GA4 直连已因互斥关闭
3. Pixel 不推送 `page_view`（由 GA4 Configuration Tag `send_page_view` 负责）

## dataLayer 关键字段

| 键 | 用途 |
|----|------|
| event | 固定 `weline_visitor` |
| weline_event | Weline 标准名 |
| ga4_event | 映射后的 GA4 事件名（CTA 默认 `cta_click`） |
| event_id | 去重 |
| page_* / website_* | 漏斗归因 |
| weline_dict_version / weline_mapping_source | 诊断 |

电商相关 push 前会清 `items/value/transaction_id/...`。

## 推荐 Trigger

1. **Custom Event** `weline_visitor`
2. 可选：再按 `ga4_event` 等于 `cta_click` / `purchase` / `search` 等拆分

## 推荐 Tag

1. **GA4 Configuration**：Measurement ID；`send_page_view=true`
2. **GA4 Event**（事件名 = `{{dlv - ga4_event}}`）：触发器 `weline_visitor`，且 `ga4_event` 不为空、不为 `page_view`
3. 参数映射：`page_location`、`page_path`、`website_id`、`event_id`、`link_url`、`link_text`、`transaction_id`、`value`、`currency`、`items`

## 校验

- GTM Preview：点击 CTA 出现 `weline_visitor`，且无 Pixel 重复 `page_view`
- 网络面板：开 GTM 时无第二套 gtag collect 双计
