<?php

declare(strict_types=1);

namespace Amayum\Website\Service;

/**
 * 三篇指南的元数据、FAQ 与相关阅读。
 *
 * 正文由前端模板持有；本目录只提供 SEO/Article/FAQPage/相关阅读所需的稳定事实。
 */
final class GuideCatalog
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $guides = [];
        foreach ($this->definitions() as $definition) {
            $slug = (string)$definition['slug'];
            $definition['reading_minutes'] = $this->readingMinutes($slug);
            $guides[$slug] = $definition;
        }

        return $guides;
    }

    /** @return list<string> */
    public function slugs(): array
    {
        return \array_keys($this->definitions());
    }

    /** @return array<string, mixed>|null */
    public function find(string $slug): ?array
    {
        $slug = \strtolower(\trim($slug));
        if ($slug === '' || !isset($this->definitions()[$slug])) {
            return null;
        }

        $guide = $this->definitions()[$slug];
        $guide['reading_minutes'] = $this->readingMinutes($slug);

        return $guide;
    }

    /** @return list<array{name: string, url: string, description: string, datePublished: string}> */
    public function itemList(string $siteUrl = 'https://amayum.com'): array
    {
        $items = [];
        foreach ($this->definitions() as $guide) {
            $items[] = [
                'name' => (string)$guide['title'],
                'url' => \rtrim($siteUrl, '/') . (string)$guide['canonical_path'],
                'description' => (string)$guide['meta_description'],
                'datePublished' => (string)$guide['date_published'],
            ];
        }

        return $items;
    }

    /**
     * 依据实际正文模板估算阅读时长；正文尚未落地时返回 null，模板应省略该字段。
     */
    public function readingMinutes(string $slug): ?int
    {
        $files = [
            \dirname(__DIR__) . '/view/templates/frontend/journal/partials/' . $slug . '.phtml',
            \dirname(__DIR__) . '/view/templates/frontend/journal/view.phtml',
        ];
        $text = '';
        foreach ($files as $file) {
            if (!\is_file($file)) {
                continue;
            }
            $raw = (string)\file_get_contents($file);
            $raw = (string)\preg_replace('/<\?php.*?\?>/s', ' ', $raw);
            $raw = \strip_tags($raw);
            $text .= ' ' . $raw;
        }
        $text = \trim($text);
        if ($text === '') {
            return null;
        }

        $han = \preg_match_all('/[\x{4e00}-\x{9fff}]/u', $text);
        $words = \preg_match_all('/[A-Za-z0-9]+/', $text);
        $minutes = (int)\ceil(max(0, (int)$han) / 400 + max(0, (int)$words) / 220);

        return \max(1, $minutes);
    }

    /** @return array<string, array<string, mixed>> */
    private function definitions(): array
    {
        return [
            'build-platform' => [
                'slug' => 'build-platform',
                'title' => '独立站建站平台怎么选：先看总成本，再看可迁移性。',
                'meta_title' => '独立站建站平台怎么选：SaaS、开源与源码框架 | Amayum',
                'meta_description' => '从总成本、代码所有权、迁移、多站点、多语言、SEO 控制与运维能力七个维度，比较 SaaS、开源插件与源码框架。价格标注 2026-10-26。',
                'dek' => '选平台不是选模板。你真正要决定的是：代码、数据与运行时归谁，以及三年后能不能迁移。',
                'canonical_path' => '/journal/build-platform',
                'date_published' => '2026-10-26',
                'date_modified' => '2026-10-27',
                'author' => 'Amayum',
                'byline' => 'Amayum Ops',
                'in_language' => 'zh-CN',
                'related' => ['shopify-alternative', 'independent-store-seo'],
                'faqs' => [
                    [
                        'question' => '独立站一定要自建吗？',
                        'answer' => '不一定。渠道独立可以在 SaaS 上实现；系统自有才需要自建/源码框架。',
                    ],
                    [
                        'question' => '源码框架是不是更便宜？',
                        'answer' => '不一定。许可可能更低，但托管、开发、维护与人力要算进去。',
                    ],
                    [
                        'question' => 'Weline 适合谁？',
                        'answer' => '有技术能力、要源码自有、要交易底座、要 SEO/多语言控制、能接受实施与运维责任的团队。',
                    ],
                    [
                        'question' => '能不能从 Shopify 迁移？',
                        'answer' => '可以评估，但没有“一键迁移”承诺；需要 URL 映射、数据、重定向与验收。',
                    ],
                ],
            ],
            'independent-store-seo' => [
                'slug' => 'independent-store-seo',
                'title' => '独立站 SEO 为什么没效果：先排查抓取与索引，再谈内容。',
                'meta_title' => '独立站 SEO 为什么没效果：技术排查清单 | Amayum',
                'meta_description' => '从抓取、索引、结构化数据、Core Web Vitals 到内容与转化，按顺序排查独立站 SEO。引用 Google Search Central，标注日期；不承诺排名。',
                'dek' => '大多数“SEO 没效果”不是内容不够，而是页面没被正确抓取、索引或理解。下面是一个可执行的排查顺序。',
                'canonical_path' => '/journal/independent-store-seo',
                'date_published' => '2026-10-26',
                'date_modified' => '2026-10-27',
                'author' => 'Amayum',
                'byline' => 'Amayum Ops',
                'in_language' => 'zh-CN',
                'related' => ['build-platform', 'shopify-alternative'],
                'faqs' => [
                    [
                        'question' => '独立站 SEO 多久见效？',
                        'answer' => '没有统一答案；取决于竞争、内容、外链与执行。不要相信固定天数。',
                    ],
                    [
                        'question' => 'hreflang 能提升排名吗？',
                        'answer' => 'Google 不把 hreflang 作为排名信号；它用于语言/地区定位。',
                    ],
                    [
                        'question' => 'canonical 是强制指令吗？',
                        'answer' => '不是，是强信号/提示。',
                    ],
                    [
                        'question' => 'sitemap 能保证收录吗？',
                        'answer' => '不能。',
                    ],
                    [
                        'question' => 'SSR 能保证排名吗？',
                        'answer' => '不能；它提高抓取/渲染可靠性。',
                    ],
                ],
            ],
            'shopify-alternative' => [
                'slug' => 'shopify-alternative',
                'title' => 'Shopify 替代方案怎么选：把订阅、交易、应用、托管与迁移摊开算。',
                'meta_title' => 'Shopify 替代方案：总成本、迁移与代码所有权 | Amayum',
                'meta_description' => '比较 Shopify、WooCommerce、Medusa/Saleor 与源码自有框架：订阅、交易费、应用、托管、迁移与代码所有权。价格标注 2026-10-26；不贬低竞品。',
                'dek' => '“替代 Shopify”不是一个产品，而是一组取舍：你要保留什么、放弃什么、愿意维护什么。',
                'canonical_path' => '/journal/shopify-alternative',
                'date_published' => '2026-10-26',
                'date_modified' => '2026-10-27',
                'author' => 'Amayum',
                'byline' => 'Amayum Ops',
                'in_language' => 'zh-CN',
                'related' => ['build-platform', 'independent-store-seo'],
                'faqs' => [
                    [
                        'question' => '迁移会掉排名吗？',
                        'answer' => '可能；目标是降低风险，不是零损失。',
                    ],
                    [
                        'question' => 'Weline 能导入 Shopify 数据吗？',
                        'answer' => '需要按项目评估；没有公开的一键迁移承诺。',
                    ],
                    [
                        'question' => 'WooCommerce 不是更便宜吗？',
                        'answer' => '许可证便宜，但托管、插件、维护与人力要算进去。',
                    ],
                    [
                        'question' => '你们能保证比 Shopify 快吗？',
                        'answer' => '不能；没有同条件基准就不下结论。',
                    ],
                    [
                        'question' => '多久能上线？',
                        'answer' => '单站首版通常按范围与内容准备度评估；不要在没有范围的情况下承诺固定周数。',
                    ],
                ],
            ],
        ];
    }
}
