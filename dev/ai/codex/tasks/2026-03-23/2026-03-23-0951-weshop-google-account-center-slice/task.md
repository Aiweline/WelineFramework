# Task: weshop-google-account-center-slice

- Task ID: 2026-03-23-0951-weshop-google-account-center-slice
- Started: 2026-03-23 09:51
- Status: done
- Owner: Codex
- Source: user request

## Goal

- Add storefront Google bind/unbind inside the customer account center.
- Make the account center render correctly in the `default` theme.
- Add focused controller-level tests around the new Google and 2FA web entry points.

## Scope

- In scope:
  - `WeShop_GoogleAuth` storefront binding flow
  - `WeShop_Customer` storefront security hook contract
  - `default` theme customer account page and layout compatibility aliases
  - focused PHPUnit controller coverage for Google auth storefront/backend web controllers
- Out of scope:
  - unified auth REST endpoints
  - 2FA self-service enablement UI
  - broader ecommerce module completion

## Constraints

- Do not modify `WeShop_Theme` or `Weline_Theme` module internals.
- Do not revert unrelated dirty worktree changes.
- Keep storefront integration hook/slot friendly.

## Related Files

- `app/code/WeShop/Customer/Controller/Frontend/Account/Index.php`
- `app/code/WeShop/Customer/hook.php`
- `app/code/WeShop/GoogleAuth/Controller/Frontend/Auth/Binding.php`
- `app/code/WeShop/GoogleAuth/view/hooks/WeShop_Customer/frontend/account/security/cards.phtml`
- `app/design/WeShop/default/frontend/pages/customer/index.phtml`

## Resume

- This slice is complete. Resume from [`result.md`](./result.md) if a later task needs the verification details or changed-file list.
