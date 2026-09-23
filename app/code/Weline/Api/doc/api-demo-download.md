# API Demo 下载协助（Agent 指针）

`Weline_Api` 只负责：探测模块 `source/api-demo/`、投影下载 URL、realpath 卡死、zip。

- 入口：`GET /api/api-demo/download?module={Vendor_Module}&demo={demo_id}&lang=php|js`
- Service：`Service/ApiDemoPackageService.php`
- 目录约定：`Weline_I18n/doc/开发/team/remote-translation-sdk-demo/meetings/架构-demo目录约定.md`

## 首例验收（远程翻译）

业务内容与 Agent 操作步骤在 **I18n**，不要在本模块重复实现：

- 说明：`app/code/Weline/I18n/doc/远程翻译API-Demo下载与验收.md`
- 技能：`app/code/Weline/I18n/doc/ai/skills/remote-translation-api-demo/SKILL.md`
- 触发词：下载 demo / 测远程翻译 / `i18n_remote_translation` / `WELINE_REMOTE_TYPE`

Agent 应：`prepare_project` → `resolve_skill`/`get_skill(remote-translation-api-demo)` 或宿主 Read 上列 SKILL + 说明全文。
