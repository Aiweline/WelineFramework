# API Contracts

待实现的统一认证端点：

- `POST /api/rest/v1/weshop/auth/token`
- `POST /api/rest/v1/weshop/auth/challenge/verify`
- `POST /api/rest/v1/weshop/auth/logout`
- `GET /api/rest/v1/weshop/auth/me`

统一响应：

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
