# Website form sections-after hook

Hook: `Weline_Websites::backend::website::form::sections-after`

Purpose: allow independent modules to append website-scoped configuration sections to the admin website form.

Guidelines:

- `Weline_Websites` owns only core website fields.
- Extension modules should post data under `extensions[{module_code}]`.
- Extension modules should persist data by observing `Weline_Websites::website_save_after`.
- Website templates must not call SEO/GEO/Location services directly.
