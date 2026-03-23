# API Contracts

Framework URL note:

- Weline frontend REST URLs follow `/{rest_frontend_prefix}/{module_router}/rest/v1/...`
- In the current local environment, `rest_frontend_prefix=api` and `WeShop_Auth.router=weshop`
- That means the full default auth URLs are `/api/weshop/rest/v1/auth/*`
- `generated/routers/frontend_rest_api.php` stores route keys without the area prefix, for example `weshop/rest/v1/auth/token`

Unified auth endpoints:

- `POST /api/weshop/rest/v1/auth/token`
- `POST /api/weshop/rest/v1/auth/challenge/verify`
- `POST /api/weshop/rest/v1/auth/logout`
- `GET /api/weshop/rest/v1/auth/me`

Unified response:

```json
{
  "code": 200,
  "msg": "ok",
  "data": {
    "status": "authenticated",
    "actor_type": "customer",
    "access_token": "token",
    "refresh_token": "refresh",
    "challenge_token": null,
    "expires_at": 0,
    "actor": {},
    "scopes": []
  }
}
```
