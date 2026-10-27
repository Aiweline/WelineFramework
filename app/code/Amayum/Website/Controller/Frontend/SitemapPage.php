<?php

declare(strict_types=1);

namespace Amayum\Website\Controller\Frontend;

final class SitemapPage extends BasePage
{
    protected ?string $layoutType = 'sitemap_page';
    protected string $pageType = 'sitemap_page';
    protected string $contentTemplate = 'sitemap/index.phtml';
    protected string $bodyClass = 'page--sitemap';

    public function index(): string
    {
        $this->assign([
            'sitemap_groups' => [
                [
                    'title' => '产品与能力',
                    'links' => [
                        ['label' => '首页', 'href' => '/'],
                        ['label' => '框架能力', 'href' => '/commerce'],
                        ['label' => '运行中的例子', 'href' => '/case-studies'],
                        ['label' => '联系 / 诊断', 'href' => '/diagnosis'],
                    ],
                ],
                [
                    'title' => '指南',
                    'links' => [
                        ['label' => '独立站指南', 'href' => '/journal'],
                        ['label' => '独立站建站平台怎么选', 'href' => '/journal/build-platform'],
                        ['label' => '独立站 SEO 为什么没效果', 'href' => '/journal/independent-store-seo'],
                        ['label' => 'Shopify 替代方案怎么选', 'href' => '/journal/shopify-alternative'],
                    ],
                ],
                [
                    'title' => '公司与法务',
                    'links' => [
                        ['label' => '关于 Amayum', 'href' => '/#about'],
                        ['label' => '隐私、条款与许可', 'href' => '/legal'],
                        ['label' => '站点地图', 'href' => '/sitemap'],
                    ],
                ],
            ],
        ]);

        return $this->renderPage();
    }

    protected function buildSeo(string $websiteCode): array
    {
        return $this->seoFacts->sitemap($websiteCode);
    }
}
