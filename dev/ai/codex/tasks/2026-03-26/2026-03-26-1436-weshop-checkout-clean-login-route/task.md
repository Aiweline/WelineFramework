# Task: WeShop checkout clean login route alignment

- Task ID: 2026-03-26-1436-weshop-checkout-clean-login-route
- Started: 2026-03-26 14:36
- Status: completed
- Owner: Codex
- Source: Commit the currently pending checkout clean-route/login redirect fixes before continuing the next checkout quote preview slice.

## Goal

- Align checkout guest redirects with the canonical storefront login route under `weshop/customer/account/login`.
- Keep checkout guest flow and clean-route tests consistent after the storefront route cleanup.

## Scope

- In scope:
  - `WeShop_Checkout` guest redirect targets for checkout index and success pages
  - focused unit coverage for the redirect behavior
  - checkout i18n entries already required by the same flow
- Out of scope:
  - quote preview refresh
  - theme-module changes
  - unrelated worktree modifications

## Constraints

- Do not modify `WeShop_Theme` or `Weline_Theme`
- Stage only the files owned by this slice
- Keep mutable task state inside this workspace only

## Related Files

- `app/code/WeShop/Checkout/Controller/Frontend/Checkout/Index.php`
- `app/code/WeShop/Checkout/Controller/Frontend/Checkout/Success.php`
- `app/code/WeShop/Checkout/Test/Unit/Controller/Frontend/Checkout/IndexTest.php`
- `app/code/WeShop/Checkout/Test/Unit/Controller/Frontend/Checkout/SuccessTest.php`
- `app/code/WeShop/Checkout/i18n/en_US.csv`
- `app/code/WeShop/Checkout/i18n/zh_Hans_CN.csv`

## Resume

- Validate focused PHPUnit coverage, commit this slice, then continue in `2026-03-26-0627-weshop-checkout-quote-preview-refresh`.
