# I18n AI翻译功能说明

## 概述

I18n AI 翻译采用“后台配置 + Cron 入队 + Queue 自动消费”的模型。目标语言以**多网站语言并集**为准：任一网站启用的语言（含 `default_language`）都会自动安装并强制纳入 AI 翻译，避免「网站新增了语言但未翻译」。

## 配置入口

- 菜单：`系统配置 / i18n国际化 / AI翻译`
- 路由：`/i18n/backend/ai-translation`
- 存储：`system_config`，模块 `Weline_I18n`，键 `ai_translation`
- 默认值：全局开启，源语言固定 `zh_Hans_CN`，批量大小 `100`，策略 `light`，自动发布开启
- **网站语言并集**：后台列表中标记为「网站语言并集，强制翻译」的目标语言不可关闭；从全部网站移除后标记「已不在网站并集，跳过翻译」，cron/入队不再处理该语种
- 有 `Weline_Websites` 时，翻译目标**仅**跟随多网站语言并集（加语言必译、减语言必跳过）
- 无 Websites 模块时，回退为已安装激活语种的手动开关

## 执行链路

```text
网站语言变更（setWebsiteLanguages / ensureAssigned）或 Cron */5
  -> WebsiteLocaleTranslationSync 安装/激活并集语言并持久化强制开启
  -> AiTranslationQueueService 入队
    -> Queue 自动消费 AiTranslateQueue
    -> AiTranslationService 扫描未翻译词
    -> I18nAiTranslationAdapter 发布 Weline_I18n::machine_translate
    -> Weline_Ai TranslationRequestObserver / TranslationService（电商后台 UI 质量提示词）
    -> 写入 i18n_locale_dictionary
    -> AiTranslationPublisher 发布语言文件并清理缓存
```

## 核心类

- `AiTranslationConfig`：读取、保存、归一化配置；合并已安装激活语言与多网站语言并集；网站并集强制 `enabled=true`。
- `WebsiteLocaleTranslationSync`：网站语言变更与 cron 自愈入口（安装 locale + 入队）。
- `I18nAiTranslationAdapter`：I18n 侧适配器，只发布中立事件，不依赖 AI 内部类。
- `AiTranslationService`：持续扫描词库，收集未翻译词，调用 AI，校验译文并写入词典。
- `AiTranslationQueueService`：创建翻译队列，使用 `i18n:ai_translation:{locale}` 作为业务去重键。
- `AiTranslateQueue`：Queue 执行器，校验参数、执行批量翻译、必要时创建下一批队列。
- `AiTranslationPublisher`：写入成功后同步运行时语言文件并清理 `i18n`、`phrase` 缓存。
- `Weline\Ai\Service\TranslationService` / `TranslationAdapter`：电商/后台 UI 向提示词（JSON 数组、术语偏好、禁止原文回显）。

## 翻译规则

- AI 开启时，多网站语言并集（减去源语言）必须翻译；从并集移除的语言立即跳过，不再入队。
- cron 会先自愈安装当前并集，再按 `getEnabledLocaleCodes()` 入队（已移除语种不在列表中）。
- 源语言固定为 `zh_Hans_CN`，后台不提供二次选择。
- 批量选择会持续扫描词库，直到收集到指定数量的未翻译词或词库结束。
- 已存在但译文为空的记录不算已翻译，会继续进入待翻译扫描。
- AI 批量响应优先解析严格 JSON 数组；数量不匹配时回退逐条翻译。
- 空译文、与原文相同、占位符缺失、HTML 标签或模板 token 结构不一致时记录失败，不写入成功译文。
- 提示词偏好简洁后台用语（如 `advanced maintenance`、`SKU identity`），避免营销口吻的 `premium`。

## 自动入队入口

- 网站语言赋值 / 保存网站语言后，为并集目标语言入队。
- Cron `i18n_ai_translation`（`*/5`）同步并集后入队。
- 后台 AI 翻译配置保存后 / 「立即翻译」手动入队。
- `CollectTranslations`、词典 CSV 导入等既有入口仍按已启用语言入队。

## 验证命令

```bash
php -l app/code/Weline/I18n/Service/AiTranslationConfig.php
php -l app/code/Weline/I18n/Service/WebsiteLocaleTranslationSync.php
php -l app/code/Weline/Ai/Service/TranslationService.php
php bin/w cron:task:collect
php bin/w cron:task:run i18n_ai_translation -f
php bin/w ai:translate --locale=en_US --limit=5
```
