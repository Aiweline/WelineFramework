# Spec: controller-extra-fpc

status: ready-for-plan  
work_kind: feature  
clarify_status: ready-for-plan

## 用户故事

作为平台开发者，我希望用 `@Extra type=fpc` 声明可缓存页，并在业务保存时只调用 `w_changed`，由框架 Extends 自动补材料并失效 FPC/CDN，而不手写清缓存。

## EARS

1. WHEN 控制器声明 `@Extra type=fpc` 且 type 已注册 THEN 升级收集 SHALL 写入可读侧车策略。  
2. WHEN 业务在同事务调用 `w_changed` 且 ChangedType 已注册 THEN 系统 SHALL Enricher→Recipe→Capability，并仍 dispatch 给 Seo 等。  
3. WHEN Enricher 后仍缺 purge_fpc_urls 所需 urls THEN 系统 SHALL fail-fast。  
4. IF 请求为预览/draft 配方 THEN SHALL NOT purge_fpc_all。  
5. WHEN theme+publish THEN SHALL purge_fpc_all + bump（白名单 Capability）。

## UC-01 商品投影变更

1. Service 写投影成功并 `w_changed(product_search_projection, …)`。  
2. Enricher 产出 urls/namespaces。  
3. afterCommit 删矩阵 FPC 键并 CDN purge；sync bump ns。  
4. Seo 仍经事件入队 URL 提交。

## UC-02 未知 Extra type

升级收集失败，错误列出已注册 type。

## 非目标

Seo 迁 Capability；业务 Provider 调 FPC；兼容双跑。
