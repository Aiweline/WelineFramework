# I18n AI 自动翻译队列实施记录

## 实现范围

- 后台菜单新增 `系统配置 > i18n国际化 > AI翻译`，路由为 `/i18n/backend/ai-translation`。
- 配置统一存储到 `system_config`，模块为 `Weline_I18n`，配置键为 `ai_translation`，内容为 JSON。
- 默认配置为：全局关闭、源语言 `zh_Hans_CN`、批量大小 `100`、翻译策略 `light`、自动发布开启。
- 每个已安装且启用的语言可以单独开启 AI 翻译；源语言自身始终跳过。
- 不新增 I18n Cron；AI 翻译由后台保存配置、手动立即翻译、词典收集、CSV 导入等入口触发队列入队。
- 已移除旧 `Weline\I18n\Cron\AiTranslation`，避免绕过后台配置翻译所有 active 语言。

## 关键类

- `Weline\I18n\Service\AiTranslationConfig`：读取、保存、归一化 AI 翻译配置，过滤未安装或未启用语言。
- `Weline\I18n\Service\I18nAiTranslationAdapter`：I18n 侧 AI 翻译适配器，只暴露批量翻译入口，并继续复用 `Weline_Ai::translate` 事件链。
- `Weline\I18n\Service\AiTranslationService`：扫描未翻译词、批量调用 AI、校验占位符和结构 token、写入译文。
- `Weline\I18n\Service\AiTranslationQueueService`：创建 AI 翻译队列，使用 `i18n:ai_translation:{locale}` 作为去重业务键。
- `Weline\I18n\Queue\AiTranslateQueue`：队列执行器，负责参数校验、调用翻译服务、在仍有剩余词时继续入队。
- `Weline\I18n\Service\AiTranslationPublisher`：成功写入译文后同步语言文件并清理 `i18n`、`phrase` 缓存。
- `Weline\I18n\Controller\Backend\AiTranslation`：后台配置页、保存配置、手动入队入口。

## 翻译规则

- 自动翻译只处理后台配置中 AI 已开启、且 `i18n_locale` 已安装并启用的目标语言。
- 手动“立即翻译”的操作边界是 `i18n_locale` 已安装、已启用、且不是源语言；单语言 AI 开关只控制自动入队，不阻止人工触发。
- 源语言自身不入队、不翻译。
- 批量选择会持续扫描词库，直到收集到指定数量的未翻译词或词库结束。
- AI 响应优先解析严格 JSON 数组；解析失败或数量不匹配时降级逐条翻译。
- 译文为空、数量不匹配、占位符丢失、HTML 标签或模板变量结构不一致时记录失败，不写入成功译文。
- 已存在但 `translate` 为空的 locale dictionary 行不再视为已翻译，会继续进入待翻译扫描和待翻译统计。
- 每批成功后根据配置自动发布；发布会刷新语言文件并清理缓存。

## 自动入队入口

- 后台 AI 翻译配置保存后，为已启用 AI 翻译的语言入队。
- 后台 AI 翻译页点击单语言“立即翻译”后，对已安装启用且非源语言的目标语言强制入队，不要求该语言已开启自动 AI 翻译。
- `CollectTranslations` 收集到新基础词条后，为已启用 AI 翻译的语言入队。
- 后台词典 CSV 导入后，为已启用 AI 翻译的语言入队。
- 队列执行后若仍有未翻译词，自动创建下一条 pending 队列继续处理。

## 后台提示修复

- AI 翻译页面不再单独渲染 `Weline_Component::message.phtml`，避免和后台全局布局重复输出。
- 后台默认布局和 blank 布局的消息区域补充 `wf-system-messages` 容器类，统一使用主题消息条样式。

## 验证记录

- `php -l` 已覆盖新增和修改的 Controller、Service、Adapter、Queue、Observer、模板文件。
- `php bin/w queue:collect` 已执行，AI 翻译队列类型可收集。
- 队列类型检查已确认 `Weline\I18n\Queue\AiTranslateQueue` 可解析到 queue type id。
- `php bin/w cron:task:collect` 已执行，旧 `i18n_ai_translation` Cron 物理文件删除后不再注册。
- 配置归一化已验证：非法 batch size 会限制到最大值，非法语言会过滤，源语言会强制关闭。
- 队列入队已验证：启用 `pt_BR` 后创建 pending 队列，业务键为 `i18n:ai_translation:pt_BR`。
- 浏览器已验证后台 AI 翻译页可打开，配置控件、语言表格、保存按钮、立即翻译按钮可见。
- 浏览器已验证后台消息提示：保存后只存在 1 条 `.wf-system-messages .wflash`，默认 layout 顶部消息为 0，提示位于标题/面包屑下方、统计卡片上方，并使用统一绿色消息条。
- 2026-05-14 浏览器复验源语言配置：AI 翻译页不再渲染 `w:i18n:language:select` 源语言选择器；DOM 中 `select[name="source_locale"]` 数量为 0，隐藏字段 `input[name="source_locale"]` 数量为 1，值固定为 `zh_Hans_CN`；可见区域只读展示 `Chinese (Simplified, China)` 和 `zh_Hans_CN`。
- 2026-05-14 浏览器复验后台统一头部：layout 级页面头部数量为 1，标题为 `AI翻译`，面包屑文本为 `系统管理 / 国际化 / AI翻译`；该结果来自 `BackendPageHeaderResolver` 菜单表优先、`menu.xml` 回退解析。
- 2026-05-14 源语言后端防线补齐：`AiTranslationConfig::saveFromPost()` 和 `normalizeConfig()` 均强制 `source_locale=zh_Hans_CN`，历史配置或伪造提交不能改写 AI 翻译源语言。
- 2026-05-14 继续验收补齐：`AiTranslationQueueService` 和后台页面读取队列时兼容数组/模型对象返回；后台“已翻译”统计只统计非空译文。
- 2026-05-14 继续验收补齐：`Weline\Ai\Service\TranslationService` 批量响应解析在数量不匹配时返回空结果，确保外层真正降级逐条翻译，而不是补空字符串掩盖失败。
- 2026-05-14 继续验收补齐：`Weline\Queue` 查询器 `getByBizKey` 改为使用 `fetchArray()` 读取最新队列行，修复 `select()->fetch()` 列表结果导致 `getId()=0`、业务键查询返回 null 的问题。
- 2026-05-14 继续验收补齐：`AiTranslateQueue::execute()` 成功返回追加 `QUEUE_DONE` 标记，匹配 Queue Cron 回收逻辑，避免子进程完成后被回收为 running/error。
- 2026-05-14 静态检查复验：`php -l` 覆盖 AI 服务、I18n Controller/Observer/Queue/Service/Adapter/Taglib、AI 翻译页面模板、后台 layout partial 和全部改动 backend layout，均通过。
- 2026-05-14 注册检查复验：`php bin/w setup:upgrade --route`、`php bin/w queue:collect`、`php bin/w queue:type:listing Weline_I18n`、`php bin/w cron:task:collect` 已执行；队列类型显示 `Weline\I18n\Queue\AiTranslateQueue`，路由列表显示 `/i18n/backend/ai-translation`，Cron 列表未匹配到旧 `i18n_ai_translation` 任务。
- 2026-05-14 配置归一化复验：运行时反射调用 `AiTranslationConfig::normalizeConfig()`，确认伪造 `source_locale=en_US` 会回到 `zh_Hans_CN`，非法 batch size 限制到 `1000`，非法 strategy 回到 `light`，源语言强制 disabled，未安装语言被过滤。
- 2026-05-14 Queue 校验复验：使用内存 Queue 模型验证当前启用语言 `en_US` 可通过 `AiTranslateQueue::validate()`；`batch_size=1001` 返回 false，并写入 `验证失败：batch_size 必须在 1-1000 之间。`。
- 2026-05-14 浏览器交互复验：在独立实例 `ai-test-i18n-continue-20260514150000` 打开 `/i18n/backend/ai-translation`，保存配置后页面仅有 1 个 `.wf-system-messages`，旧顶部消息数为 0，提示包含 `AI翻译配置已保存` 和 `已入队`；源语言 select 数量为 0，隐藏值为 `zh_Hans_CN`。
- 2026-05-14 队列执行复验：浏览器保存产生队列 `1956`，`w_query('queue','getByBizKey')` 可返回该队列；`php bin/w queue:run --id=1956 -f` 执行后状态为 `done`、`pid=0`，结果包含 `QUEUE_DONE`；浏览器刷新后能看到队列号和完成状态。
- 2026-05-14 已安装语言操作边界复验：`pt_BR` 已安装启用但未开启自动 AI 翻译时，自动队列 `validate()` 返回 false 并记录“语言 pt_BR 未开启 AI 自动翻译，自动队列已跳过”；手动队列 `validate()` 返回 true。浏览器点击 `pt_BR` 的“立即翻译”后创建 `#1958 pending`，队列内容包含 `requested_by=manual` 和 `manual=true`；执行 `php bin/w queue:run --id=1958 -f` 后状态为 `done`，结果包含 `QUEUE_DONE`。页面刷新后可见 `#1958 done`，旧 `#1952 error` 不再可见。
- 2026-05-14 词包合并复验：`Weline\Framework\Phrase\Parser` 合并模块词包时不会让 `source=translation` 的未翻译行覆盖已有真实译文，同时保持当前请求模块范围，不退化为全模块扫描，避免跨模块业务文案串味。
- 2026-05-14 英文后台界面复验：在独立实例 `ai-test-i18n-en-ui-20260514151500` 通过后台语言选择器从 `中文（简体，中国）` 切换到 `English (United States)`，目标 URL 为 `/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/CNY/en_US/i18n/backend/ai-translation`；DOM 断言确认标题 `AI Translation`、面包屑 `System Management / Internationalization / AI Translation`、统计卡片 `Dictionary Words`、`AI Enabled Languages`、`Global Status`、基础配置 `Basic Configuration`、语言配置 `Language Configuration`、保存按钮 `Save Configuration And Queue`、立即翻译按钮 `Translate Now` 均为英文，且旧中文核心标签 `AI翻译`、`基础配置`、`保存配置并入队`、`立即翻译` 不再出现在 AI 翻译页面主体中。
