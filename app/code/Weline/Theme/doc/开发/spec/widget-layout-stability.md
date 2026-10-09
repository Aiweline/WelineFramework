# 部件布局稳定（Theme 骨架硬规）

> 硬规则 id：`widget_layout_stability_theme_primitives`  
> 互补：`image_explicit_width_height_css`（HTML width/height）  
> 资源通道：[部件静态资源固化规范.md](../../部件静态资源固化规范.md) §4 / §8.1（全局 theme-head 一份；禁止 per-layout 抽骨架）

## 一句话

可变内容到达前，外框几何已由 Theme 原语锁定；**类名全局一份**，色盘/度量随 scope·主题·版本 Token。

## 原语（foundation → theme-head）

| 类 | 变体 | MUST / SHOULD |
|---|---|---|
| `.w-frame` | `data-ratio="1\|4/3\|16/9\|21/9"`；`data-fit="cover\|contain"` | 业务大图 MUST |
| `.w-skeleton` | `data-size="card"` | 异步 hydrate/lazy-shell MUST |
| `.w-lines` | `data-lines="2\|3"` | 商品卡标题 MUST；其它 SHOULD |

Token：`--weline-skeleton-card-min-h`、`--weline-lines-lh`（variables 盘可覆盖；禁止 foundation 写品牌 hex）。

## file:image

渲染器在已知 layout 时自动外包 `.w-frame`。调用方已自备框时用 `frame="false"`。

## 门禁

```bash
php bin/w frontend:check-widget-layout-stability
```

扫描根：`app/code` **与** `app/design`（主题覆盖部件；`doc/` / 资料快照豁免）。  
DEV：`WidgetHtmlHealthInspector` → `missing_layout_frame` / `missing_layout_skeleton`。

## chrome / 图标豁免

站标（`logo-image`）、Mega 侧栏/卡片小图、分类子项图标等：父级已定死几何或属 UI chrome 时，标 `data-layout-exempt="1"`（禁止拿大图业务媒体滥标）。

## 禁止

- 按每个布局实体复制 foundation / 骨架 CSS  
- content 货架乱标 `layout-source` 挂骨架  
- com 残留已合进 theme-head 的旧碎片 `<link>`  
- Product 卡再立法平行 pad-lock / 私有 skeleton 色值  

## 全局 chrome

头尾结构统一见 `SharedChromeService`（已支持）。本规只管布局几何 CSS 机制。
