# Theme 静态资源压缩（生产）

与 `theme:css` / `theme:js` / `theme:font` 同属 Theme 资源管线。

## 行为

- **DEV**：`deploy:upgrade` 原样拷贝；不压缩。
- **生产（`!DEV`）**：`deploy:upgrade` 写 `pub/static` 前派发中立事件 `Weline_Framework_Deploy::static_asset_transform`；Theme Observer 对 css/js/mjs（非 `*.min.*`）就地 minify。
- **系统更新**：`setup:upgrade` → `upgrade_after` 在生产自动跑一次 `deploy:upgrade`（与字体预热同批）。

源码目录 `view/statics` / `view/theme` **不改写**；标签 URL / 文件名不变。

## 算法位置

| 组件 | 路径 |
|------|------|
| JsMin | `Theme/Minify/Js/JsMin.php`（改编 jsmin-php，MIT） |
| CssMin | `Theme/Minify/Css/CssMin.php` |
| 门面 | `Theme/Minify/StaticAssetMinifier.php` |
| Observer | `Theme/Observer/StaticAssetTransformMinify.php` |

详见 `Theme/Minify/NOTICE.md`。

## DEV 预算告警（2026-10 架构优化）

`StaticAssetMinifier::shouldMinify()` 进一步排除 `*.map` / `*.d.ts`（非运行时负载）。
DEV 环境不压缩，但 `StaticAssetTransformMinify` 会对 > `DEV_ASSET_WARN_BYTES`（102,400 字节）
的 css/js/mjs 打 warning：体积问题必须在开发期暴露，而不是等上线后看 PageSpeed。

head 侧的硬门禁不在本文件：见 `theme-spec-gates.md` S2（head 只允许 tokens/后台阻塞）。
