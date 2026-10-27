<?php

declare(strict_types=1);

namespace Amayum\Website\Controller\Frontend;

final class Journal extends BasePage
{
    protected ?string $layoutType = 'journal';
    protected string $pageType = 'journal';
    protected string $contentTemplate = 'journal/index.phtml';
    protected string $bodyClass = 'page--journal';

    public function index(): string
    {
        $this->assign([
            'guides' => $this->guides->all(),
            'reading_order' => [
                '先决定平台与代码所有权 → /journal/build-platform',
                '再排查抓取、索引与结构化数据 → /journal/independent-store-seo',
                '最后算迁移与总成本 → /journal/shopify-alternative',
            ],
        ]);

        return $this->renderPage();
    }

    protected function buildSeo(string $websiteCode): array
    {
        return $this->seoFacts->journal($websiteCode);
    }
}
