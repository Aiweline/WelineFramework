# 国际化 SEO 能力实施计划

## 目标

默认前端布局只提供通用 `seo::head` hook，不直接拼 SEO 规则；I18n 通过 Framework 的通用 Head Context 契约向前端请求上下文注入国际化数据；SEO 模块只消费该上下文并统一输出符合全球站点要求的 canonical、hreflang、Open Graph locale 和 JSON-LD `inLanguage`。

后端默认主题 head 不引入 `<w:seo>` 或 `<w:geo>` 插槽。后台页面是管理界面，不参与前台国际化 SEO/GEO discovery 输出。

## 架构

```mermaid
flowchart LR
    Theme["Frontend Theme 默认 head 模板<br/><w:seo slot=\"head\"/><br/><w:geo slot=\"head\"/>"]
    SeoTag["Weline_Seo Taglib"]
    Resolver["PageSeoContextResolver<br/>页面 SEO 基础上下文"]
    I18nProvider["Weline_I18n<br/>InternationalSeoProvider"]
    I18nService["InternationalSeoContextService<br/>语言/URL/本地化文案"]
    Renderer["HeadRenderer<br/>HTML/OG/JSON-LD 标准输出"]
    Geo["Weline_Geo<br/>AI discovery feed links"]

    Theme --> SeoTag --> Resolver
    Resolver --> I18nProvider --> I18nService --> Resolver
    Resolver --> Renderer
    Theme --> Geo
```

## 模块边界

- 前端布局：只放置 `seo::head` / `seo::body` / `seo::footer` 通用 hook，不硬编码语言、canonical、hreflang 或 GEO 规则。
- `Weline_I18n`：优先读取当前网站关联语言和默认语言生成 hreflang；取不到网站语言时再回退到全局已安装启用语言。
- `Weline_Backend`：后端默认 head 不放置 SEO/GEO 插槽，避免后台管理页触发前台 SEO/GEO 输出。
- `Weline_I18n`：提供当前语言、启用语言、默认语言、跨语言 URL、可选本地化 SEO 文案覆盖。
- `Weline_Seo`：消费上下文并渲染 `<link rel="alternate" hreflang="...">`、`og:locale`、`og:locale:alternate`、JSON-LD `inLanguage` 和 `availableLanguage`。
- `Weline_Geo`：继续输出 `llms.txt`、`geo-feed.json/xml` 等 discovery 信息，不承担页面语言结构渲染。

## 页面数据契约

页面模块如果需要覆盖某个语言版本的 SEO 信息，只需要把数据放到模板上下文，不需要跨模块调用：

```php
$template->setData('i18n_seo', [
    'en_US' => [
        'title' => 'English title',
        'description' => 'English description',
        'canonical_url' => '/en_US/example',
        'image' => '/media/example-en.jpg',
    ],
]);

$template->setData('i18n_alternates', [
    'zh_Hans_CN' => '/example',
    'en_US' => '/en_US/example',
]);
```

`i18n_alternates` 是显式 URL 覆盖；未提供时 I18n 会基于当前 canonical URL、当前网站关联语言、网站默认语言和现有 URL 前缀规则生成。若当前请求无法解析网站或网站未配置关联语言，才回退到全局已安装启用语言。

## 实施清单

- 在 `Weline_I18n` 增加 `InternationalSeoProvider`，注册到 `Weline_Frontend` 的通用 `HeadContextProvider` 扩展点，并只实现 Framework 契约。
- 在 `Weline_I18n` 增加 `InternationalSeoContextService`，按当前网站语言生成 locale、html locale、OG locale、available languages、hreflang alternates 和本地化 SEO 覆盖。
- 在 `Weline_Seo` 的 `HeadRenderer` 中统一规范化 hreflang，输出 Open Graph locale 与 JSON-LD 语言字段。
- 保持 Theme 默认主题只使用 hook，不向 Theme 写入跨模块 SEO 逻辑。

## 验证点

- 前端默认主题 head 中存在 `<w:seo slot="head"/>` 和 `<w:geo slot="head"/>`。
- 后端默认主题 head 中不存在 `<w:seo>` 或 `<w:geo>`。
- 页面 head 输出 canonical、当前网站每个关联语言的 hreflang、`x-default`。
- 页面 head 输出 `og:locale` 与其它语言的 `og:locale:alternate`。
- JSON-LD `WebPage` 节点包含 `inLanguage`，`WebSite` 节点包含 `availableLanguage`。
- 显式 `i18n_seo` / `i18n_alternates` 能覆盖自动生成结果。
