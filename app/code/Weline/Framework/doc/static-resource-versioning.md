# 静态资源版本约定

`@static(...)`、`<css>` 与 `<js>` 继续是模块静态资源的唯一模板入口。

## Taglib callback 例外（常见 404 坑）

`@static(...)` **只在 `.phtml` 编译期 AST 中解析**。Taglib `callback()` / `runtime_callback()` 返回的 HTML 字符串**不会**二次解析其中的 `@static`；裸写会导致浏览器请求 `.../@static(Module::css/foo.css)` 并 404。

- **禁止**：callback 返回 `'<link href="@static(Weline_X::css/a.css)">'`
- **必须**：`Template::fetchTagSource(DataInterface::dir_type_STATICS, 'Weline_X::css/a.css')`（范例：`Weline\I18n\Taglib\Local::resolveModuleStaticUrl`）
- **权威**：`app/code/Weline/Taglib/doc/如何自定义Tag.md` §静态资源

- 开发环境在未配置 `theme_static_version` / `theme.static_version` 时，框架按资源文件内容生成稳定的 `dev_*` 指纹并附加到 URL。
- 文件内容变化后 URL 同步变化，普通页面刷新即可取得新的 CSS/JS；内容不变时 URL 保持稳定，不制造随机请求。
- 生产环境不在请求期计算文件哈希，继续使用顶层 `theme_static_version`（权威）或兼容的 `theme.static_version` / 资源编译清单。注意：`theme` 会被主题元数据整表刷新，静态版本必须写顶层键。
- 模块不得自行拼接时间戳、随机数或硬编码版本参数，也不得改写为内联脚本来规避缓存。
