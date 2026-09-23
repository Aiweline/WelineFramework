# 部件资源位置与无内联迁移

## 背景

2026-09-23 用户要求延续 Cursor 中已实现的 layout-source/source 布局固化机制，补齐所有部件迁移、位置选择、开发提示词和文档。当前收集器只有 layout/source 两桶，统一 head 发射；历史合同允许部分内联，尚未满足本次要求。

本次为跨模块资源契约调整；前端负责模板声明和初始化，后端负责注册、持久化、固化与发射。用户已在计划模式审阅完整计划，并明确要求实施；本文持续记录执行证据。保留当前 dev 工作区全部既有脏改，不作清场或整文件旧版回写。

## 方案

复用 Widget 元数据、节点图、ThemeLayoutEntityAssetCollector、布局资源 sidecar 和统一消费链路。布局关键资源提前进 head；普通 source 按 source-postion 发射，兼容正确拼写 source-position；body/end-body 同义，表示结束 body 前，footer 表示结束 footer 前（页面无 footer 时落 body 末尾）。未指定位置保留 head 默认。相同资源只输出一次，layout 优先于 source。声明和实际渲染需要在正式、版本预览及编辑器动态部件保持一致。

所有部件的 CSS/可执行 JS 正文迁往静态文件并声明资源；实例数据通过 data 属性等非可执行数据传递，内联事件迁到外部监听。检查组件调用、模块和主题覆盖模板，不能仅迁移首页出现的部件。不得以运行时重扫全部注册表作为替代迁移方案。

## 细节

- [x] 确认历史合同与当前收集器、head 消费器。
- [x] 部件席列出初始模板/注册范围及每类内联缺口：33 个注册文件、166 个模块部件相关模板（136 前台、30 后台等），83 个 style 块、29 个可执行 script、2 个 JSON 数据 script；主题覆盖与共享组件调用链继续扩展核查。
- [ ] 主题席补齐位置字段从 Taglib/元数据到节点、快照、sidecar、消费器的传播；先写并执行最小失败用例。
- [ ] 部件席逐项迁移存量资源并清除模板内联 CSS/JS，保持实例隔离、翻译和行为。
- [x] 提示词席更新权威资源规范、开发指南、工程席位提示与 MCP 下发规则，删除冲突示例。13 个文档/规则/测试文件，新增 4 条资源下发语义断言通过，5 个 PHP 语法检查通过。完整 MCP 测试仍各有 1 条“必装永远存在”字面断言失败，尚不能声明全套通过。
- [ ] 检查迁移覆盖，执行配置的测试与资源编译；刷新注册并重建相关布局闭包。
- [ ] 实际运行本机页面，验证位置、顺序、去重、无内联、资源响应与部件交互；记录可复核证据后交付。

验收用例：WHEN 节点声明 source-postion=footer，SHALL 资源出现在 footer 末尾且不在 head 重复；WHEN 同一文件先出现在 source 后出现在 layout-source，SHALL 提升到 layout 并仅输出一次；WHEN body 或 end-body，SHALL 输出在 body 末尾；WHEN 多实例或动态预览，SHALL 资源可用且各实例交互正常；WHEN 检查所有部件源模板和实际输出，SHALL 不存在部件内联 CSS/可执行 JS。纯 JSON 数据不是可执行脚本，但应优先使用数据属性。

第一波真实子智能体（只读定位）：部件开发工程师 `/root/widget_inventory`；主题开发工程师 `/root/resource_pipeline`；提示词优化工程师 `/root/prompt_contract`。父会话为项目经理。尚未完成实现或运行验收，禁止声称已完成。

实施波沿用三席；另由 `/root/asset_review` 独立检查迁移的类型保真、多实例、加载时序与观察器开销。source-postion 与 source-position 同时存在时前者优先；相同普通资源多位置声明按 head → footer → body 合并。

运行基线（04:44 UTC）：当前配置实例 default、master PID 69435、HTTPS 29843；实际进程清单无 HTTP Worker，配置报告的旧 worker PID 69485/69486/84862 已不存在。首页请求 TCP 可连接但 TLS ClientHello 后超时。资源迁移未完成时不重启，集成后统一恢复并运行验收；状态命令“All Running”不能当作页面可用证据。

执行进度（2026-09-23 05:20 UTC）：

- 清单扩展至 166 个模块模板、8 个有效主题覆盖、13 个间接片段，共 187 项；精确路径及资源映射见 `../team/widget-assets-position-migration/widget-inventory.json`。迁移已写入工作区，运行验收尚未关闭。
- 注册刷新完成：更新 100 项，ParamSchema 已刷新。最后一轮 `resource:compile welineUi` 退出 0；日志 `/tmp/weline-widget-resource-compile-final.log`。
- 编辑器删除分支、更新预览和弹窗资源加载修复已定向复核，源码和编译镜像一致；现有 Node 用例 2/2 通过。位置 PHP 用例复跑 4 tests / 16 assertions 通过。
- 本机 `https://p05113ef3.test.weline.com:29843/` 已实测 HTTP 200，新部件资源进入真实输出。发现商品卡片间接资源仍在正文重复输出，交主题席修复并以正式 Playwright 流程验证，不能据此声明验收通过。
- 本轮 MCP prepare 返回 MCP_RUNTIME_STALE，按 AGENTS 文档回退读取硬规则；未伪称 MCP 成功。

运行修复与复测（2026-09-23 05:30 UTC）：

- 商品卡片外链去重现在保留专属识别标记，避免后置 HtmlCacheAdmission 再次补入；其内联 CSS fallback 同步改成框架解析的外链。位置回归 5 tests / 19 assertions 通过。
- 真实框架 Taglib 将裸 template 解释为模板引用，导致初始化数据丢失。28 个模板的 31 个初始化节点改为 hidden span，资源描述符也改 hidden span；真实 Taglib 编译回归 1 test / 63 assertions 通过，Node 动态加载 2 tests 通过。
- 重新编译 welineUi 退出 0，模板缓存已刷新，日志 `/tmp/weline-widget-resource-compile-span.log`。
- 当前注册表为 154 项，其中 123 项有资源声明；129 处模板声明与注册表一致。独立核对见 `../team/widget-assets-position-migration/widget-inventory-recheck.json`。
- 其他本机会话将 default 实例端口调整为 9555，已通过启动日志和实际 HTTP 200 核实。后续验收地址为 `https://p05113ef3.test.weline.com:9555/`。
- 正式 Playwright `widget-assets-runtime.spec.js` 前台用例已通过资源位置、layout 优先、唯一性、初始化节点保留及逐资源 HTTP 200。后台预览及实际交互仍在验收，不标记整体完成。
- 页脚实际交互补测发现旧 chrome 片段缺初始化节点。已执行现有 `theme:layout:migrate-bindings`，退出 0，处理 1392 项；另有 1711 条无法映射的历史身份被保留（含 chrome_layout_version_unresolved），不能将命令成功等同全部布局刷新。日志 `/tmp/weline-widget-layout-rebake.log`，由主题席进一步核实当前生效身份及复测。
- 重建后的真实首页包含 6 个初始化节点、无 template 缺路径警告。再定位到选中主题覆盖模板的资源被 `_source` 旧声明覆盖，WidgetAssetRenderer 现合并选中模板依赖与显式附加资源，并使用框架 `convertFetchFileName` 解析实际模板。行为回归 6 tests / 21 assertions 通过。已再执行 cache:clear 与布局重建，均退出 0；日志 `/tmp/weline-widget-final-cache-clear.log`、`/tmp/weline-widget-layout-rebake-final.log`。
- 后台登录、编辑器和部件库已实际加载成功；早期网络/导航失败不能视作最终阻断。正在完成真实部件选择与预览交互。

本轮交付状态：实现已落盘，整体真实验收未通过，目标保持未完成。

- 最新 PHP 回归：6 tests / 21 assertions；Node 动态加载及实际 iframe 更新入口 2/2 通过。此前商品卡片 7/64、购买动作 3/22、资源收集 7/22、31 个锚点真实编译 1/63 均通过。
- 最新首页证据：55 项资源 HTTP 200、无重复/错位、layout 在 source 前、6 个初始化节点且无模板缺路径警告。页脚仍缺 Hanfu 专属脚本，最新 WidgetAssetRenderer 修复尚未在刷新后的常驻 PHP 进程验收，不可声明返回顶部已恢复。
- 统一 `server:reload default` 返回 exit 1：`Control operation cancelled by imperial ssl_serving_quarantine`。日志 `/tmp/weline-widget-runtime-reload.log`。未绕过控制层限制、未强杀进程或清理其他会话数据。
- 后台最新正式用例 exit 1，在登录后导航 `/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/weline_dashboard/backend/dashboard` 出现 `net::ERR_TIMED_OUT`，未到部件预览。日志 `/tmp/weline-widget-editor-search.log`；此前能打开编辑器和库的结果不能代替该最终失败。
- 未完成：刷新运行进程后验证覆盖模板资产及页脚交互；后台预览、新增/更新/删除/配置保存与多实例真实验收；footer/body/end-body 在真实页面的完整位置场景（当前为行为单测证据）。服务恢复后运行 `Theme/test/e2e/frontend/widget-assets-runtime.spec.js` 并补齐这些场景，不重复全库迁移。

## 追加需求：资源压缩与分段合并

用户已确认采用建议：实际渲染顺序计数，包含嵌套部件；CSS 与 JS 独立设置起始序号。随后截图明确入口为现有基础信息抽屉，要求分为基础配置与资源配置。

方案冻结：

- 基础信息抽屉顶部采用已有主题 Tab 控件，默认“基础配置”；网站身份、图标、Logo 等原字段及保存行为保持在基础配置。“资源文件配置”页承载统一 SystemConfig 配置；作用范围提示共用，品牌保存按钮不作用于资源页。
- SystemConfig group=`theme_resource_files`，键为 `resource_files/css_minify`、`js_minify`、`css_merge`、`js_merge`、`css_merge_start`、`js_merge_start`。前四项 auto/on/off 默认 auto：开发关闭，生产开启；显式开关覆盖环境。起始序号为正整数，默认 6。压缩与合并独立，不引入懒加载。
- 配置服务 `ThemeResourceConfig::resolve(?WelineTheme $theme=null,string $area='frontend')` 返回四个实际布尔值和 `css_merge_start_widget`、`js_merge_start_widget`。沿现有范围身份继承，不另造保存接口或私有配置表。
- 每个真实部件开头保留稳定实例标记，最终完整 HTML 按文档前序计数，包含无资源、layout 与嵌套部件；缓存不能固化请求计数。独立预览从 1 重新计数。共享资源按最早使用者分段，前段资源不可因合包后移。
- layout-source 独立提前加载，可压缩但不进入后段合包。普通 source 达阈值后，按 CSS/JS、输出位置和执行语义分别稳定合并。不能改变 JS 依赖顺序；不安全的模块脚本、文件语义边界保留独立。CSS 相对 URL 按原文件基址改写。
- 复用 StaticAssetMinifier、主题静态发布与原子文件机制，产物按有序内容及配置指纹缓存；暖请求不重复压缩，不扫描全注册表。性能席已复现 calc 空白及嵌套 JS 模板字符串损坏，必须先保守修复压缩语义。

新增验收：WHEN auto 在开发/生产运行，SHALL 分别关闭/开启；WHEN 显式选择 on/off，SHALL 覆盖环境默认；WHEN 两类阈值不相同，SHALL 独立分段且前序无资源/嵌套部件计数正确；WHEN 共用资源跨阈值，SHALL 只保留最早独立引用；WHEN 切换抽屉分组及作用范围，SHALL 保持正确字段、保存目标和继承关系；WHEN 加载合并 CSS/JS，SHALL 相对资源、脚本顺序与多实例交互保持正确。

实施分工：配置/UI（widget_inventory）、资源优化管线（resource_pipeline）、压缩正确性（asset_review）；项目经理维护合同、集成与真实验收；翻译/文档后续交专席处理。禁止把上一阶段未完成的真实验收当成已完成。

追加实现进度（2026-09-23）：

- 基础信息双页签及六配置已落盘；前后台按现有 SystemConfig area 各自配置，抽屉绑定当前编辑区域。声明解析/分组测试 3 tests / 41 assertions，读取服务 2 tests / 40 assertions，包含隔离 PHP 进程的开发/生产 auto 与手动开关验证，未修改本机环境配置。
- ThemeResourceConfig、最终部件序号收集、WidgetAssetOptimizer、WidgetAssetArtifactPublisher 已接入正式页面与预览。使用原模块源码，避免生产关闭压缩却仍读取预压缩产物。配置保存沿已有 namespace 发布链失效 FPC，无重复 observer。
- 两个旧压缩器问题已修：CSS 保留数学表达式空白；含反引号的 JS 保守保留原文。压缩测试 9 tests / 34 assertions。CSS URL 重写区分真实 token 与字符串/注释；可能跨文件变量提升的 JS 不拼接。最终管线回归 21 tests / 70 assertions，Node 2/2。
- 已发布真实 JS 产物 HTTP 200、MIME text/javascript、1166 字节，与磁盘逐字节一致；证据 `/tmp/weline-widget-bundle-headers-latest.txt`、`/tmp/weline-widget-bundle-served-latest.js`。这只证明发布与静态服务，不代替配置切换后页面验收。
- 中英 CSV 各补 18 行，覆盖 19 条资源配置源串；规范及部件提示已同步。模块 i18n collect 被现有全模块任务 PID 29032 占用返回 BUSY/75，未删锁，翻译运行刷新未声明完成。
- 两区域 extends 重建及 welineUi 编译均退出 0。日志 `/tmp/widget-resource-config-extends-final.log`、`/tmp/widget-resource-config-compile.log`。
- 本轮常驻进程 reload 已获接受但在 Batch 1/2 重启时失败 Unknown error，退出 1；日志 `/tmp/widget-resource-config-reload.log`。新配置正式只读 E2E 为 `Theme/test/e2e/backend/theme-editor-resource-config.spec.js`，真实运行仍待结果；不得凭静态通过标记整体完成。
- 新计数标记对应布局已再通过既有 migrate-bindings 重建，处理 1412 项，1731 条无法映射历史身份保留；日志 `/tmp/widget-resource-config-rebake.log`。未修改业务布局内容。
- 真实编辑器初验发现 website 范围误带 store/channel=default 返回 422，已修复为遵循 typed ScopeIdentity 的空下级并显式传 scope_kind；四层范围测试 4 tests / 70 assertions。cache:clear 后正式主域名 drawer 用例通过（1 passed，18.6 秒）：登录、编辑器 HTTP 200、基础默认页签、资源六字段及四组三态、切回按钮恢复、scope/URL不变。证据 `/tmp/theme-editor-resource-config-e2e-fixed.log`。先前服务/导航失败不作为本轮抽屉最终状态。
- 新增配置真实保存验收：首轮统一保存超过 30 秒，采样定位于 Phrase 词典合并加载；空保存复现 33.8/74.77 秒，不归因于六字段校验。扩大仅测试预算后，第二轮在保存前首页导航 `https://p05113ef3.test.weline.com:9555/` 连接超时，未进入 apply。结束精确回读六行全部 null，未留下覆盖值，保持原继承。证据 `/tmp/weline-resource-config-runtime-round2.log`、`/tmp/weline-resource-config-round2-final-state.json`。所有正式 runner 已结束。
- 抽屉功能两次正式 PASS；补存视觉截图那轮在登录即连接超时，没有成功图，不能用失败截图冒充新界面。当前整体未完成项仍含保存设置后真实首页合包/首段独立/交互、原迁移后台预览编辑完整流程与各资源位置场景。代码、可运行用例和原配置均保留。
- 原全模块采集任务退出后，已重试模块 `i18n:collect Weline_Theme`；当前仍运行（本会话 shell handle 72135，PID 16360，日志 `/tmp/widget-resource-i18n-collect.log` 显示 Collecting module: Weline_Theme）。尚无退出码，不能声明采集完成；后续先检查原进程，勿重复启动。未提交或发布代码。

## 追加需求：资源配置触发固化与即时预览

用户要求点击资源配置时及时刷新预览 iframe 并触发布局固化。实施合同：点击资源页签、该组配置实际保存成功后，先完成当前编辑上下文对应的布局固化，再刷新 iframe；保留当前范围、编辑区域、布局及预览版本，不创建发布版本。现有 compile-layout 仅渲染布局提取 slots，不能把单独调用它当成已完成实体固化。主题席补齐既有入口，验收席验证对应请求与 iframe 导航的先后顺序。

- 上一轮模块翻译采集已结束，日志明确 `Module Weline_Theme localization collection successful!`。后续翻译缓存清理报告当时无运行 WLS 实例而跳过；采集成功与运行刷新分开记录。原 PID 16360 已退出，不重复启动采集。
- 本轮 MCP prepare 仍返回 MCP_RUNTIME_STALE（常驻 PID 58347）；按仓库既有降级规则重新读取硬规则索引并有界读写，不重启共享宿主。

- 本轮正式旧验收重跑 `/tmp/weline-widget-runtime-sep23.log`：首页 PASS，57 项外部资源全部 HTTP 200、无重复/错位、layout 优先、6 初始化锚点，返回顶部实际点击滚动成功。editor 用例 FAIL：编辑器已加载但部件库持续“加载中”，60 秒未出现目标预览按钮；不是首页失败，也不是已完成新增/删除等流程。
- 配置保存完整验收第三轮 `/tmp/weline-resource-config-round3.log`：首页已打开，apply 在 PHP 90 秒超时，未到合包断言。finally 成功，精确回读六项覆盖仍 null，`restored=true`，未留下测试设置。原状态文件 `/var/folders/j_/3f031n9s6493k_nrptr2zck40000gn/T/weline-resource-config-4gAFEd/original.json`。测试进程均已结束。

- 新页签实现：`theme-brand-basics.js` 监听资源页签点击及 `weline:config-saved`；SystemConfig embed 仅实际保存成功后冒泡该事件。`theme-editor.js` 冲刷待处理编辑保存、核对当前 iframe 身份，调用 `compile-layout?resource_refresh=1`，成功后仅更新原 iframe URL 的时间戳。
- `ThemeLayoutEntityBakeCoordinator::refreshResourceArtifacts` 复用当前实体或选中历史版本解析，固化 page/chrome 及资源绑定，保留必装默认注入和选中版本人工卸载记录，不创建发布、不改版本指针。回执包含实际文件内容 SHA256 及仓内相对路径；测试读取本地文件核验，而不是把 slots 响应当固化成功。
- root 新回归：Node 6/6、PHP 回执 2 tests / 13 assertions。编译退出 0（`/tmp/widget-resource-preview-compile.log`）。正常 reload 在首批两个 worker ready 后第二批 draining 时 Unknown error 退出 1（`/tmp/widget-resource-preview-reload.log`），未强杀或重复重启。新 E2E 必须看到新固化回执再判定使用新实现。
- 配置保存阻碍的最小公共修复：`Framework/Phrase/Parser::extractModuleWords` 去除逐平铺词条复制增长数组，保持原翻译优先规则。实际英文 63,220 条词典前后结果 SHA256 一致，同探针约 13,402ms → 32ms；root 目标回归 3 tests / 5 assertions 通过。额外 Phrase 测试中 6 个 global-module 缓存失败已由原实现 `/tmp` 独立副本重现，未修改断言掩盖。该修复仍需真实配置保存验证，不能仅据性能探针宣布全链路完成。

本轮追加需求验收结果：

- 实际 PHP 固化链路通过：当前主题 3、frontend、website default、homepage default 草稿 d2162 输出 page 与 chrome 模板，磁盘文件及 SHA256 与回执相符。前后工作区数据、发布记录、chrome版本和 current.json 原文一致，没有发布或改变业务配置。证据 `/tmp/weline-resource-materialization-integration-result.json`、`/tmp/weline-resource-materialization-integration.php`。
- Parser修复后的真实配置保存通过：既有模型保存六项临时配置，`success=true`、版本 2284、耗时 1370ms；finally 精确恢复原六项覆盖 null，`restored=true`。证据 `/tmp/weline-resource-config-cli-save.log`、`/tmp/weline-resource-save-Nk6Ouo/original.json`。
- 浏览器新页签验收仍未完成：`/tmp/weline-resource-refresh-e2e.log` 在主站9555后台登录即 `ERR_CONNECTION_TIMED_OUT`，没有到达固化请求。配置完整页面验收第四轮 `/tmp/weline-resource-config-round4.log` 在首页连接超时，未进入apply；不能用上述PHP证据替代真实点击与iframe更新。
- 所有本轮runner/helper均已结束；未改变原资源配置，未提交、合并或发布。整体目标保持待验收，待本机HTTP服务恢复后继续已有正式E2E，无需重新迁移模板。

## 目标续行检查（2026-09-23 07:25 UTC）

上一轮分类为 progress：实现与真实PHP链路均新增了证据。本轮重新请求主站，curl exit 28 无法连接；9555 监听者仅 Master PID 72570，没有 Worker。框架正常 `server:restart default` 只因 Master 存在返回 already running，没有恢复实例。检查 Restart 实现确认 `-r` 仍委托此前失败的滚动重载，并非另一条恢复路径；未强制停止共享 Master、改服务策略或清状态。新浏览器验收尚无执行条件。日志 `/tmp/widget-resource-normal-restart.log`，整体目标继续 active，不能标为完成。

## 服务恢复后的续验（2026-09-23 07:56 UTC 起）

- default实例已由外部会话恢复为Master66354及四worker，首页HTTP200。原资源配置完整浏览器用例通过：`/tmp/weline-resource-config-resumed.log`，1 passed / 6.7s，设置版本2288后页面合包、资源请求与交互全部通过，finally六项覆盖恢复null。
- 真实页签初次失败暴露EditorApi导出误插：函数被放入autosave options而非对外对象。已最小纠正，并增加执行真实导出块+drawer两个事件的测试，先红后绿，5/5通过；再次编译exit0。
- 后续真实浏览器确认方法存在、drawer已绑定；原spec等待普通compile-layout URL不符合当前BinQuery通道，正在改为观察真实SDK/传输业务结果。诊断日志包含theme.editorRequest业务错误，尚不能标记页签通过。
- 商品卡图片真实加载成功，但新测试强制正方形与已有汉服精选货架68%媒体设计不符；依据既有CSS修正测试期待，保留自然尺寸与购买按钮不溢出验证，未改业务CSS。
- 187模板最新逐路径静态复核无内联残留、343次资源引用对应160文件全部存在；13份规范/提示无旧内联豁免。新版记录`../team/widget-assets-position-migration/widget-inventory-static-recheck-sep23.json`，明确旧29843运行证据过时而保留其历史。

- 新静态接线确认通过：EditorApi方法实际function、drawerBound=1。正式tab/save用例已改用共享`resource-sdk-observer.js`观察真实BinQuery参数/解析结果，保留真实网络传输与iframe导航，不再错误等待普通compile-layout地址；多实例用例同步使用观察器。
- 正确Host再次运行SDK诊断时，登录后theme-editor/index导航ERR_TIMED_OUT，没有获得新的业务错误证据。监听检查当前9555再次仅剩Master66354，无worker；未盲改业务逻辑或再重启服务。日志`/tmp/weline-resource-sdk-diagnostic.log`，runner已结束。
- 两份资源用例、隔离草稿多实例用例及商品卡专项当前Node语法检查通过；实际UI保存、多实例编辑、商品卡纠正设计预期后的复跑仍待服务恢复，不用语法检查替代。

- 服务恢复至 Master83935 后，真实 SDK 明确回报 theme_editor_context_mismatch：typed 网站身份为 default.__website__.default，但 compile-layout 缺少 scope query，旧接口默认为 default。已在实际 refresh 方法中由 iframe typed identity 计算规范 scope，保留后端身份校验；网站、店铺、渠道、特殊模式覆盖通过，Node 6/6，welineUi 编译成功。
- 同轮商品卡专项通过，1440/390 两张真实截图人工复核图片及购买按钮正常，证据 /tmp/weline-resource-sdk-recovered。修复 scope 后再次执行 tab/save 正式用例时服务又失去 workers，9555 仅 Master83935 监听，curl 连接超时；日志 /tmp/weline-resource-scope-live.log。保留待验收，不将单测或 PHP 产物证明代替 UI 流程。

- Master7078恢复后首页HTTP200，串行tab/save/多实例正式runner输出至 /tmp/weline-resource-scope-recovered2.log。08:30:51后再失去workers。只读诊断：var/log/wls/runtime.log:782/792的GET /发生HTTP/2 RST_STREAM；var/log/wls/default/error-2026-09-23.log:1949–1953的session_flush/session_instances收尾失败触发quarantine。worker_ssl.php关闭listener并排水，退出统一标记“热重载”，该标签不是外部reload的证据。08:30:54两个slot缺credential-bound process-birth lease阻止恢复。Worker7359具体退出原因尚不确定。未启停服务、未修改WLS；运行环境阻碍不能视为业务验收完成。
- 部件弹窗正式用例已将普通widget-preview HTTP等待改为真实SDK BinQuery观察，使用实际widgetSearch/widgetList预览按钮，不mock；共享observer可选URL筛选，默认resource_refresh行为保持。语法检查通过，实际执行仍待稳定服务。

- 更正 recovered2 完整结果：第二用例在服务恢复间隙已取得tab固化成功、UI保存版本2292成功、保存后固化成功；最终不是单纯连接错误，而是测试恢复助手误读new_row.value（真实字段v），框架ANSI异常stdout又掩盖原异常。已按真实2292 detail核对scope/key/old_row/当前唯一override，helper改用schema_fields_VALUE，正常record+rollback2292后六项精确null、state restored=true，独立复核证据 /tmp/weline-ui-2292-after.json。未直接覆盖数据。局部异常handler输出JSON到stderr/exit1，不再误报JSON语法错误。
- 新位置专项widget-resource-positions-draft.spec.js包含现有CSS/JS默认head、footer/body/end-body、先普通后layout提升、真实meta.showFooter=false回退；只用独立网站草稿，无DOM删除壳或mock资源。当前语法通过，尚待真实执行。
- 最新恢复Master22753后串行6用例runner启动，日志 /tmp/weline-resource-final-live.log，未发布或提交。
- 最终本轮runner /tmp/weline-resource-final-live.log：服务再次停止监听后4 failed、1 interrupted、1 did not run。root仅对本轮Playwright PID32001发送SIGINT；session15839已exit130，不存在活跃验收任务。UI保存用例此次未进入配置保存，独立草稿用例均进入finally清理且未出现cleanup断言失败；未操作共享服务。原2292恢复证据仍有效。整体继续待验收，不能以已有单测或部分SDK成功回执宣布完成。

- 后续目标回合再次核验：9555当前Master35288与两个Worker监听，但首页返回HTTP/2 503、x-weline-maintenance:1、Retry-After:5；服务并未恢复可验收状态。此前连续回合已反复遇到服务中断，本次未绕过维护、未再次启动浏览器或操作共享服务。当前无活跃测试runner；配置2292已精确回滚。剩余完整点击/保存、多实例、位置与弹窗流程依赖稳定运行环境，目标标记blocked，非完成。

- 用户明确允许独立实例端口。9555重新HTTP200后恢复正式验收，/tmp/weline-resource-resume-9555.log确认UI保存完整专项1passed且六项已恢复；tab最后badge baseline捕获太早的测试时序问题已修，保留前后身份断言。9555随后超时。
- 按授权启动HTTPS独立widget-assets-acceptance-9567，先200后SSL_CERT_RELOAD确认失败丢workers；正常stop首次被default证书退役后台任务certificate_retirement_replay持有跨实例锁阻止，释放后正常stop重试成功，未删锁/杀共享任务。
- 再启动纯HTTP独立widget-assets-http-9568（--edge=wls --no-nginx --no-ssl --direct --count=2 --worker-memory-limit=512M --log），首页200且正式runner完成：/tmp/weline-resource-http-9568.log，3passed（tab固化刷新/页面资源交互/弹窗预览），2failed（multi首次写revision conflict；positions写后普通promoted资源locator0）。没有mock或发布，fixture清理默认范围比较未失败。9568仍在供本次后续验收使用。
- 独立HTTP两草稿专项复测诊断：读取revision0，提交expected0，失败后revision3；typed identity/parent234一致，新增均为编辑器初始化必装部件。不是资源业务revision字段错误；测试绕过了正式pendingScopedMutation队列。已将两spec读+单次写整体接入该现有队列，无写重试、未改产品版本校验。最新串行复测 /tmp/weline-resource-http-queued.log。
- 资源标记说明：优化后artifact会按既有契约移除data-weline-module-source，因此旧位置marker0不能单凭该项认定丢资源；新测试保留完整workspace/iframe产物供诊断。
- UI截图中的两旧footer-help-center-link失效引用核对为既有chrome v9/草稿残留，早于本次资源迁移；现定义footer-faq-link，HEAD升级仅迁默认注入不改旧快照。只读证据/tmp/weline-footer-help-audit.json，未为消警擅改用户草稿。
- 两草稿用例再次核查发现新增整节点应使用add_node/remove_node，先前set在本级占槽合并时被过滤；已对齐正式协议，未改产品规则。最新 /tmp/weline-resource-http-contract.log 仍2failed，但这次两个FAQ UID已各一次进入固化模板，实际iframe只剩一个FAQ section。编辑工具栏子元素重复同UID不是11个FAQ实例；正在查固化后slot合并/去重，不再把失败归为环境。位置同轮产物已留档交管线席核查。HTTP9568持续200，HTTPS9567已正常停止且端口无监听；当前无活跃runner。
- 真实多实例缺陷修复：SlotRendererService新增时此前按widget code首匹配替换，后实例覆掉前实例及其资源。现按node_uid区分，不覆盖不同UID，同UID更新、无UID历史兼容仍保留。SlotHtmlBoundaryBehaviorTest先RED复现后19tests/134assertions PASS、PHP lint PASS，独立管线席只读复核通过。仅重启自建HTTP9568。
- /tmp/weline-resource-http-uid-fixed.log：1passed（完整多实例新增/两实例互不干扰/配置更新/删除后一实例交互/精确fixture清理），1failed（positions第二refresh promotedJs inHead=false，CSS提升已过）。positions-iframe-1/2实际HTML完整保留，普通JS提升layout位置正在定点排查，未放宽断言。
- 头部新增节点的声明已正确固化但渲染误选祖先同theme单binding。SlotRendererService::loadSharedChromeSlotWidgetsFromEntity现按当前scope/preview调用既有请求缓存resolveRenderSources；SharedChromeAssetBindingTest先RED（取祖先UID）后1test/6assertions PASS，相邻19/134仍PASS。独立只读复核确认本级优先、祖先继承、历史版本与空槽删除决定未变，无新增cache/placement补包。只重启HTTP9568。
- /tmp/weline-resource-http-binding-fixed.log：真实前两阶段10项CSS/JS位置、去重、HTTP200和普通→layout提升断言通过。整体唯一失败是第三阶段footer0实际5；解析为mini-cart内部footer1、评价blockquote内footer3、页面footer1。不能以showFooter=false作为真正无footer条件，既有blank/full布局提供实际无header/footer入口，正在将回退专项改用该真实布局，不删DOM或放宽断言。
- 当前187模板精确重核：widget-inventory-final-hash-recheck.json，2模板存在其他业务改动但仍无内联违规；无模板或已记录资源文件缺失。
- 空白full入口诊断：正式editor生成/blank，实际HTTP404，非DOM id错误。补齐正式功能：仅frontend blank/full走已认证compile-layout?render=html&include_html=1，复用现有typed校验与真实渲染，无任意模板参数；其它页面原路由、普通JSON保持；刷新SDK仍JSON且iframe保留render/locale/scope/status/version。Node先RED后9/9、PHP2/8、语法通过，独立只读复核通过，welineUi编译exit0。
- 自建9568停止后，正常启动两次被共享生命周期锁拒绝。只读证实09:42:30 default证书退役子任务PID13861（随后17179）purpose=certificate_retirement_replay连续占用9568锁及监听端口，不能当作HTTP实例ready；9568 metadata master_pid=0、lease stopping。无footer复测session72698因未就绪失败后已exit1，无活跃runner，未删锁/kill共享任务。待共享任务释放后正常启动9568，再只跑blank full专项；不新开更多端口。

- 最终运行收口（2026-09-23）：独立9568正常启动HTTP200；真实blank/full暴露后台壳，HTML分支临时layoutType=null并finally恢复，PHP3/14通过，正常reload成功。无footer正式用例1passed/24.6秒（/tmp/weline-resource-final-nofooter-shell.log），验证CSS/JS唯一body/200/defer、固化后刷新与范围恢复。
- 补齐真正backend代表：只读Dashboard系统状态部件外置CSS唯一head/200/loaded、pageerror0，1passed/20.0秒（/tmp/weline-resource-backend-final.log），截图人工核对；没有fixture发布或注册重建。
- 187精确模板最终重核无缺失与内联残留，两项其它会话的业务修改哈希与之前复核相同并保留。各项当前证据汇总acceptance-status.md；未提交、未合并、未生产发布。
