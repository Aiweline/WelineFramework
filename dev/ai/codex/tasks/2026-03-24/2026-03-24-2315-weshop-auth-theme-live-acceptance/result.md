# Result - weshop-auth-theme-live-acceptance

- Status: in_progress

## Summary

- Current checkpoint:
- live auth probes on port `9982` are now confirmed against the real REST prefix `api123`
- compare storefront clean routes are restored and validated for guest-safe behavior
- compare and RMA hook declarations are aligned with the manifest/injection strategy expected by preflight refresh
- remaining highest-priority acceptance gap is the missing frontend Google login provider rendering despite hook registration being present

## Verification

- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Compare/Test/Unit --colors=never`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Compare/Test/Unit/View app/code/WeShop/RMA/Test/Unit/View --colors=never`
- `php tests/e2e/framework/preflight-refresh.php`
- live probes on `http://127.0.0.1:9982`:
  - `GET /customer/account/login`
  - `GET /wishlist`
  - `GET /recently-viewed`
  - `GET /compare`
  - `POST /compare/add`
  - `POST /compare/remove`

## Remaining

- determine why the frontend login route resolves to a different runtime template than `app/design/WeShop/default/frontend/pages/customer/login.phtml`
- restore live frontend Google provider rendering under the default-theme-compatible hook path
- continue the next module wave after checkpoint commit
