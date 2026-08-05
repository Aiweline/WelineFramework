# Website form sections-after hook

Hook: `Weline_Websites::backend::website::form::sections-after`

Purpose: allow independent modules to append website-scoped configuration sections to the admin website form.

Guidelines:

- `Weline_Websites` owns only core website fields.
- Extension modules should post data under `extensions[{module_code}]`.
- Extension modules should persist data by observing `Weline_Websites::website_save_after`.
- Website templates must not call SEO/GEO/Location services directly.

The I18n language-request panel is injected through this hook. It loads
`website_language_requests.listReady(website_id)` only after the website form is open, carries the object
authorization `grant_version`, and calls `assign` for one or many locales. The write path re-checks backend
login, `Weline_Websites::website_edit`, typed Website Scope, and treats `website_id=0` as valid.

Assignment is one transaction:

1. Re-read I18n `ready` entries and reject stale/unready locales.
2. Call `WebsiteLanguageAssignmentInterface::ensureAssigned()`.
3. Mark matching I18n request items `assigned`.

It never deletes existing website languages and never changes the default language.
