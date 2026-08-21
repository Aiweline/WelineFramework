---
name: framework-taglib-catalog
description: Select the official Weline Taglib for template controls, selectors, layout protocols, DataTable, permissions, SEO, or visitor features, and maintain the catalog when adding a Taglib. Use before replacing a framework control with raw HTML or template-side data access.
---

# Framework Taglib catalog

## Rules

- Check `tag-catalog.md` before implementing a template control.
- Use the Taglib owned by the data domain; do not query another module's Model or Service from a template.
- Use `<w:form>` in compiled `.phtml` templates. Dynamic attributes use `@var(...)`; do not embed PHP inside Taglib attributes.
- **Never embed PHP inside any HTML tag** (not only `<w:*>`). Precompute attribute values and class strings in a top `<?php ... ?>` block; HTML may echo prepared scalars only. See `dev/ai/global-constraints.md` §4 and `.cursor/rules/weline-phtml-templates.mdc`.
- Keep user-visible copy translatable with `<lang>`, `@lang()`, or the appropriate language Taglib.
- Never edit generated Taglib metadata. Run `php bin/w taglib:collect [Module_Name]`.
- A new Taglib is incomplete until its catalog entry and relevant owning documentation are updated.

## Common mapping

| Need | Use |
|---|---|
| Website, store, channel, domain, registrar | `w:websites:*` selectors; `website_id=0` remains the valid default website |
| Language selection or switching | `w:i18n:language:select` / `w:i18n:switcher`（旧名 `w:i18n:language:switcher` 为别名） |
| ACL visibility or ACL tag selection | `w:acl` / `w:acl:tag:select` |
| Module, AI model, CDN, or SEO account | The owning module's `*:select` Taglib |
| File or rich-text editing | `w:file-manager` / `w:editor-manager` |
| Theme controls | `w:theme:*` |
| Semantic UI icon or icon selection | `w:icon` / `w:theme:icon-picker` |
| Data table or declarative form | `w:d-table`, `w:field`, `w:d-form` |
| Published standalone inquiry form | `w:inquiry` (owned by `Weline_Inquiry`; no PageBuilder dependency) |
| Layout, widget, hook, block, or partial | Existing layout protocol: `w:slot`, `w:widget`, `w:hook`, `w:block`, `w:template` |
| SEO/meta or visitor pixel | `w:seo:*`, `w:meta*`, or `w:pixel` |

The complete tag names, classes, and attributes are in `tag-catalog.md`.

## Decision

1. Use layout, partial, component, or Widget for page structure; Taglib is not a replacement for those layers.
2. Reuse the mapped official Taglib when one exists.
3. For a genuinely new reusable template protocol, add the Taglib under the module that owns the data or behavior.
4. Follow `tag-development.md`, collect metadata, update `tag-catalog.md`, and validate the changed template surface.

## References

- `dev/ai/skills/framework-taglib-catalog/tag-catalog.md`
- `dev/ai/skills/framework-taglib-catalog/tag-development.md`
- `app/code/Weline/Framework/View/doc/Taglib/使用指南.md`
- `app/code/Weline/Taglib/doc/README.md`
