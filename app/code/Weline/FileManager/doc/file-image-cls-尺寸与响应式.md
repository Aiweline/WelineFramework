# file:image CLS 尺寸与响应式（Google 写法）

## 结论

`<w:file:image>` **必须**在使用处给出 UI 占位宽高（或宽高比），输出 HTML `width`/`height` 防 CLS；显示尺寸由主题 CSS `max-width:100%; height:auto`（含 `.w-file-image`）做响应式。二者缺一不可。

## 用法

```html
<!-- 推荐：按设计稿比例写占位整数 -->
<w:file:image usage="heroUsage" width="16" height="9" class="hero-media" />

<!-- 或宽高比 -->
<w:file:image asset="…" alt="…" aspect_ratio="4/3" />
```

ImageUsage JSON 可持久化 `layout_width` / `layout_height`（或 `aspect_ratio` 在写入前解析）。标签上的 `width`/`height`/`aspect_ratio` 优先于 usage 内布局尺寸；若都未设，则回退 FileAsset 元数据宽高。

渲染结果始终带 class `w-file-image`。

## 优先级

1. 标签 / 调用显式 `width`+`height` 或 `aspect_ratio`
2. ImageUsage `layout_width`+`layout_height`
3. FileAsset 入库宽高

## CSS

Theme `foundation.css`：

- `img, … { max-width: 100%; height: auto; }`
- `.w-file-image { max-width: 100%; height: auto; }`

封面/`object-fit: cover` 可用更具体选择器覆盖 `height`，但仍须保留 HTML 宽高以锁定比例。

## Agent / MCP

硬规则 id：`image_explicit_width_height_css`（`HardConstraintsCatalog` + `AI硬规则索引`）。凡图片相关改动须设宽高再配响应式 CSS。
