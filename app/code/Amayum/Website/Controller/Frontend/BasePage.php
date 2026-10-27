<?php

declare(strict_types=1);

namespace Amayum\Website\Controller\Frontend;

use Amayum\Website\Service\CaseStudyCatalog;
use Amayum\Website\Service\GuideCatalog;
use Amayum\Website\Service\SeoFactsService;
use Amayum\Website\Service\SiteProfileService;
use Weline\Framework\App\Controller\FrontendController;

/**
 * Amayum 页面基类：注入站点资料、导航、页脚与 SEO 事实，并渲染模块内容模板。
 *
 * 子类只设置 layoutType / pageType / contentTemplate / bodyClass，并实现 buildSeo()。
 */
class BasePage extends FrontendController
{
    protected ?string $layoutType = null;
    protected string $pageType = 'default';
    protected string $contentTemplate = '';
    protected string $bodyClass = '';

    public function __construct(
        protected readonly SiteProfileService $siteProfile,
        protected readonly SeoFactsService $seoFacts,
        protected readonly CaseStudyCatalog $caseStudies,
        protected readonly GuideCatalog $guides,
    ) {
    }

    /** @return array<string, mixed> */
    protected function buildSeo(string $websiteCode): array
    {
        return [];
    }

    /** @param array<string, mixed> $data */
    protected function renderPage(array $data = []): string
    {
        $websiteCode = $this->siteProfile->currentWebsiteCode();
        $site = $this->siteProfile->profile($websiteCode);
        $nav = $this->siteProfile->nav($websiteCode);
        $footer = $this->siteProfile->footer($websiteCode);
        $seo = $this->buildSeo($websiteCode);
        $title = (string)($seo['title'] ?? $site['brand_name'] ?? 'Amayum');

        $this->request->setGet('page_type', $this->pageType);
        $this->request->setGet('theme_page_title', $title);

        $this->assign([
            'site' => $site,
            'nav' => $nav,
            'nav_items' => $nav['items'],
            'nav_cta' => $nav['cta'],
            'footer' => $footer,
            'footer_columns' => $footer['columns'],
            'footer_bottom' => $footer['bottom'],
            'seo' => $seo,
            'pageType' => $this->pageType,
            'page_type' => $this->pageType,
            'bodyClass' => $this->bodyClass,
            'page_title' => $title,
            'title' => $title,
            'faqs' => $seo['faqs'] ?? [],
            'contact_email' => (string)($site['contact_email'] ?? 'hello@amayum.com'),
            'site_url' => (string)($site['site_url'] ?? 'https://amayum.com'),
        ]);
        if ($data !== []) {
            $this->assign($data);
        }

        return (string)$this->fetch('Amayum_Website::templates/frontend/' . $this->contentTemplate);
    }
}
