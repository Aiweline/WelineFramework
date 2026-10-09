# CLI 短语语言选项

事件：`Weline_Framework_Phrase::cli_language_options`

## 用途

收集 CLI 可选 Phrase 语种，供 `php bin/w cli:language` 展示与校验。

## 流程

1. Framework `CliLanguageCatalog` 扫描活跃模块 `i18n/*.csv` 与 `generated/language/*.php`
2. dispatch 本事件；I18n 等观察者可追加 `{code,label,source}`
3. Framework 按 code 去重，`zh_Hans_CN` / `en_US` 置顶后返回

## 配置

写入 `app/etc/env.php` 键 `cli_language`。CLI 解析顺序：`cli_language` → `system.lang` → `zh_Hans_CN`。
