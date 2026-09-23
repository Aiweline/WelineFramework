# 后端 — seed API / 清理

updated: 2026-09-23  
result: closed

## 改动

- `MailTemplateDefaultLocales`：zh 文件路径；它语内联 subject/body
- `MailTemplateSeedCopyCatalog`：forSlug 支持 en；补 cart/checkout/welcome/subscribe 渲染；materializeFiles no-op
- `Setup/Upgrade`、`Controller/Backend/Template`：去掉 materialize 调用
- 删除 1803 非 zh 实体；根 `.gitignore` 防护
- 模块版本 `1.4.61`

notify_pm: true  
@项目经理：本席已交付，请检查并更新 SESSION
