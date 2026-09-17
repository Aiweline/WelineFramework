# Channel form sections-after hook

Hook: `Weline_Websites::backend::channel::form::sections-after`

Purpose: allow independent modules to append channel-scoped configuration sections to the admin channel edit form.

Guidelines:

- `Weline_Websites` owns only core channel fields (name).
- Extension modules should post data under `extensions[{module_code}]`.
- Extension modules should persist data by observing `Weline_Websites::channel_save_after`.
- Channel templates must not call Shipping/SEO services directly.

Payload keys available in the hook: `id`, `channel`, `channel_id`, `store_id`, `website_id`.

## Built-in: 站点联系信息

已保存渠道时嵌入 `website_contact`（锁 `scope_kind=channel`）；未保存仅提示。见 website hook 文档与 `SiteContactScopeMapper::forChannel`。
