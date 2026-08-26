# 店面商品图占位标准

## 硬规则

**禁止**把媒体图写成 `data:image/svg+xml;charset=…`（或任何 `data:image/*`）直出到 HTML 的 `img src` / `data-fallback`。  
无图或不可用图时，只用**一份**本地静态 SVG 的普通 `src`；浏览器缓存一次即可，远好于在 HTML 里塞几百份相同字节。

## 规则

1. 商品/媒体图缺失、空字符串、任何 `data:image/*`、或已知失效远程 URL → `Weline\Theme\Helper\StorefrontImagePlaceholder::resolve()`。
2. 唯一占位资源：`Weline_Theme::images/storefront-placeholder/default.svg`。`url()` / `ThemeDemoCatalog::productImage()` 只返回该路径（`seed` 参数仅兼容旧调用，不影响路径）。
3. 模板输出：
   - `src` = resolve 的 `src`（真实媒体 URL 或上述静态 SVG）
   - `data-storefront-img="1"`
   - `data-fallback` = 同一静态 SVG URL（禁止 data URI）
   - 装饰性缩略图可用 `alt=""`，链接用 `aria-label`
4. 运行时：`Weline_Theme::js/storefront-image-fallback.js`；破图或误留的 `data:image` 一律改成静态 `src`。可注入 `Weline.Theme.storefrontPlaceholderSrc`。
5. JS：`Weline.Theme.storefrontImagePlaceholder()` 返回同一静态 URL。

## 禁止

- `data:image/svg+xml;charset=…` / `data:image/svg+xml,…` 作为媒体直出
- 用字母「W」「FCDC」等文本当商品图占位
- 各部件私写互不一致的内联 SVG 占位
- 为「好看」准备多份色板 data URI 或在 HTML 内复制多份 SVG
