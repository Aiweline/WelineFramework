# AI翻译功能 - 快速开始

## 1. 打开配置页

进入后台 `系统配置 / i18n国际化 / AI翻译`。

页面会展示：

- 全局 AI 翻译开关
- 源语言只读展示：`zh_Hans_CN`
- 批量大小，默认 `100`
- 翻译策略，默认 `light`
- 自动发布开关，默认开启
- 已安装且启用语言列表，每个目标语言可单独开启 AI 翻译

## 2. 开启目标语言

勾选全局开关，并为需要自动翻译的目标语言开启 AI 翻译。

保存配置后，系统会为已开启的目标语言创建 Queue。源语言自身不会入队。

## 3. 触发翻译

支持以下触发方式：

- 保存 AI 翻译配置。
- 在 AI 翻译页点击目标语言的“立即翻译”。
- 后台词典新增或采集到新词。
- 后台词典导入 CSV 后触发入队。

## 4. 查看队列

```bash
php bin/w queue:collect
php bin/w queue:type:listing Weline_I18n
```

应能看到：

```text
Weline\I18n\Queue\AiTranslateQueue    I18n AI翻译队列
```

队列内容包含：

- `locale_code`
- `source_locale`
- `batch_size`
- `strategy`
- `publish`
- `force`
- `requested_by`

## 5. 确认不再使用 I18n Cron

```bash
php bin/w cron:task:collect
php bin/w cron:task:listing
```

列表中不应出现 `i18n_ai_translation` 或 `Weline\I18n\Cron\AiTranslation`。

## 6. 结果检查

成功执行后：

- 译文写入 `i18n_locale_dictionary`。
- 自动发布开启时，会刷新运行时语言文件。
- `i18n`、`phrase` 缓存会被清理。
- 前后台 `__()` 和 `<lang>` 应能读取新译文。

## 注意事项

- AI 提供商、模型和密钥继续由 `Weline_Ai` 的 `translation` 场景管理。
- I18n 只负责词典扫描、队列编排、译文校验和发布。
- 如果 AI 批量响应数量不匹配，会自动降级逐条翻译。
- 空译文或结构 token 丢失的译文不会写入成功结果。
