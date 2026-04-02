# PageBuilder Observer

## VirtualThemeRequestInterceptor

PageBuilder AI 建站虚拟主题请求拦截器。

### Responsibilities

1. 监听 `Weline_Framework_Router::route_before`
2. 优先读取显式 `virtual_theme_id`
3. 仅在 `pagebuilder`、`ai-site-agent`、`site-builder-agent` 相关路由下复用已持久化上下文
4. 将上下文写入 `pagebuilder_virtual_theme_context`
5. 向当前请求注入 `virtual_theme_id`、`pagebuilder_virtual_theme_id`、`virtual_theme_path`、`theme_component_area`

### Guardrails

- 不注入 `preview_theme`
- 不注入 `frontend_theme_id`
- 不把 PageBuilder 虚拟主题 ID 伪装成 `Weline/Theme` 主题 ID

### Notes

- `VirtualThemeContextService` 负责上下文归一化、持久化和清理
- Request 注入只服务于 PageBuilder 自己的预览、编辑和组件渲染链路
- 非 PageBuilder 路由会清理已存虚拟主题上下文，避免串用

### Tests

```bash
php vendor/bin/phpunit app/code/GuoLaiRen/PageBuilder/test/Unit/Observer/VirtualThemeRequestInterceptorTest.php
```
