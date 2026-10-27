<?php

declare(strict_types=1);

namespace Amayum\Website\Service;

/**
 * 每页 SEO 事实：title/description/canonical/robots/OG/JSON-LD 事实。
 *
 * 不手写整段 JSON-LD；由 Weline_Seo HeadRenderer 依据这些事实输出。
 */
final class SeoFactsService
{
    public function __construct(
        private readonly SiteProfileService $siteProfile,
        private readonly GuideCatalog $guides,
        private readonly CaseStudyCatalog $caseStudies,
    ) {
    }

    /** @return array<string, mixed> */
    public function homepage(string $websiteCode = ''): array
    {
        $faqs = [
            ['question' => 'WelineFramework 是 Shopify 的替代品吗？', 'answer' => '它解决的是“源码自有 + 交易底座”的问题，不是“零运维开店”。如果你要的是最快上线、最少运维，Shopify/Shoplazza 更合适；如果你要代码、数据与运行时归自己，且有人维护，Weline 才合适。'],
            ['question' => '支持哪些支付？', 'answer' => '仓库内置 Stripe、PayPal 与 fake_card（测试）。其他通道通过 Provider SPI 接入；28 个支付文档是配置指南，不是 28 个已实现网关。'],
            ['question' => '能支持多少语言？', 'answer' => '框架附带 682 个 locale 目录（642 种语言、249 个国家/地区），支持 locale 激活、URL 前缀、hreflang 与语言切换。实际能上线多少，取决于翻译、内容质检与市场运营，不是目录数字。'],
            ['question' => 'WLS 能上生产吗？', 'answer' => 'WLS 已在本机/受控环境验证，官方状态是 LOCAL_RUNTIME_VERIFIED / GA_BLOCKED_EXTERNAL；FPM 是一等部署路径。生产选哪条，按你的验收标准与运维能力决定。'],
            ['question' => '主题是零继承吗？', 'answer' => '不是。主题是父主题链 + 同键覆盖；设计主题不复制默认实现，但父主题链存在。'],
            ['question' => '能保证 SEO 排名或迁移不掉排名吗？', 'answer' => '不能。框架提供 SSR、canonical、hreflang、结构化数据、sitemap 与重定向能力；排名与迁移结果取决于内容、外链、竞争与执行。'],
            ['question' => '三个站点是你们的产品吗？', 'answer' => '不是。它们是运行中的例子；产品是 WelineFramework。'],
            ['question' => '能自动算税吗？', 'answer' => '税费引擎支持辖区规则与快照；生产默认关闭，开启需要授权与迁移证据；申报请找当地顾问。'],
            ['question' => '有 RMA / 退货门户吗？', 'answer' => '没有独立 RMA/退货门户；订单退款与退货入库有账本。'],
        ];

        return $this->page(
            '/',
            'homepage',
            'WelineFramework — 源码自有的跨境电商框架 | Amayum',
            'WelineFramework 是 PHP 8.4+ 的模块化电商框架：商品、订单、支付、税费、多语言与 SEO 是系统能力；MIT 源码、主题独立、WLS 或 FPM 部署。看能力矩阵与三个运行中的例子。',
            $websiteCode,
            ['faqs' => $this->faqList($faqs)],
        );
    }

    /** @return array<string, mixed> */
    public function commerce(string $websiteCode = ''): array
    {
        $faqs = [
            ['question' => 'WLS 和 FPM 怎么选？', 'answer' => '要长驻 Worker、热重载与多实例，选 WLS，但先做生产验收；要成熟通用路径，选 FPM。两条都是一等路径。'],
            ['question' => '127 个模块都是电商功能吗？', 'answer' => '不是。包含基础设施、AI、后台与开发工具；电商能力看交易内核表。'],
            ['question' => '主题能完全独立吗？', 'answer' => '每个站点注册自己的设计主题，设计主题同键覆盖默认主题；父主题链存在，不是零继承。'],
            ['question' => '能接哪些支付？', 'answer' => '内置 Stripe、PayPal、fake_card；其他按 Provider SPI 实现。'],
            ['question' => '能自动算税吗？', 'answer' => '税费引擎支持辖区规则与快照；生产默认关闭，开启需要授权与迁移证据；申报请找当地顾问。'],
            ['question' => '有 RMA 吗？', 'answer' => '没有独立 RMA/退货门户；订单退款与退货入库有账本。'],
        ];

        return $this->page(
            '/commerce',
            'commerce',
            '框架能力 — WelineFramework | Amayum',
            'WelineFramework 能力矩阵：PHP 8.4+、WLS/FPM、商品/订单/支付/税费/库存、多站点、i18n、SEO/GEO、ACL 与部署。每项标注验证状态与边界。',
            $websiteCode,
            [
                'faqs' => $this->faqList($faqs),
                'breadcrumbs' => $this->crumbs([
                    ['name' => '首页', 'url' => '/'],
                    ['name' => '框架能力'],
                ]),
            ],
        );
    }

    /** @return array<string, mixed> */
    public function caseStudies(string $websiteCode = ''): array
    {
        $page = $this->page(
            '/case-studies',
            'case_studies',
            '运行中的例子 — WelineFramework | Amayum',
            '三个运行在 WelineFramework 上的站点：長安漢服、PlayingInChina、DaoCharms。写清楚它们证明什么、不证明什么；不是产品本身。',
            $websiteCode,
            [
                'breadcrumbs' => $this->crumbs([
                    ['name' => '首页', 'url' => '/'],
                    ['name' => '运行中的例子'],
                ]),
            ],
        );
        $page['schema_nodes'] = [
            $this->itemListSchema(
                $this->caseStudies->itemList(),
                '运行中的例子',
                (string)$page['canonical_url'],
            ),
        ];

        return $page;
    }

    /** @return array<string, mixed> */
    public function journal(string $websiteCode = ''): array
    {
        $profile = $this->siteProfile->profile($websiteCode);
        $page = $this->page(
            '/journal',
            'journal',
            '独立站指南 — 建站、SEO 与迁移 | Amayum',
            '独立站建站平台怎么选、独立站 SEO 怎么排查、Shopify 替代方案怎么算总成本。每篇只解决一个判断，标注来源与日期。',
            $websiteCode,
            [
                'breadcrumbs' => $this->crumbs([
                    ['name' => '首页', 'url' => '/'],
                    ['name' => '指南'],
                ]),
            ],
        );
        $page['schema_nodes'] = [
            $this->itemListSchema(
                $this->guides->itemList((string)($profile['site_url'] ?? 'https://amayum.com')),
                '独立站指南',
                (string)$page['canonical_url'],
            ),
        ];

        return $page;
    }

    /** @return array<string, mixed> */
    public function article(string $slug, string $websiteCode = ''): array
    {
        $guide = $this->guides->find($slug);
        if ($guide === null) {
            return [];
        }

        $profile = $this->siteProfile->profile($websiteCode);
        $siteUrl = (string)($profile['site_url'] ?? 'https://amayum.com');
        $canonical = $this->canonical((string)$guide['canonical_path'], $websiteCode);
        $image = $this->siteProfile->absoluteAsset((string)($profile['og_image'] ?? ''), $siteUrl);
        $organization = $this->siteProfile->organization($websiteCode);
        $author = [
            '@type' => 'Organization',
            'name' => (string)($guide['author'] ?? 'Amayum'),
            'url' => $siteUrl,
        ];

        $page = $this->page(
            (string)$guide['canonical_path'],
            'article',
            (string)$guide['meta_title'],
            (string)$guide['meta_description'],
            $websiteCode,
            [
                'faqs' => $this->faqList((array)$guide['faqs']),
                'breadcrumbs' => $this->crumbs([
                    ['name' => '首页', 'url' => '/'],
                    ['name' => '指南', 'url' => '/journal'],
                    ['name' => (string)$guide['title']],
                ]),
                'article' => [
                    'headline' => (string)$guide['title'],
                    'description' => (string)$guide['meta_description'],
                    'datePublished' => (string)$guide['date_published'],
                    'dateModified' => (string)$guide['date_modified'],
                    'author' => $author,
                    'publisher' => $organization,
                    'inLanguage' => (string)($guide['in_language'] ?? 'zh-CN'),
                    'articleSection' => '指南',
                    'image' => $image !== '' ? [$image] : [],
                ],
            ],
        );
        $page['og']['type'] = 'article';
        $page['guide'] = $guide;

        return $page;
    }

    /** @return array<string, mixed> */
    public function contact(string $websiteCode = ''): array
    {
        $faqs = [
            ['question' => '诊断收费吗？', 'answer' => '30 分钟初诊免费；深度诊断（TCO 表 + 迁移风险清单 + 90 天路线）按项目报价。'],
            ['question' => '你们会先出方案吗？', 'answer' => '不会在没有范围的情况下出完整方案；先对齐业务与约束。'],
            ['question' => '能保证上线时间吗？', 'answer' => '按确认范围与里程碑；不按“尽快”承诺。'],
            ['question' => '能只做主题吗？', 'answer' => '可以评估；但如果交易链路/SEO 基础不满足，单做主题不会解决问题。'],
            ['question' => '能接现有站点迁移吗？', 'answer' => '可以评估；需要 URL 映射、数据、重定向与验收。'],
        ];

        $profile = $this->siteProfile->profile($websiteCode);
        $siteUrl = (string)($profile['site_url'] ?? 'https://amayum.com');
        $email = (string)($profile['contact_email'] ?? 'hello@amayum.com');
        $page = $this->page(
            '/diagnosis',
            'contact',
            '预约架构诊断 — WelineFramework | Amayum',
            '30 分钟架构诊断：判断你的业务适不适合源码自有路线，第一版做什么，哪些现在不能承诺。提交市场、品类、现有栈与时间计划。',
            $websiteCode,
            [
                'faqs' => $this->faqList($faqs),
                'breadcrumbs' => $this->crumbs([
                    ['name' => '首页', 'url' => '/'],
                    ['name' => '预约架构诊断'],
                ]),
            ],
        );
        $page['schema_nodes'] = [
            [
                '@type' => 'Organization',
                '@id' => \rtrim($siteUrl, '/') . '/#organization-contact',
                'name' => (string)($profile['brand_name'] ?? 'Amayum'),
                'url' => $siteUrl,
                'email' => $email,
                'contactPoint' => [
                    '@type' => 'ContactPoint',
                    'contactType' => 'sales',
                    'email' => $email,
                    'availableLanguage' => ['zh-CN'],
                ],
            ],
        ];

        return $page;
    }

    /** @return array<string, mixed> */
    public function legal(string $websiteCode = ''): array
    {
        return $this->page(
            '/legal',
            'legal',
            '隐私、条款与许可 — Amayum',
            'Amayum 的隐私说明、站点使用条款与 WelineFramework 的 MIT 许可说明。',
            $websiteCode,
        );
    }

    /** @return array<string, mixed> */
    public function sitemap(string $websiteCode = ''): array
    {
        return $this->page(
            '/sitemap',
            'sitemap_page',
            '站点地图 — WelineFramework | Amayum',
            'Amayum 站点地图：框架能力、运行中的例子、独立站指南、联系与法务。',
            $websiteCode,
        );
    }

    /** @return array<string, mixed>|null */
    public function forPath(string $path, string $websiteCode = ''): ?array
    {
        $path = '/' . \trim($path, '/');
        if ($path === '/') {
            return $this->homepage($websiteCode);
        }

        return match ($path) {
            '/commerce' => $this->commerce($websiteCode),
            '/case-studies' => $this->caseStudies($websiteCode),
            '/journal' => $this->journal($websiteCode),
            '/diagnosis' => $this->contact($websiteCode),
            '/legal' => $this->legal($websiteCode),
            '/sitemap' => $this->sitemap($websiteCode),
            default => \preg_match('#^/journal/([a-z0-9-]+)$#D', $path, $matches) === 1
                ? $this->article((string)$matches[1], $websiteCode)
                : null,
        };
    }

    public function canonical(string $path, string $websiteCode = ''): string
    {
        $profile = $this->siteProfile->profile($websiteCode);
        $siteUrl = \rtrim((string)($profile['site_url'] ?? 'https://amayum.com'), '/');
        $path = '/' . \trim($path, '/');

        return $path === '/' ? $siteUrl . '/' : $siteUrl . $path;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function page(
        string $path,
        string $pageType,
        string $title,
        string $description,
        string $websiteCode,
        array $extra = [],
    ): array {
        $profile = $this->siteProfile->profile($websiteCode);
        $siteUrl = (string)($profile['site_url'] ?? 'https://amayum.com');
        $canonical = $this->canonical($path, $websiteCode);
        $image = $this->siteProfile->absoluteAsset((string)($profile['og_image'] ?? ''), $siteUrl);
        $brand = (string)($profile['brand_name'] ?? 'Amayum');

        return \array_merge([
            'page_type' => $pageType,
            'title' => $title,
            'description' => $description,
            'canonical_url' => $canonical,
            'canonical' => $canonical,
            'robots' => 'index,follow',
            'image' => $image,
            'image_alt' => $brand . ' — WelineFramework',
            'site_name' => $brand,
            'organization' => $this->siteProfile->organization($websiteCode),
            'og_locale' => 'zh_CN',
            'html_locale' => 'zh-CN',
            'locale' => 'zh_CN',
            'site_search_enabled' => false,
            'og' => [
                'type' => 'website',
                'site_name' => $brand,
                'title' => $title,
                'description' => $description,
                'url' => $canonical,
                'image' => $image,
                'locale' => 'zh_CN',
            ],
            'jsonLd' => [],
        ], $extra);
    }

    /**
     * @param list<array{question: string, answer: string}> $faqs
     * @return list<array{question: string, answer: string}>
     */
    private function faqList(array $faqs): array
    {
        $normalized = [];
        foreach ($faqs as $faq) {
            $question = \trim((string)($faq['question'] ?? ''));
            $answer = \trim((string)($faq['answer'] ?? ''));
            if ($question !== '' && $answer !== '') {
                $normalized[] = ['question' => $question, 'answer' => $answer];
            }
        }

        return $normalized;
    }

    /**
     * @param list<array{name: string, url?: string}> $items
     * @return list<array{name: string, url: string}>
     */
    private function crumbs(array $items): array
    {
        $crumbs = [];
        foreach ($items as $item) {
            $name = \trim((string)($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $crumbs[] = [
                'name' => $name,
                'url' => \trim((string)($item['url'] ?? '')),
            ];
        }

        return $crumbs;
    }

    /**
     * @param list<array{name: string, url: string, description?: string}> $items
     * @return array<string, mixed>
     */
    private function itemListSchema(array $items, string $name, string $canonical): array
    {
        $elements = [];
        foreach ($items as $index => $item) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => (string)$item['name'],
                'url' => (string)$item['url'],
            ];
        }

        return [
            '@type' => 'ItemList',
            '@id' => $canonical . '#itemlist',
            'name' => $name,
            'numberOfItems' => \count($elements),
            'itemListElement' => $elements,
        ];
    }
}
