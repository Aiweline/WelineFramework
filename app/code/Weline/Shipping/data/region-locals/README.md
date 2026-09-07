# region-locals（可选离线包）

默认升级/导入**不会**自动灌这些文件。

地区名称翻译约定：

1. 主表已有 `region_name` / `street_name` → 直接作源文（**不要求**先有源语言 Local 行）
2. I18n `LocalModelTranslation` 写入其它 `local_code` 的 Local
3. 前台按当前 locale 读 Local，缺则回退主表

本目录 `*.tsv` 仅供运维手工 `importLocalePack`，不是推荐路径。
