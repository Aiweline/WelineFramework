# API 文档收集后

事件名：`Weline_DeveloperWorkspace::api_doc_collect_after`

DeveloperWorkspace 在 `ApiDocCollector::generateAll()` 中完成基础 API 文档生成后触发该事件。监听者可以追加 SDK、协议或扩展接口文档，让内容进入 `/dev/tool/docs/api` 管理界面和 API 文档自动导入流程。

## 数据结构

- `apis`：按模块分组的 API 文档数组，监听者追加后必须写回。
- `force`：是否强制重新生成。
- `source`：触发来源，默认 `developer_workspace`。

## 使用约束

- 不要直接修改 `Weline_Api\Service\ApiDocService`。
- 监听者只追加自己模块负责的文档分组。
- 追加数据应保持 API 文档通用结构：`module`、`version`、`class`、`method`、`route`、`document`、`parameters`、`responses`、`example`。
