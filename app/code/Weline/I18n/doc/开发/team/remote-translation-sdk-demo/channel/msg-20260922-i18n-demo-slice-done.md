# Team:I18n: → Team:项目经理: — remote-translation-sdk-demo 切片完成

- 席位：`Team:I18n:`
- 日期：2026-09-22
- 状态：**done**
- `notify_pm`: **true**

## 交付摘要

| 项 | 结果 |
|----|------|
| descriptor | `i18n_remote_translation` 顶层 `'demo'=>true`；四 op `frontend=true`，`external=false`，`auth=backend` + 既有 ACL |
| 可下载包 | `app/code/Weline/I18n/source/api-demo/i18n_remote_translation/{README.md,php/,js/}` |
| api.demo id | `remote-translation-assist-demo` |
| event | `Weline_DeveloperWorkspace::api_doc_collect_after` → `Weline\I18n\Observer\ApiDocDemoObserver` |
| 版本 | I18n `1.0.80` → `1.0.81` |
| 未改 | `Weline_Api` 下载端点；`pub/source`；BinQuery |

## 建议唤醒

- **Team:API:**（若尚未完成）`ApiDemoPackageService` 探测 `source/api-demo` + download zip
- **Team:测试:** 并块有则显；zip 仅该树；descriptor/demo 契约
