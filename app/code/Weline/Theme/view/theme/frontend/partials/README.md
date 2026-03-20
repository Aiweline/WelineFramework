# Frontend Partials

前端片段按 `partials/<type>/<option>.phtml` 组织，由 `Weline\Theme\Block\Partials`
按主题配置、预览 scope、父主题回退规则进行解析。

## 当前 partial 类型

- `breadcrumb`
  - `default`

- `footer`
  - `default`
  - `minimal`

- `head`
  - `default`

- `header`
  - `centered`
  - `default`
  - `minimal`

- `pagination`
  - `default`

- `sidebar`
  - `default`

## 推荐用法

优先使用 `w:block` 方式加载，不要在布局里手写 ObjectManager。

```html
<w:block
    class="Weline\Theme\Block\Partials"
    area="frontend"
    type="header"
    default-option="default"
    vars="logo,logoText,navItems"/>
```

## 运行时可用数据

- `meta`
  - 当前 partial 自己的 `@param.*` 参数值
- `layout`
  - 当前布局的 `meta`
- `theme`
  - 当前 area、colorMode、layoutType、layoutOption、theme 对象
- `colors`
  - 当前主题颜色键值
- `contentTemplate`
  - 原始内容模板路径（部分场景可用）

## 规范提醒

- 每个 partial 类型至少保留一个 `default.phtml` 作为回退实现。
- `head/default.phtml` 是主题资源入口，负责加载 theme CSS/JS 和生成的布局 CSS。
- 新增 partial 选项时，只需在对应目录新增同名 `.phtml` 文件并补齐 `@meta.*` / `@param.*`。

