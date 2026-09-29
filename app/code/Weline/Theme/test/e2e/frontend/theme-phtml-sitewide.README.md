# PHTML 全站实际页面验收

使用既有正式 runner，无应用写入 fixture、无独立 Chrome 启动器。运行输入必须是当前有效站点，不能复用已清理的 Theme PHTML 编辑测试 owner。

```bash
PHTML_SITEWIDE_MANIFEST=/absolute/current-pages.json \
PHTML_SITEWIDE_EVIDENCE_DIR=/absolute/evidence-directory \
PLAYWRIGHT_DISABLE_PROXY=1 \
PLAYWRIGHT_TARGET_ORIGIN=https://p05113ef3.test.weline.com:9555 \
php bin/w e2e:run app/code/Weline/Theme/test/e2e/frontend/theme-phtml-sitewide.spec.js --project=chromium --workers=1
```

manifest 包含 `pages[]`，每页使用 `id`、真实绝对 `url`、`identity`、`layout_type`、`layout_option`、`mode`。`viewports` 默认 1440×900 和 375×812。按精确用例选择可使用 `--case-id=PHTML-SITE-<id>-<width>`。未设置 manifest 时明确 skipped；显式传入缺失或无效文件会在测试中失败，不在其它 e2e 导入阶段抛错。

`source_expectations_file` 指向独立源码席冻结的 JSON。使用 theme/layout/option、完整 `published_identity`（scope/store_mode/area/V/mode/R）、精确 target 和 `locale_applicability.locales` 找到唯一 `pages[]` 项并保存原文件 hash。请求 scope 与发布 owner 分别记录；不把继承发布 owner 自动替换成请求 scope，不将 global 合同自动用于具体实体。缺少适用依据保留 `not_evaluated`。`mode=observe` 仅采集 Browser 基线，`product_result=not_evaluated`；runner 报采集成功不代表部件/布局验收通过。

实体/条件切片可提供 `request_cases[].url`；此时额外要求与manifest原始意图URL规范化后完整匹配，并保留命中的case与实体依据。不能用最终DOM/重定向结果反推适用分支，也不能把裸 `/product` 缺身份分支用于真实商品slug。

逐实体正文由独立合同 `request_case_assertions[]` 验证：`match` 或源中明确的 `selector`、精确 `count`，再以 `attribute + expected_from` 比较case字段，或 `text_from` 比较其独立标题/分类名称。只归一空白，不能用substring或当前DOM生成预期。实际商品ID、博客分类ID、文章标题和末级商品分类面包屑都有真实节点index/值证据；缺少独立字段保持未判定。

`mode=verify` 消费源页的 `browser_assertions`：

- `widgets[]` / `slots[]`：`id`、`match`、精确 `count`、可选 `visible`、`parent_widget` / `parent_slot`，以及独立条件依据 `applicability={state:applies|absent,evidence:...}`。匹配字段为 module/type/code/uid/slot、`class_token` 或精确 DOM 属性名。`node_kind=element` 读取模板自身 root marker，不能把内层 marker 再计为实例。
- `slot_host` 根据 `SlotBoundaryMarkers::stripReactiveSlotAttributes` 的实际协议接受 `data-wslot` 或 `.theme-published-slot[data-slot-id]`，同时排除 `.widget-wrapper`；父槽和同父顺序也按这个协议解析，不能把带 slot id 的部件外壳当槽宿主。
- `order[]`：`before` / `after` 引用上面的断言 id，`same_parent=true` 另外要求相同父实例和槽。
- 源页的 `request_branch={kind:redirect,initial_status,expected_destination_path_suffix}` 核对真实主文档跳转状态/Location 与最终路径；例如缺少 challenge token 的入口只验登录跳转，不宣称挑战表单已验收。
- `unresolved_conditions` 非空、未冻结断言或未确定 applicability 均保持未判定，不能计作通过。源码 PHP/Hook/业务空态必须独立解释，不从当前 DOM 反推预期。

部件计数取真实 `.widget-wrapper` 元素，保留 UID 与 DOM index，不按 code/UID 去重。内部 `data-widget-code` / `data-widget-name` 标记单列并关联 `owning_wrapper`，避免误算外层/内层两次。相同 code 的合法多个实例及同名嵌套槽均保留父链。

每页保留 DOM 拓扑、槽/实例/标记、区域尺寸/可见性、图片实际加载属性、网络失败与 pending、console/pageerror、首屏及全页截图。原始完整 DOM HTML 仅写入0600私有文件；公开 JSON 脱敏。导航前禁浏览器缓存、清除自动化标志，保持实际 H2/脚本/业务请求，不等待第三方 networkidle。页面滚动只触发真实 lazy 逻辑。

布局/交互合同、必要资源、嵌入媒体附带请求分开记录，完整网络失败仍保留。仅实际来自YouTube embed子frame的精确 `https://static.doubleclick.net/instream/ad_status.js` 按已观察的附带广告探针独立报告；首方CSS/JS/API、播放器脚本/媒体及其它影响功能的第三方失败仍算必要资源失败。未能证明frame归属时不自动豁免；不会禁请求、改脚本或改写旧失败证据。

普通页面不请求全量 trace。`capture_source=true` 会读取主 HTML request ID 对应的已有 WLS timing，但仅 `X-Weline-Trace` header 在当前环境不保证开启 trace；没有数据即明确不可用。需要执行来源时另设独立诊断 URL（`wls_tpl_perf=1` 会插入页面调试条），其截图不能作正常视觉通过证据。实际执行 com 的 `weline-source` 元数据用于核来源/owner/V/R，截断状态原样保留；不以通用 com 存在证明派生布局，不把截断 trace 当完整 SQL 证明。

页面可指定 `request_headers`，例如现有 `X-Wls-Fpc-Bypass: 1` 明确请求动态执行；每个主文档的实际发出头、HTTP 状态、Location 和跳转链都会脱敏保存。`server_cache_mode=normal` 保持原 URL；未指定时历史兼容行为只追加 `no_cache=1`，证据标为 `no_cache_query_only`，不能把该 query 或首次访问称为 FPC/编译冷态。浏览器缓存禁用不等于服务端缓存清空。导航未完成时也保存已收到的 document response 和失败阶段；pending timing 为默认值不等于请求未发送。

分批使用 `PHTML_SITEWIDE_OFFSET` / `PHTML_SITEWIDE_LIMIT`。`evidence_profile=full` 保留双尺寸截图和完整拓扑；数据实体全量模式可用 `compact` 每 URL 实际运行首个 viewport，仍保留部件/槽/来源 root 及父链、图片和网络证据，失败自动保留完整 DOM 与截图。每次尝试保留时间戳文件并更新该 URL/尺寸的 latest JSON。`PHTML_SITEWIDE_RESUME=1` 只跳过已实际 PASS 且 URL、完整身份、源合同 hash、viewport 均未变的条目；观察模式和未判定项不进入已验收集合。

每页 `costs_ms` 保存导航、真实滚动、截图、DOM采集、独立源比较和资源核对耗时；原始开始/结束与总墙钟同时保留。compact省略重复成功截图与全span请求，不能省略部件关系、真实正文或必要资源检查。恢复还要求runner及比较器hash不变。

对目录中已核实但未写系统 hosts 的测试域名，可在本 spec manifest 写 `local_host_resolver=[{host:"exact-name.test",address:"127.0.0.1"}]`，通过 Playwright `test.use` 追加精确 Chrome host-resolver 规则，保留正式 runner executable 和原 flags。禁止通配域名、外访回退或改系统 hosts。响应必须证明 document remote address 为 loopback，且服务器输出的 configScope 对应请求 Website。页面可另指定 `forbidden_request_paths`，用于已实证的资源回归，不改变网络或隐藏错误。

当前已授权的非空购物车专项也在同一spec：`flow=cart_checkout`，输入真实 `product_url/product_id/cart_url/checkout_url` 及独立Cart/Checkout源合同。每站一个新的匿名context，先核空车，正常PDP数量1/加入购物车→正常cart链接→checkout链接，只读表单后通过正常remove清空。每阶段复用原DOM/源断言，保留hydrate后的拓扑与截图；不造商品、注入API结果、填写或提交订单。该流程须在项目经理确认对应PHP已加载后运行。

Cart空态保留hidden/inert的旧ready子树，移除验收核空态及可见行0，再正常刷新回读empty/0行。定向诊断可用 `capture_document_responses` 保存服务端原response bytes及完整头到0600文件；`source_diagnostic_cart` 只在同session另读一次现有perf URL，`diagnostic_cart_only` 则不重复checkout。普通页面的公共header/footer源断言不被诊断或业务操作成功替代，调试文档不计正常视觉通过。
