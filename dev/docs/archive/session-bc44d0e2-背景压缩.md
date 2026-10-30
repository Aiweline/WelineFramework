# session-bc44d0e2-a84a-4b3b-a1a2-adcc6edf453a · 背景压缩

- 生成时间：2026-10-30 23:xx (Asia/Shanghai)
- 会话档案：`~/.dsh/sessions/--Users-weline-Project-Official-~6846~67B6--/session-bc44d0e2-a84a-4b3b-a1a2-adcc6edf453a/session.v4.jsonl.zstd`（9.7MB zstd → 29.4MB / 22,342 行）
- 覆盖会话：`session-bccc65f1-f98c-4460-8984-88e4a66e58b6`（接手）→ 本会话 16:06–23:09
- 仓库：`/Users/weline/Project/Official/框架`

---

## 1. 会话主线（一句话）

接手「优化Worker渲染」会话 → 定位并重写 i18n 短语热路径（`__()` 207µs → 1.4µs）→ 修 `setup:upgrade` 路由与全站 404 → 发现「系统更新删表」事故根因是 Model 读取链路 → 转入**全仓 `getItems()` → 数组优先 API 改造**（当前目标，29 轮未完成）。

---

## 2. 已完成的四段工作

### 2.1 性能定位与 i18n 热路径重写（16:06–18:15）

用户纠偏三次：「你在做什么？不是优化性能么」→「用debug技能定位问题，不要乱搞其他的」→ 反复要求「找到一个问题修复一个问题，一步一步来」。

**根因链**：页面渲染慢 → `__()` 每次调用都在重算分层词典缓存键 → 缓存键重算又触发多层「袋子」隔离。

**用户设计裁决（会话内定调，后续所有相关改动均以此为准）**：
- 词典是**全局事实**，删除请求级隔离，只保留「语言 × 前后端区域」隔离；
- 只用**一个袋子**（静态变量挂在 Phrase 类上），内部分区域，禁止多层袋/多层缓存；
- 缺词典才从文件加载，加载后写回静态变量，并置「已尝试」信号，此后不再尝试；
- 取不到词直接返回源串；
- 语言/货币在请求初始化时确定，之后一律从上下文读取，不得自行重算。

**落地文件**：
- `app/code/Weline/Framework/Phrase/Parser.php` — 新增 `private static array $regionWords = []`（键 `"<lang>|<area>"`）、`private static array $regionLoadAttempted = []`、`$layeredWordsCacheKeyMemo`；`processWords()` 改为 重入守卫 → 命中记忆 → `hotRegionKey()` → 静态数组 → 未命中走 `lazyLoadRegion()`（先置尝试信号，再 `LocaleAreaDictionaryLoader::loadMerged($lang,$area,false)` 直读文件）；删除 `BAG_WORKER_MATERIALIZED_WORDS` / `requestBag` / `translateFromEventDictionary` / `EventDictionary::isExclusive` / `TraceGate` 等热路径依赖；两处 `array_merge` 改 `array_replace` 去重。
- `app/code/Weline/Framework/Env/WelineEnv.php` — `getLangLocal()` 先读 Context 缓存 `state.lang_local_cache` → `route.language` → 才走 `resolveLangLocal()`；`getLang()`/`getCurrency()` 同理读 `state.lang_cache`/`state.currency_cache`。
- `app/code/Weline/Framework/App/State.php` — `resetLangLocalCache()` 一并清理 6 个语言相关 state key；`resolveWebsiteDefaultLanguage()` **不再**返回 `preferredLanguageCodes()[0]`（无默认配置时返回字母序第一个 `sv_SE`，是全站 404 诱因之一），改回落 `zh_Hans_CN`；`resolveBackendEffectiveDefaultLanguage()`、`isAllowedLanguageCode()` 记忆化。
- `app/code/Weline/Framework/Cache/Namespace/NamespaceGenerationRepository.php` — 加进程级 `$ancestorCache` + `ancestorExpansion()` 记忆化 + sha256 记忆键。
- `app/code/Weline/Framework/Runtime/MemDiag.php` — 移除 `phrase_materialized` 注册行。

**实测成绩（留存证据）**：`__()` 207µs → **1.4–1.6µs**；`getLangLocal` 5.44µs → **0.20µs**；`getLang` 3.2µs → 0.19µs；`getCurrency` 1.86µs → 0.19µs；整页热 ~590ms → ~560ms；`WorkerMaterializedWordsCache` 重复占用（~3.3MB/region）已消除。

**测量依据**：文件 include 冷 13.6ms / 热 0.1ms；静态数组读 0.007µs；文件序列化写 1.85ms / 读 3.35ms；ProcessMemoryStore 写 4.21ms / 读 ~0；共享内存服务 `set` 31.2ms / `get` 每次 13.6ms → 结论：**直读文件 + 静态数组最优，不走共享内存服务**。隔离验证：4 语言 × 6 轮交错 → 0 mismatch，前后端键不同，`allowed(en_US)=true` / `allowed(zz_ZZ)=false`。

### 2.2 路由与全站 404（18:19–19:10）

用户链：「系统更新报错，解决后路由应该就有了」→「那就是Api的开发没按照规范走，修复」→「根因是之前只放控制器的APi目录放了其他的类对不？」→「那很简单啊，过滤掉没继承前端Api控制器的就可以了吧」→「并发竞态这种问题怎么解决？原本是想加快模块控制器读取的，最后再合并」→「对，最后合并，开始」。

**改动**：
- `app/code/Weline/Framework/Module/Helper/Data.php` — 路由收集缓冲改为 **Fiber 局部**（`collectionSlot()` / `&collectedRouteRegistrations()` / `clearCollected*()` / `releaseCollectionSlot()`，在 `registerModuleRouter` 末尾释放）；`apiSymbolCanRoute()` 新增：要求父类名以 `...RestController` 结尾（自包含判定：`class_exists($symbol,false)` + isInterface/isTrait/isAbstract/isEnum 守卫），API 调用点改用它并记 `recordRouteScanNotice('api_symbol_not_controller')`；PC 与 API 两支的 `array_pop` 剥 `index` 逻辑改为 `trim(preg_replace('#/+#','/',str_replace('\\','/',$rule_router)),'/')` + 回退 `$baseRouter`。
- `app/code/Weline/Framework/Router/Core.php` — 常驻运行时分支加 `clearstatcache` + `filemtime` 比对 `$generatedRouterFileMtimes`（过期则解冻）；`getGeneratedRouterLookupIndex()` 为首页补别名键 `''`/`index`/`index/index`。
- `app/code/Weline/Websites/Observer/DetectWebsite.php` — `getWebsiteDomainRows()`/`getWebsiteRows()` 改读 `RowCollection->getItems()`，站点匹配过滤改 `toModels()`；**这是全站 404 的直接根因**（在 Model 上调 `getItems()` 恒空 → `matchedSite=null` → `noRouter(404)`）。
- `app/code/Weline/Websites/Model/WebsiteDomain.php` / `Website.php` — 新增静态 `fetchCatalogRows(): array`（单次 `fetchArray()`，无克隆无对象）。
- `app/code/Weline/Websites/Integration/Backend/FrontendStartPageRouteProvider.php` — `$websiteCode === ''` 时不再回退全局 scope（原为跨站 `frontend_start_page_path` 泄漏 → 全站 404）。
- `app/code/Weline/Framework/Http/Fpc/FpcHtmlDiskStore.php` — `publish()` 拒绝错误文档（`looksLikeErrorDocument()`，见 76 行 / 399 行）。用户原话：「忘了一个缺陷问题，404是不允许fpc的，否则就会出这个问题」「我们框架只要不输出404还存到fpc就行」。
- `app/code/Weline/Server/Service/WorkerFullPageCacheFastPath.php` — 前置条件：除非 `RequestContext::scopeIdentity()` 是完整的 `KIND_CHANNEL`，否则返回 null（早期 FPC 查找跑在身份安装之前 → 全站 500）。
- `app/code/Weline/Server/Runtime/WlsRuntime.php` — `runProcessL1HydrateCycle()` 独立 `Context::enter(new Context(...))`，`finally { RequestContext::cleanup(); Context::leave(); restore previous }`；移除 `hydrateBags()` 调用（恒 `bags_peeked=0` 且污染身份）。
- `app/code/Weline/Framework/Runtime/RequestPipeline.php` — 恢复被误删的 `else` 分支（`$app->parseUrl()` / `applyParsedUrl()`），这是间歇性 500 的根因。
- `app/code/Weline/Framework/Router/FullPageCacheCoordinator.php` — 整文件从 HEAD 恢复（`getCachedResponse()` 完整）。

** Fiber 竞态诊断**：默认并发下 `Weline_Widget/Payment/Server/Visitor/DeveloperWorkspace` 全量升级 `collected=0`；`WELINE_ROUTE_FIBER_CONCURRENCY=1` 通过 → 定位为共享收集缓冲的 Fiber 竞态 → 用 Fiber 局部槽位修复后，默认并发可报「路由更新阶段已提交」。

### 2.3 「系统更新把表删了」事故（19:14–19:29）

用户原话：「那就是大问题了，系统更新把表删了，卧槽。系统更新备份表的逻辑有大问题，看下是不是有危险操作删表的。」→「是查询Model优化导致的问题吧，看下」。

**根因**：`getItems()` 改造后调用链上出现「`fetch()` 返回数组 → 调用方仍按对象用（`instanceof` / `$row->getData()`）」→ 静默返回空 → 升级/备份流程把「查不到表」误判为「表不存在」→ 走删表/重建路径。

**修复方向**：全链路改成纯数组读取，除非确实需要对象。

**已确认的漂移点**：`UsageAuditLegacyBackfill`、`AdapterScanner`、`ThemeScopedWorkspaceRequestService`、`FooterHelpCenterLinkScopedMigrator`、`RulesPushDefaults::extractItems`、`Order/Controller/Backend/Records`、`CustomerPurchaseFactsService`、`UnpaidOrderSignalService`、`UnpaidOrderMailLineProjector`、`InvoiceService`、`BuyerInvoiceDownloadService`。

`app/code/Weline/ModuleManager/Service/ModuleBackupService.php` 现已 4 处改为 `toModels()`（54/297/329/366 行）；218 行的 `DROP TABLE IF EXISTS {$originalName}` 为改名/移交路径的既有动作，不是本次事故根因。

### 2.4 全仓 Model → 数组优先 API 改造（19:35 起，当前目标）

**用户裁决（原文）**：
- 「改成fetch默认就是数组，需要对象的用getItems或者fetchModel」
- 「优化所有使用getItems的地方…其实我们就只是想查一下这个记录的一个数组信息，并不需要返回一个对象…都改成那个fetch返回宿主」
- 「剩下的看下周围逻辑，没必要的都改成fetch，直接修改成数组。除非是向外部真的要提供对象的就继续用」
- 「getItems是可以留的，只是不需要的地方就不要用这个」

**分类规则（会话内建立并一致执行）**：
| 场景 | 用法 |
|---|---|
| 只读映射 / 计数 / 空判断 / 单列取值 | `fetchArray()` + 数组下标 |
| 需 `setData()->save()` / `delete()` / `getConnection()` / `getData()` / `getId()` / `instanceof X` / 传给别的服务 | `fetch()->toModels()` |
| 单行返回模型 | `firstModel()` |
| 分页 | `getPagination()` 仍从 query model 取，行数据用 `fetchArray()` |

**关键澄清**：`->getItems()` 在 **RowCollection** 上链式调用仍合法（如 `$collection->getItems()`）；bug 只在于 **`fetch()` 之后在 Model 上**调 `getItems()`。

**框架侧**：
- `app/code/Weline/Framework/Database/Query/RowCollection.php` — 新增 `fetchModel(): array`（`toModels()` 别名），注释写明数组行语义与 2026-10-30 Websites 404 事故背景。
- `app/code/Weline/Framework/Database/Query/QueryDelegator.php` — 注释说明每行是关联数组、`getItems()` 保持可用。

**已清零模块**：Shipping（~45 文件 / 116 处）、Ai（10）、Agent（7）、Theme（13）、Cdn（0）、I18n（0）、Order（2 处收尾）、Promotion、Ops、ModuleManager、Multipass、Mail、Queue、Async、Checkout、Acl、Cms、DeveloperWorkspace、Visitor（部分）。

---

## 3. 当前状态（2026-10-30 23:09 会话中断点）

**目标**：`goal-14751025-8c32-44f6-aeba-19577f41500b`，objective「继续直到处理完这个Model的优化修改」，maxGoalRounds 256，**已跑 29 轮，phase=active**（revision 13，末次操作 resume）。

**全仓残留（规范口径现场实测）**：
```
grep -rn -e '->getItems()' --include=*.php app/code \
  | grep -v '/Test/\|/test/' | grep -v toModels | grep -v fetchArray | grep -v '// '
=> 146
```
按模块分布（现场实测）：Payment 9 · Framework 9 · Seo 7 · Smtp 6 · Checkout 6 · Backend 6 · Visitor 5 · Inventory 5 · ElFinderFileManager 5 · Database 5 · AppStore 5 · TranslationService 4 · I18n 4 · Cron 4 · App 4 · Api 4 · Ai 4 · Inquiry 3 · Indexer 3 · Frontend 3 · Dropship 3 · Currency 3 · Cart 3 · Admin 3 … 约 60 个模块。

**中断点**：会话在改 Visitor 模块中途结束（最后一条是模型切换提示 `magpie/antigravity/claude-opus-4-6-thinking → qwen/q2_0`）。**Visitor 5 处仍未改**：
```
app/code/Weline/Visitor/Model/Pixel.php:173                      return $model->getItems();
app/code/Weline/Visitor/Service/PixelChannelRuleSeedService.php:182   $items = $model->getItems() ?: [];
app/code/Weline/Visitor/Service/PixelChannelLookupService.php:207     $items = $model->getItems() ?: [];
app/code/Weline/Visitor/Service/PixelEventVendorManager.php:136       $items = $model->getItems() ?: [];
app/code/Weline/Visitor/Service/PixelMarkerAuditService.php:404      ->getItems();
```
本会话已改完：`Visitor/Controller/Backend/TrafficChannel.php`、`Visitor/Controller/Backend/PixelErrorMonitor.php`（两者 phtml 模板已做数组兼容：`is_object($row) && method_exists($row,'getData') ? $row->getData() : (array)$row`）。

**注意**：`Pixel::getPixelsByWebsiteId()` 同时被数组访问与 `instanceof` 消费（`PixelEventService.php:126`），改 `fetchArray()` 时必须同步改消费方。

**最后一份 todo**（seq 22237）：
1. Visitor 剩余 5 处 getItems —— `in_progress`
2. Seo 7 处 —— `pending`
3. Smtp/Backend/Inventory 等中型模块 —— `pending`
4. 筛 Payment/Framework 真实 Model 残留 —— `pending`
5. 回归验证三站 200 —— `pending`

**工作区状态**：`git status --porcelain` = **337 modified + 12 untracked = 349 个文件**（含其他会话的改动），**全部未提交**。

**三站回归（本次现场实测，非引用）**：
```
curl -s -o /dev/null -m 20 -w '%{http_code} ' -H 'User-Agent: Mozilla/5.0' http://<host>/
changanhanfu.test.weline.com   200 200
amayum.test.weline.com         200 200
weline-dev.test.weline.com     200 200
```

**已核对在位的关键修复**（本次现场验证）：
- `Parser.php` 静态词典属性 `regionWords`(257) / `regionLoadAttempted`(266) 在位；
- `FpcHtmlDiskStore.php` `looksLikeErrorDocument()`(76) + 调用点(399) 在位；
- `RowCollection.php`、`FullPageCacheCoordinator.php` 存在；
- `Framework/` + `Server/` 下**无 `debug start` / `debug end` 标记残留**。

---

## 4. 事故与教训（必须继承）

### 4.1 脚本批量替换违规（HARD 禁止，现已写进全局与仓内规则）

本会话用 `python3` + `re.sub` 批量剥离调试标记，**误删**了 `FullPageCacheCoordinator.php` 的 `hasCompleteFrozenStorefrontScope` / `legacyPreRouterFpcAllowed` / `namespaceKeyDecorator` 三个方法，以及 `RequestPipeline.php` 的 `parseUrl/applyParsedUrl` 分支 → 运行期 `Call to undefined method` 才暴露，`php -l` 初查未报错。恢复方式：`git show HEAD:` 整文件恢复后重打改动。备份：`/tmp/FullPageCacheCoordinator.broken.php`、`/tmp/fpc-current.diff`。

用户原话：「我全局中明显提示过不能用正则或者shell等脚本批量改代码的。而且是全局禁止的行为，你看下为什么违反，如何让这个信息一直跟随。修改全局规则」。

**违规机制根因**：宿主报告 `Workspace instruction budget 65536 bytes: omitted ~/.dsh/AGENTS.md`，而该文件仅 59,653 字节（未超预算）→ 规则根本没进上下文。

**修复**：
- `~/.dsh/AGENTS.md` 文件头新增「⚠ 硬规则速查（防注入截断 · 2026-10-30）」块（1,083 字节 / 6 条硬规则），文件现 60,668 字节；
- 仓内 `AGENTS.md` 新增「禁止脚本批量替换（HARD · 2026-10-28 用户指定，2026-10-30 加仓内常驻镜）」6 条（含本次事故记述），因全局文件超 64KB 注入预算会被整体省略，故仓内留常驻镜兜底；两处口径冲突以 MCP `hard_constraints` 为准。

**强制做法**：一个文件、一次定点编辑 → 改完立即重读复核；批量任务 = 循环该流程 N 次。

### 4.2 debug 技能被用户改写

用户原话：「修改debug技能，先看日志定位大致范围后，再去翻代码看，如果看代码太复杂看不出来才进行锚点调试。」
`~/.agents/skills/debug/SKILL.md` 已加「定位顺序铁律（HARD）」章节（日志 → 代码 → 锚点调试）+ 判断表。

### 4.3 汇报只给重点与建议

用户原话：「修改全局的开发规则，以后AI汇报只用汇报重点和建议。除非用户要求」→ 已写入 `~/.dsh/AGENTS.md`、`app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php`（`user_facing_key_points_and_suggestions_only`）、`app/code/Weline/Ai/doc/规则/00-MCP调用与会话.md` §4.3b。

### 4.4 其他坑

- `git stash` 一推 `Data.php` 就让全量升级报 `Call to undefined method Weline\Framework\Module\Helper\Data::enableDirectRouteRegistration()` → **4 个脏文件是一个互相依赖的变更集，不能单独动**。
- `resolveWebsiteDefaultLanguage()` 返回 `preferredLanguageCodes()[0]` → 返回 `sv_SE`（46 个允许语言字母序第一个）。`x-weline-title-locale: sv_SE|zh_title_on_non_zh|长安汉服` 只是观测字段，是**红鲱鱼**，但顺着它找到了真 bug。
- 503 `X-Wls-Edge-Unavailable: origin` = WLS 完全停掉（9555 无 worker），不是 nginx 问题。
- `NamespaceGenerationRepository` PHP fatal `Can't use method return value in write context` → 改用 `&$ref` 局部变量。
- `apiSymbolCanRoute()` 一度做成「父类链含 Controller」，改成「requireControllerBase」后 **130 个模块 collected=0** → 已回退，现为 Api 侧专用 `apiSymbolCanRoute()`。
- 并发会话同时改 `Parser.php` 与路由文件 → 每次编辑前必须重读，收到 `FS_STALE_VERSION` / 「file has not been read」即重读 rebase。
- **404 缓存陷阱**：早期无 `timing.log` 新行且 `[dbg-router]` 无输出 → 请求被 FPC 挡在 PHP 之前，根本没进 router。

### 4.5 已知「红」基线（HEAD 既有，非本次引入，勿回归）

`ParserLocaleNamespaceTest`(2) · `ParserModulePrefetchTest`(4) · `PhraseContractsTest`(1) · `ParserFiberRequestStateIsolationTest`(1)

另：`app/code/Weline/Ai/Mcp/tests/host-editor-rules-generator.php` `php -l` 失败（line 57 `syntax error, unexpected token "&&"`），为 HEAD 既有问题，该文件 git 干净。

---

## 5. 下一步（接手起点）

1. **Visitor 模块收尾**（中断处）：`Pixel.php:173` 改 `fetchArray()`，同步修消费方 `PixelEventService.php:126`（`$pixel->clear()->getPixelsByWebsiteId(...)`）与其余 4 处 `getItems()`；改完 `php -l` 逐个复核。
2. **按 todo 顺序清**：Seo(7) → Smtp(6)/Backend(6)/Inventory(5)/ElFinderFileManager(5)/Database(5)/AppStore(5) → Payment(9)/Framework(9) 逐个甄别「真 Model 残留」与「DTO/会话/RowCollection 合法调用」。
3. **重点甄别假阳性**：`Framework/Env/Api/Data/EnvRequirements.php`（`addItems`）、`Framework/Session/Business/WishlistSession.php`（自身 `getItems`）、`RowCollection.php`/`QueryDelegator.php`（注释）、`Framework/doc/**`（文档）均**不是**目标残留。
4. **回归验证**（每次批量改完）：
   ```
   php bin/w framework:compile
   php bin/w server:stop && pkill -9 -f weline-wls && php bin/w server:start   # 等 ~14s
   curl -s -o /dev/null -w '%{http_code}' -H 'User-Agent: Mozilla/5.0' http://{changanhanfu|amayum|weline-dev}.test.weline.com/
   ```
   三站必须 `200 200`。注意 `server:reload -r` 单独用不可靠（不保证清 opcache / 路由静态缓存）。
5. **改文件铁律**：一次一个文件、一次定点编辑、编辑前先重读（防并发会话覆盖）、改后重读复核、**禁止脚本批量替换**。
6. **汇报**：只给「重点」+「建议」；任何「已完成/已通过」必须附命令 + exit code + 原始 stdout 片段。

---

## 6. 关键路径速查

| 用途 | 路径 |
|---|---|
| 仓库根 | `/Users/weline/Project/Official/框架` |
| 会话档案 | `~/.dsh/sessions/--Users-weline-Project-Official-~6846~67B6--/session-bc44d0e2-a84a-4b3b-a1a2-adcc6edf453a/session.v4.jsonl.zstd` |
| 调试脚本 | `dev/debug/render-perf/`（`ladder.md` 假设台账、`mock-render.php`、`dict-store-bench.php`、`lang-isolation*.php` 等） |
| 回归脚本 | `/tmp/regress.php`、`/tmp/ai.php`、`/tmp/i18n.php`、`/tmp/cdn.php`（调用前需 `Context::enter(new Context()); RequestContext::init();`） |
| 本地 WLS | 9 workers，端口 9555 |
| 全局规则 | `~/.dsh/AGENTS.md`（60,668 B，**注入时被省略**）+ 仓内 `AGENTS.md` 常驻镜 |
| debug 技能 | `~/.agents/skills/debug/SKILL.md` |
| 硬规则权威 | `app/code/Weline/Ai/doc/AI硬规则索引.md` + MCP `hard_constraints` |
