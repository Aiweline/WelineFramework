# injectinherit 主题

## 探针约定（UC-5）

- **父主题**：`injectprobe`（故意无 float 的 footer）。
- **本层必须有** `frontend/`（否则 `assertFrontendTheme` 拒绑），但 **禁止** `frontend/layouts/**/*.phtml` 覆盖——专门验收「新主题继承父布局后，布局内插槽上的应用部件 `default_injections` 仍生效」。
- 机制：`SolidifiedControllerTemplateResolver::resolvePublishedVersionAlongThemeChain` + `RequiredDefaultInjectionRuntimeSafetyNet`；客服/进店音乐 `layout_type=*`。
- 契约：`InheritedLayoutRequiredInjectionContractTest`。

## 活体验收口径

绑定本主题后看 **`/products`**（继承父 `products` 布局 + floatless footer）：

| 信号 | 须出现 |
|------|--------|
| `injectprobe-footer--floatless` | 证明走的是父布局/壳，不是子主题自写布局 |
| `store-music-widget` / `#weline-store-music` | 进店音乐 required 注入 |
| `cs-chat-button` | 客服 required 注入 |
| `w-storefront-float-layer` / `storefront-float-*` | float 宿主合成兜底 |

首页若父主题无 homepage 布局覆盖，可能仍渲染站柜 CMS 内容；**不以首页判定 UC-5**，以 `/products`（或同类有父布局的页）为准。

## 安装

```bash
php bin/w setup:upgrade
php bin/w theme:listing
```

在网站信息绑定本主题（`websites_theme_application`）。验完请恢复原主题。
