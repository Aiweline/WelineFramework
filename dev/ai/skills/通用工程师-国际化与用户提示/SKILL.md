---
name: 通用工程师-国际化与用户提示
description: Implement or diagnose Weline translation keys/files, interpolation, localized notifications, confirmation flows, or stale runtime phrases. Use when localization or feedback mechanics change or fail; routine copy edits should follow the owning skill and global i18n rules without loading this specialist.
---

# Internationalization and user feedback

## Contracts

- Simplified Chinese is the visible source/key for `__()`, `<lang>`, templates, hooks, and JavaScript. `zh_Hans_CN` maps Chinese-to-Chinese and `en_US` maps the same key to English.
- Controller flash messages use `Weline\Framework\Manager\MessageManager::success()`, `warning()`, or `error()` unless the owning API documents an exception.
- Custom-tag attributes use `@lang` forms; never embed PHP translation calls inside `<w:*>` attributes.
- Replace native `alert`, `confirm`, and `prompt` with framework notification or confirmation UI.
- Use `%{1}` or `%{name}` placeholders. A single placeholder may receive a scalar; multiple/named placeholders use an ordered or associative array.
- Edit the owning source/template/CSV, never generated phrase packs.

## Workflow

1. Identify the owning module, source key, locale files, rendered surface, and feedback behavior.
2. Apply the correct PHP, template, attribute, or JavaScript translation form.
3. Keep translation entries keyed by the same Simplified Chinese source across locales.
4. When runtime output is stale, inspect module registration plus phrase/template/taglib caches before rewriting correct source.
5. Validate the actual language switch, message, toast, or confirmation on the rendered surface.

## Validation

- Confirm the live output uses the expected locale, not merely that CSV/template text changed.
- Confirm placeholders render without PHP syntax or parameter-shape errors.
- Confirm visible prompts use framework UI and remain actionable.
- Report missing locale coverage or a cache/registration blocker explicitly.

