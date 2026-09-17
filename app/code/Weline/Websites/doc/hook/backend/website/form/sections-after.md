# Website form sections-after hook

Hook: `Weline_Websites::backend::website::form::sections-after`

Purpose: allow independent modules to append website-scoped configuration sections to the admin website form.

Guidelines:

- `Weline_Websites` owns only core website fields.
- Extension modules should post data under `extensions[{module_code}]`.
- Extension modules should persist data by observing `Weline_Websites::website_save_after`.
- Website templates must not call SEO/GEO/Location services directly.

## Built-in: 站点联系信息

`Weline_Websites` 自身通过本 hook 嵌入 SystemConfig 分组 `website_contact`（地址/电话/服务时间）：

- 已保存网站：经 `ConfigEmbedRenderer` 渲染 `website_contact`（`scope_kind=website`），写目标锁到当前网站 `storage_scope`。
- 新建未保存：仅提示，不渲染可写控件。
- 实现：`Service/SiteContactScopeMapper.php`、`view/templates/Admin/partials/site-contact-embed-section.phtml`（hook include 路径须 PHP 渲染，勿裸写 Taglib）。

## Built-in consumer: I18n language requests

The I18n language-request panel is injected through this hook. It loads
`website_language_requests.listReady(website_id)` only after the website form is open, carries the object
authorization `grant_version`, and calls `assign` for one or many locales. The write path re-checks backend
login, `Weline_Websites::website_edit`, typed Website Scope, and treats `website_id=0` as valid.

Assignment is one transaction:

1. Re-read I18n `ready` entries and reject stale/unready locales.
2. Call `WebsiteLanguageAssignmentInterface::ensureAssigned()`.
3. Mark matching I18n request items `assigned`.

It never deletes existing website languages and never changes the default language.
