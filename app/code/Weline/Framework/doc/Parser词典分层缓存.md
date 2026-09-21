# Parser 词典分层缓存

WLS 请求在路由、Observer 和视图执行过程中逐步发现模块。公共翻译仍由 `GlobalDictionaryProviderInterface`、`ModuleGlobalDictionaryProviderInterface` 和现有共享缓存提供；语言依赖与 namespace generation 由 `DictionaryCacheNamespace` 统一管理。

## 进程缓存与请求引用

- 每个 generation/locale 保存不可变增量词表的列表。首次加载一个模块批次后追加其词表，后续不会修改已发布的词表。
- `workerLocaleWordsCache` 和 `workerLayeredWordsCache` 的模块组合条目只保留词表的浅引用列表，不保存每个组合的完整累计字典。旧请求和先前模块组合保留原列表，因此后发现模块及新 generation 不会改变旧层的优先级。
- 模块 CSV 的 locale fallback 合并结果按 generation、目标语言、默认语言和模块复用一次，仍在原 `workerModuleWordsCache` 中管理。
- 普通解析按原模块优先级、locale fallback 和增量发布顺序查找译文；请求 overlay/exclusive 优先。最终译文及已确认的缺失词继续复用现有 `workerTranslatedWordsCache`，不新增业务缓存。
- 层构建时已经确认的字典完成事实随层携带，负词解析不再逐词重复检查所有模块。失败或未完成的加载不发布完整层，也不把暂时原词回退冻结为最终译文。
- 只有显式 `getWords()` 或旧的完整词典消费者物化合并数组。非持久 CLI 的显式全词库语义保留；空模块 WLS 请求仍只按需逐词回退。

## 定向验证

`Test/Unit/Phrase/ParserLayerRetentionTest.php` 覆盖增量发现内存、旧模块组合、同名译文与原词占位、模块 CSV 和语言 fallback、overlay/exclusive、并发旧 generation，以及确定缺失词的最终缓存。

隔离真实 Parser 实验（28 模块、每模块 1000 词、两个语言）中，普通分层加载由约 79.93MiB 降为 17.14MiB；两代次由约 145.08MiB 降为 19.47MiB。共享 Provider 批次数保持 56。数字包含实验内存缓存中的原始词表，属于特定数据规模的开发证据，不能替代真实页面冷请求验收或承诺整页 70ms。

原始逐层查找在较早层命中及全缺失时需要多次哈希查找；高频重复词由既有最终词缓存吸收。验证同时测量首次解析与热解析，避免仅用内存下降判断性能完成。


## 2026-09-13 真实运行复验

Root 已通过 43 tests / 171 assertions 有效回归，并统一编译、滚动加载隔离 19655 和默认 9555。两实例各三份动态 HTML 的正文、商品链接、图片引用和 footer 与基线一致，默认浏览器禁缓存筛选分页及页脚实图通过。普通 FPC MISS 仍为 2.80 秒、随后 HIT 53/58ms，不能据局部内存下降宣称 70ms 冷启动达成。详细队列、上下文及剩余翻译代次重建证据见《统一缓存范围与性能优化》同日“增量词典层、上下文身份与响应后任务空闲推进”节。既有 authority 不可用用例的基线失败单独保留，未计入通过数字。

## 语言范围代次与默认语言快照

`DictionaryCacheNamespace::namespacePaths(array $locales = [])` 是不访问存储的统一叶子规范化入口。有效语言经 `LocaleFallbackChain::normalize()` 处理后，去重排序为 `global/i18n/{locale}`；祖先 `global/i18n` 由核心代次仓库展开。没有有效语言或存在畸形输入时保守使用 `CONTENT_NAMESPACE`（`global/i18n/content`），保留旧无参消费者在任意翻译内容改变时失效的语义。写端须将实际语言叶子与 content 同批提升；全词库清理仍提升父代次。

`fingerprint($locales)`、`cacheKey($key, $locales)`、`localCache($cache, $maxEntries, $locales)` 与 `scopedPool($pool, $locales)` 复用同一规则。指纹按依赖集合挂在框架 RequestContext，事务读取继续禁止发布公共 L1；无请求 ID 的 CLI 继续使用进程指纹。没有新增独立版本存储。

进程袋底层委托 `ProcessMemoryStore`（语言维 `bucket=locale`）：重语种驻留默认 ≤4（可 `phrase_heavy_locale_resident_max`），超限踢最冷 locale 桶；可挂 `ProcessMemoryReclaimableAdapter`（经 `PhraseProcessMemoryReclaimable`）参与压力回收；`Parser::clearWorkerCaches()` 同步清空 Store。Store **不**实现 `MemoryStoreInterface`，也不经 CachePool `processStore`。

- 原子词条、确认缺失词、单语言模块词表及完成标记只依赖本语言叶子。其他语言写入不会重建它们。
- 外层模块组合、物化结果及最终译文依赖实际完整回退链。目标、neutral 与网站默认语言的有序链同时进入请求签名、Worker 键和已发布层 metadata；代次集合排序不改变翻译优先级。后续切换默认语言不会改变已有层的逐词回退。
- 网站默认语言的唯一读取入口为 `LocaleFallbackChain::websiteDefaultLocale()`：依次读取已加载的 `website.language`、`locale`、`lang`，最后使用框架默认常量。非中文链为目标语言、`en_US`、网站默认语言并去重；中文目标不追加 neutral 或默认语言。
- 共享池底层只保留父命名空间，具体词条批次按候选语言取得现有不可变 namespace facade。批量预取的池与版本前缀位于候选语言循环内，避免所有原子词条被错误绑定为整个回退链或 content。

`ParserLocaleNamespaceTest.php` 使用按 namespace 集合变化的 authority fixture 与真实 namespace pool 键装饰器，覆盖叶子别名、无参全内容兼容、其他语言不失效、当前/回退/父代次失效、跨 Worker L2 复用，以及默认语言切换和旧层快照。此前保留的增量词表引用、overlay/exclusive 与滚动模板兼容机制不变。该定向证据仍不替代主任务统一编译后的真实冷请求验收。

## 模块原子词表预取

已确定本次渲染依赖的统一入口可以调用 `Parser::prefetchGlobalDictionaryModules(array $modules)`。它只预取明确给出的模块，不读取全模块目录；空列表不代表全语言词库。未实现模块批量接口的旧 Provider 继续走正常解析路径。

预取复用 `ModuleGlobalDictionaryProviderInterface::wordsByModule()`、既有模块 L2 键和 single-flight，一次读取每个候选语言尚未缓存的模块。原子词表在 `workerGlobalDictionaryWordsCache` 中按单语言代次保留；后续同 Worker 直接读取，其他 Worker 通过 WLS 复用。该数组继续使用既有 128 条保留上限，没有新增缓存管理器或失效事件。

预取不会修改 Request 模块成员、`loadedModules`、已发布翻译层或请求签名。正常解析仍按实际模块差集与原有顺序激活词表，持久层只追加不可变原子数组引用，避免再复制一份合并词典；CLI 和显式物化保留原合并语义。预取失败不发布空词表或完成标记，正常解析仍可重试。

语言优先读取已存在的 `StorefrontCacheKeyContext::current()`：仅当目标语言与冻结上下文一致时复用有序 `translationLocales`；局部语言不同或没有冻结对象时使用 Parser 原回退链。整个语言集合先固定代次，原子缓存仍只依赖各自语言；不创建 scope fence、不调用 Resolver、不修改语言或上下文。

`ParserModulePrefetchTest.php` 覆盖非激活行为、旧层和同名词优先级、后续消费零词表 I/O、跨 Worker L2、语言及父代次、失败恢复，以及渐进模块加载的内存增长。真实冷请求收益由统一编译后的页面 trace 验证，不能用预取 fixture 或整页缓存命中代替。

## Hook 文件批次预取

`Template::getHook()` 在 HookReader 已解析文件、完成 `solo` 筛选之后，使用 `TraitTemplate::processModuleSourceFilePath('hooks', $file)` 获取实际文件来源模块，并统一调用上述非激活预取 API。注册模块可能指向另一个 `Module::path`，不能直接把注册列表键当作来源模块。预取仅排序去重后的模块副本，原文件集合与执行顺序不变；真正激活仍由逐文件 `fetchHookHtml()` 完成。兼容旧 Worker 未提供预取方法的滚动发布。

整批 Request/aggregate 输出缓存命中仍在文件发现和预取之前返回。单文件 `render_once` 与输出缓存判断则位于 `fetchHookHtml()` 内，因此它们属于已预取批次，即使最终跳过文件渲染也可能预热字典。该入口只合并本次 Hook 已知文件的缺失模块，不覆盖之前的控制器译词或尚未发现的嵌套 Hook；不读取全模块目录，不新建依赖索引或缓存。

`TemplateHookDictionaryPrefetchTest.php` 执行生产的 Hook 方法和路径解析方法，使用真实 Parser，覆盖来源模块、`solo`、预取先于逐文件激活、原顺序、整批缓存提前返回、单文件缓存边界和旧 Parser 兼容。诊断阶段 `view.hook.dictionary_prefetch` 仅记录 Hook 名、模块数及模块集合摘要；收益仍以真实页面 trace 为准。
