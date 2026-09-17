# Store form sections-after hook

Hook: `Weline_Websites::backend::store::form::sections-after`

Purpose: allow independent modules to append store-scoped configuration sections to the admin store edit form.

Guidelines:

- `Weline_Websites` owns only core store fields (name/mode/url).
- Extension modules should post data under `extensions[{module_code}]`.
- Extension modules should persist data by observing `Weline_Websites::store_save_after`.
- Store templates must not call Shipping/SEO services directly.

Payload keys available in the hook: `id`, `store`, `store_id`, `website_id`.

## Built-in: 站点联系信息

已保存商店时嵌入 `website_contact`（锁 `scope_kind=store`）；未保存仅提示。见 website hook 文档与 `SiteContactScopeMapper::forStore`。
