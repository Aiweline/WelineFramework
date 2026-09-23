# evidence — theme editor HTTP 500（运营探活）

日期：2026-09-23T01:40+08:00  
面：`theme_id=3` 主题编辑器草稿 URL（后台前缀）  
方法：`curl` + Chrome DevTools Browser（background）

## HTTP

- `GET /jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/` → **500**
- `GET .../theme/backend/theme-editor?theme_id=3&page_type=homepage&...&status=draft` → **500**

## 错误摘要

```json
{
  "error": true,
  "message": "Request reset boundary wls_request_finalization failed in 1 stage(s): hot_cache_bag_prime=Error(Call to undefined method Weline\\Framework\\Runtime\\WlsRuntime::isHotCacheBagPrimePendingForCurrentFiber())",
  "exception": "Weline\\Framework\\Runtime\\RequestResetException",
  "file": ".../app/code/Weline/Framework/Runtime/WlsRuntime.php",
  "line": 6807
}
```

## 说明

本席未改任何代码。截图为 Browser 视口 JSON 错页（回合内 inline）。  
未使用已发布店面 `/` 作为本主题验收。
