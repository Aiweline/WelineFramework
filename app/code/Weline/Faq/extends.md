# Weline_Faq 扩展点

## FaqTypeProvider

- 路径：`extends/module/Weline_Faq/FaqTypeProvider/`
- 接口：`Weline\Faq\Api\FaqTypeProviderInterface`
- 内置：`ProductFaqTypeProvider`（product）、`SiteFaqTypeProvider`（site）
- 用途：将外部 UUID（offer / product / site）解析为存储用 `entity_id` + `entity_uuid`
