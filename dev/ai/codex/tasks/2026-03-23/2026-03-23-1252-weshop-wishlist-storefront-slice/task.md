# Task: weshop wishlist storefront slice

- Task ID: 2026-03-23-1252-weshop-wishlist-storefront-slice
- Started: 2026-03-23 12:52
- Status: in_progress
- Owner: Codex
- Source: user request

## Goal

- Complete the WeShop wishlist storefront slice so the `default` theme has a production-usable wishlist page and working short routes for `/wishlist`, `/wishlist/add`, and `/wishlist/remove`.

## Scope

- In scope:
  - Refactor wishlist storefront controllers onto `CustomerContextInterface` + service layer.
  - Add a default-theme wishlist page with saved items, empty state, and recommendations.
  - Make guest add/remove flows return usable login redirect payloads.
  - Register short storefront wishlist routes without exposing `frontend/...` in public URLs.
  - Fix wishlist deletion persistence and close the slice with targeted unit/runtime verification.
- Out of scope:
  - Logged-in end-to-end browser coverage for wishlist management.
  - Broader customer/login route normalization outside the wishlist slice.

## Constraints

- Do not modify `WeShop_Theme` or `Weline_Theme`.
- Keep `default` theme compatibility through WeShop-side templates and routes only.
- Use the task workspace instead of mutable `ACTIVE.md`.
- Runtime verification must target port `9982`.

## Related Plans

- `dev/ai/codex/WeShop国际电商/roadmap.md`
- `dev/ai/codex/WeShop国际电商/acceptance-matrix.md`
- `dev/ai/codex/WeShop国际电商/test-matrix.md`

## Related Files

- `app/code/WeShop/Wishlist/Controller/Frontend/Wishlist/Add.php`
- `app/code/WeShop/Wishlist/Controller/Frontend/Wishlist/Index.php`
- `app/code/WeShop/Wishlist/Controller/Frontend/Wishlist/Remove.php`
- `app/code/WeShop/Wishlist/Controller/Add.php`
- `app/code/WeShop/Wishlist/Controller/Index.php`
- `app/code/WeShop/Wishlist/Controller/Remove.php`
- `app/code/WeShop/Wishlist/Service/WishlistPageDataService.php`
- `app/code/WeShop/Wishlist/Service/WishlistService.php`
- `app/code/WeShop/Wishlist/etc/env.php`
- `app/design/WeShop/default/frontend/pages/wishlist/index.phtml`
- `app/code/WeShop/Customer/Api/CustomerContextInterfaceFactory.php`
- `app/code/WeShop/Wishlist/Test/Unit/Controller/Frontend/Wishlist/AddTest.php`
- `app/code/WeShop/Wishlist/Test/Unit/Controller/Frontend/Wishlist/IndexTest.php`
- `app/code/WeShop/Wishlist/Test/Unit/Controller/Frontend/Wishlist/RemoveTest.php`
- `app/code/WeShop/Wishlist/Test/Unit/Service/WishlistPageDataServiceTest.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
