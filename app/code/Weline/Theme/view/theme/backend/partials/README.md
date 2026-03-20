# Backend Partials

后台片段按 `partials/<type>/<option>.phtml` 组织，由 `Weline\Theme\Block\Partials`
在运行时根据主题配置、预览 scope 和父主题回退规则解析。

## 当前 partial 类型

- `breadcrumb`
  - `default`

- `footer`
  - `default`

- `head`
  - `default`

- `header`
  - `default`

- `loading`
  - `default`

- `right-sidebar`
  - `default`

- `scripts`
  - `default`

- `sidebar`
  - `default`
  - `left`

- `topbar`
  - `default`

- `topnav`
  - `default`

## 推荐用法

```html
<w:block
    class="Weline\Theme\Block\Partials"
    area="backend"
    type="sidebar"
    default-option="default"/>
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

## 规范提醒

- `head/default.phtml` 是后台主题资源入口，负责加载后台 theme CSS/JS 和生成的布局 CSS。
- `scripts/default.phtml`、`loading/default.phtml`、`topbar/default.phtml` 这类 partial
  都属于可配置片段，不要把它们误写成布局。
- 新增 partial 选项时，保持目录名就是 partial type，文件名就是 option。

