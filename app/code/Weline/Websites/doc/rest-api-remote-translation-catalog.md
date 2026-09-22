# 远程协助翻译 REST（Websites 选站/语种）

基路径：`/{api_admin}/websites/rest/v1/RemoteTranslationCatalog/{action}`

鉴权：Admin 后台 Token / Session。示例 Token 占位：`YOUR_ADMIN_TOKEN`（禁止写入生产密钥）。

本机路由别名示例：`websites/rest/v1/remote-translation-catalog/websites`、`…/get-websites`（以 `generated/routers/backend_rest_api.php` 为准）。

## ACL

| source_id | 方法 |
|-----------|------|
| `Weline_Websites::rest_v1_remote_translation_catalog` | 类 |
| `Weline_Websites::rest_v1_remote_translation_catalog_websites` | `getWebsites` |
| `Weline_Websites::rest_v1_remote_translation_catalog_languages` | `getLanguages` |

## 接口

### GET `…/getWebsites`

返回：

```json
{ "items": [ { "website_id": 0, "code": "default", "name": "…", "default_language": "zh_Hans_CN", "status": 1 } ] }
```

实现：薄壳调用 `w_query('websites','getWebsiteList')`。

### GET `…/getLanguages?website_id=0`

返回：

```json
{ "website_id": 0, "locales": [ { "code": "zh_Hans_CN", "name": "简体中文", "is_default": true } ] }
```

- 未知 `website_id` → 404
- 站未配语种 → `locales: []`（不回退全球目录）

## 冒烟（本机）

```bash
API_ADMIN=$(php -r 'echo (require "app/etc/env.php")["router"]["area_routes"]["rest_backend"]["prefix"]??"";' )
curl -sS -H "Cookie: …" "https://127.0.0.1:9555/${API_ADMIN}/websites/rest/v1/remote-translation-catalog/websites"
```

生产调用须明示并先备份；本接口只读。
