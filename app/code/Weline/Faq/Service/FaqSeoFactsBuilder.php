<?php

declare(strict_types=1);

namespace Weline\Faq\Service;

use Weline\Theme\Helper\WidgetI18n;

final class FaqSeoFactsBuilder
{
    public function __construct(
        private readonly FaqHubContent $hub = new FaqHubContent(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildHubProfile(string $canonical = '/faq'): array
    {
        return [
            'page_type' => 'faq',
            'title' => WidgetI18n::label('帮助中心 | 订单物流退换与支付指南'),
            'description' => WidgetI18n::label('查找订单进度、物流配送、退换货、支付发票与账户问题的自助指南，快速解决汉服购物常见疑问，支持中英文浏览。'),
            'canonical_url' => $canonical,
            'robots' => 'index,follow',
            // Share card for FAQ hub; HeadRenderer emits og:image / twitter:image when set.
            'image' => '/pub/media/websites/default/default/brand/changan-logo-20260903.png',
            'image_alt' => WidgetI18n::label('帮助中心分享预览图'),
            'faqs' => $this->hub->seoFaqs(),
            'breadcrumbs' => [
                ['name' => WidgetI18n::label('首页'), 'url' => '/'],
                ['name' => WidgetI18n::label('帮助中心'), 'url' => $canonical],
            ],
            'sitemap' => [
                'include' => true,
                'changefreq' => 'weekly',
                'priority' => '0.6',
            ],
            'geo' => ['include' => true],
        ];
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    public function buildArticleProfile(array $page, string $canonical): array
    {
        $title = trim((string)($page['title'] ?? ''));
        if ($title === '') {
            $title = WidgetI18n::label('帮助文章');
        }

        return [
            'page_type' => 'faq_article',
            'title' => $title,
            'description' => trim((string)($page['description'] ?? $page['meta_description'] ?? $title)),
            'canonical_url' => $canonical,
            'robots' => 'index,follow',
            'breadcrumbs' => [
                ['name' => WidgetI18n::label('首页'), 'url' => '/'],
                ['name' => WidgetI18n::label('帮助中心'), 'url' => '/faq'],
                ['name' => $title, 'url' => $canonical],
            ],
            'sitemap' => [
                'include' => true,
                'changefreq' => 'monthly',
                'priority' => '0.5',
            ],
            'geo' => ['include' => true],
        ];
    }
}
