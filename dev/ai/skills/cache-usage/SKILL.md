---
name: cache-usage
description: Deprecated compatibility alias for historical cache-usage references.
status: deprecated
---

# Compatibility Alias

缓存相关规范已并入当前配置与权限技能，并补充「默认全维 + Custom 逃逸」契约。

请改读：

- `dev/ai/skills/业务模块工程师-配置缓存与后台权限/SKILL.md`（含「缓存键契约」专节）
- `app/code/Weline/Framework/doc/3-开发/缓存使用指南.md`

## 契约摘要

- 普通 `get/set/remember`：自动注入 `website_code` + `lang` + `currency`（+ area）
- 特殊 `*Custom`：维度 bool 默认 `false`=逃逸；`true` 才启用该维
