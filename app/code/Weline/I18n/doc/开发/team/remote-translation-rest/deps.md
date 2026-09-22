# deps — remote-translation-rest

```text
align-freeze (UC+contracts) 
  → [API] Websites RemoteTranslationCatalog Rest (复用 getWebsiteList/getWebsiteLanguageCodes)
  → [API] I18n RemoteDictionaryAssistService + CollectJob (+ DictionaryCollect enqueueAi=false)
  → [API] I18n Query ops + RemoteTranslation Rest
  → framework:compile + setup:upgrade --route (Websites+I18n)
  → 契约 UT / 本机冒烟
  → 查询/i18n/安全/API 合规复审 → 汇审
```

并行：Websites Rest 与 I18n Service 文件不重叠，可同波；I18n Rest 依赖 Service+Query ops。

后端 Service 缺口判定：**closed / N/A** — 在 `DictionaryCollectService::collect` 增加 `$enqueueAiTranslation` 默认 true；远程路径传 false。无需独立后端席施工。
