# I18n AI翻译功能说明

## 概述

I18n AI 翻译采用“后台配置 + Queue 自动消费”的模型，不再注册 I18n Cron。后台只负责配置和入队，实际翻译由 `Weline\Queue` 消费 `Weline\I18n\Queue\AiTranslateQueue` 执行。

## 配置入口

- 菜单：`系统配置 / i18n国际化 / AI翻译`
- 路由：`/i18n/backend/ai-translation`
- 存储：`system_config`，模块 `Weline_I18n`，键 `ai_translation`
- 默认值：全局关闭，源语言固定 `zh_Hans_CN`，批量大小 `100`，策略 `light`，自动发布开启
- 每个已安装且启用的目标语言可以单独开启 AI 翻译，源语言自身始终跳过

## 执行链路

```text
后台保存配置 / 手动立即翻译 / 词典新增 / CSV 导入
  -> AiTranslationQueueService 入队
  -> Queue 自动消费 AiTranslateQueue
  -> AiTranslationService 扫描未翻译词
  -> I18nAiTranslationAdapter 批量调用 Weline_Ai::translate
  -> AiTranslationObserver 调用 Weline\Ai\Service\TranslationService
  -> 写入 i18n_locale_dictionary
  -> AiTranslationPublisher 发布语言文件并清理缓存
  -> 若仍有剩余词，继续创建下一批 Queue
```

## 核心类

- `AiTranslationConfig`：读取、保存、归一化配置，过滤未安装或未启用语言。
- `I18nAiTranslationAdapter`：I18n 侧适配器，只暴露批量翻译入口，继续复用 `Weline_Ai::translate`。
- `AiTranslationService`：持续扫描词库，收集未翻译词，调用 AI，校验译文并写入词典。
- `AiTranslationQueueService`：创建翻译队列，使用 `i18n:ai_translation:{locale}` 作为业务去重键。
- `AiTranslateQueue`：Queue 执行器，校验参数、执行批量翻译、必要时创建下一批队列。
- `AiTranslationPublisher`：写入成功后同步运行时语言文件并清理 `i18n`、`phrase` 缓存。

## 翻译规则

- 只翻译后台已开启 AI 翻译、且 `i18n_locale` 已安装启用的目标语言。
- 源语言固定为 `zh_Hans_CN`，后台不提供二次选择。
- 批量选择会持续扫描词库，直到收集到指定数量的未翻译词或词库结束。
- 已存在但译文为空的记录不算已翻译，会继续进入待翻译扫描。
- AI 批量响应优先解析严格 JSON 数组；数量不匹配时回退逐条翻译。
- 空译文、与原文相同、占位符缺失、HTML 标签或模板 token 结构不一致时记录失败，不写入成功译文。

## 自动入队入口

- 后台 AI 翻译配置保存后，为已开启 AI 翻译的目标语言入队。
- 后台 AI 翻译页点击“立即翻译”后，为指定语言强制入队。
- `CollectTranslations` 收集到新基础词条后，为已开启 AI 翻译的语言入队。
- 后台词典 CSV 导入后，为已开启 AI 翻译的语言入队。

## 验证命令

```bash
php -l app/code/Weline/I18n/Controller/Backend/AiTranslation.php
php -l app/code/Weline/I18n/Queue/AiTranslateQueue.php
php -l app/code/Weline/I18n/Service/AiTranslationService.php
php bin/w setup:upgrade --route
php bin/w queue:collect
php bin/w queue:type:listing Weline_I18n
php bin/w cron:task:collect
php bin/w cron:task:listing
```

`cron:task:listing` 不应出现 `i18n_ai_translation` 或 `Weline\I18n\Cron\AiTranslation`。
