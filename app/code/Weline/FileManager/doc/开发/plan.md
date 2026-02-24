# FileManager 模块：媒体选择与分辨率配置

本模块子计划，总计划见 [.cursor/plans/媒体选择与分辨率配置_676aad06.plan.md](../../../../.cursor/plans/媒体选择与分辨率配置_676aad06.plan.md)。

## 目标

- FileManagerConnector 标签支持分辨率相关属性，并传递给 getConnector，供选择器展示建议尺寸或校验。

## 已完成

- [x] FileManagerConnector::attr() 增加 recommend_width、recommend_height；可选 min_width、min_height、max_width、max_height。
- [x] callback 中将上述属性写入 $attributes 并传给 $fileManager->getConnector($attributes)。
- [x] document() 中补充上述参数说明。

## 涉及文件

- `app/code/Weline/FileManager/Taglib/FileManagerConnector.php`
