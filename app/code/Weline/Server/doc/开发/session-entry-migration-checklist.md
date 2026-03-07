# Session Entry Migration Checklist

## Goal

Unify Session access entry points to `SessionFactory` in WLS lifecycle adaptation work.

## Completed

- [x] `Weline/Backend/view/blocks/header/base.phtml`
  - Replace `Env::getInstance(BackendSession::class)` with `SessionFactory::backend()`
- [x] `Weline/Frontend/view/blocks/header/base.phtml`
  - Replace `Env::getInstance(BackendSession::class)` with `SessionFactory::frontend()`

## Remaining

- [ ] Localized template mirrors still reference legacy `BackendSession` in `view/tpl/zh_Hans_CN`
  - `Weline/Backend/view/tpl/zh_Hans_CN/blocks/header/com_base.phtml`
  - `Weline/Frontend/view/tpl/zh_Hans_CN/blocks/header/com_base.phtml`
- [ ] Legacy text mentions in docs/comments (`SessionManager`, `BackendSession`) are non-runtime and can be cleaned in doc sync task.

## Verification Notes

- PHP syntax checks pass for updated templates.
- `php bin/w server:status default` remains healthy after migration changes.
