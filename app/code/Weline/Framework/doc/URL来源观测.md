# URL 来源观测

`App::applyParsedUrl()` 在已有 `load_context_server`、`server_context_set`、`storefront_scope_install` 三个 profile 阶段增加 `meta.origin`。仅在 `RequestLifecycleTrace::isEnabled()` 时生成快照，关闭跟踪时不读取额外 Env 或解析 URL。

`parsed`、`context`、`global` 分别表示解析结果、观测当时的请求 Context 和当前进程 globals。每组只包含 `http_origin`、`server_port`、`website_origin`；另记录 `route_website_origin`、`env_website_origin`、`env_base_origin` 以区分请求传输地址与 URL 构建读取的有效来源。

所有 URL 裁剪到协议、主机和可选端口，不保存路径、查询串、fragment、userinfo、Cookie 或其他请求字段。快照读取 `Context::getCurrent()`，不创建上下文，不调用 URL 构建器，不查库、不改路由、不写缓存。

比较三个阶段，能区分错误来源在解析输入、server 合并还是范围安装时首次出现；若第一个阶段已经不同，则须继续定位更早的来源。该日志不代表已证明跨请求数据泄漏，也不构成 URL 行为修复。

定向验证：`vendor/bin/phpunit --bootstrap app/bootstrap_phpunit.php --colors=never app/code/Weline/Framework/Test/Unit/App/ParsedUrlOriginTraceTest.php`。使用真实 Context 和私有观测方法，验证连续更新后的来源、快照不变性及敏感成分裁剪。真实实例的编译、加载和对照请求由主任务统一执行。

## 2026-09-13 编译目录来源修复

先前以同一公开 9555 地址连接两个实例时，三个 App 来源快照均为 9555，但两者读取相同的 `frontend_w0_default_default_CNY_ctx_*` 编译目录。主任务在默认头部编译文件发现 111 处硬编码 19655，首页布局编译文件另有 74 处；范围叶子失效与 Worker 重载仍不能更改这些共享文件内容。

`Template::stableTemplateCompileDirectory()` 的维度闭包误用 `w_env_get()`，此函数实际调用 `WelineEnv::getGet()` 读取查询参数；`website.url` 也不是有效的 `website_url` 别名。现将两处读取改为 `w_env()`，使已有面积、网站、语言、货币及网站 URL 维度来自有效 Context。不同 origin 分离编译目录，同一 origin 不因请求 ID、普通路径或同名 GET 参数增加目录。

最终由 `TraitTemplate::TEMPLATE_COMPILE_SCOPE_SCHEMA` 统一声明 `context-env-v3`，同时参与物理编译目录和 template-file-map、tag-source、fetch-file 等路径映射，排除旧产物。仅迁移映射的中间版本 v2 不足以排除旧物理文件，最终实现以 [编译缓存格式迁移](编译缓存格式迁移.md) 为准；不修改编译产物，也不清空全局缓存。已编译旧 HTML 的外层范围缓存由主任务在源码加载后通过既有精确叶子失效处理。

新增真实函数验证：`vendor/bin/phpunit --bootstrap app/bootstrap_phpunit.php --colors=never app/code/Weline/Framework/Test/Unit/View/TemplateCompileOriginScopeTest.php`。用真实 Common/Context/WelineEnv 校验 origin 分离、身份稳定、GET 不替代环境、目录后缀幂等与旧路径格式迁移。仅固定外部主题后缀事件的测试边界。本次未重构部件的编译/运行期执行模型；该边界是否还包含其他请求敏感值，需另以实际证据审查。

## 实际验收结果（2026-09-13）

源码经正常 framework:compile 和两个实例 managed reload 加载；旧输出仅沿现有 theme 精确叶子 81→82 失效，父代次未变。同一 H2/同 worker 的 19655→9555→19655 每页 186 个同站链接全部正确，默认另一 worker 也为 0/0/0 错误；HTML/footer 完整。浏览器禁缓存的首页、英文筛选、第二页及详情页实际通过来源与页脚检查，既有 category-menu 重复 ID 警告仍保留。本批定向 PHPUnit 合计 22 tests/182 assertions 通过。英文 MISS 2.296s/HIT 58–51ms，不能视为冷启动达到 70ms。

完整证据及当前性能边界见 [统一缓存范围与性能优化](统一缓存范围与性能优化.md) 的“有效上下文、共享原始路由与编译格式的实际验收”一节。
