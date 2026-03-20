# Backend Layouts

后台布局按 `layouts/<layoutType>/<option>.phtml` 组织，由控制器的 `layoutType`、
主题配置和预览 scope 共同决定最终文件。

## 命名与解析规则

- 路径规则
  - `theme/backend/layouts/{layoutType}/{option}.phtml`

- 显式指定 option
  - 控制器若传 `layoutType = default.blank`，会解析到
    `layouts/default/blank.phtml`

- 配置驱动 option
  - 控制器只传 `layoutType = default` 时，option 可由主题配置决定，
    未配置时回退 `default`

- 模板元数据
  - 布局文件顶部应包含 `@meta.name`、`@meta.description`、`@param.*`
  - 运行时会注入 `meta`、`theme`、`colors`、`contentTemplate`

## 当前布局清单

- `dashboard`
  - `default`

- `default`
  - `1280`
  - `1440`
  - `blank`
  - `default`

- `fullscreen`
  - `default`

- `login`
  - `default`

- `minimal`
  - `default`

- `print`
  - `default`

## 维护建议

- `default` 目录下的多个 option 是同一类布局的不同宽度/壳层变体，不要拆成新的 layoutType。
- 后台全局初始化放在 `partials/head/default.phtml`，布局模板只负责页面结构和插槽。
- 新增布局时同步补齐 `@param.*`，否则后台配置和预览系统拿不到完整参数。
