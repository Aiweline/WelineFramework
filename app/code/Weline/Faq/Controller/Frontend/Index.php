<?php

declare(strict_types=1);

namespace Weline\Faq\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Faq\Api\Uri\FaqNamespace;
use Weline\Faq\Service\FaqHubContent;
use Weline\Faq\Service\FaqPageProviderRegistry;
use Weline\Faq\Service\FaqScopeResolver;
use Weline\Faq\Service\FaqSeoFactsBuilder;
use Weline\Theme\Helper\WidgetI18n;
use Weline\Theme\Model\ThemeLayout;

final class Index extends FrontendController
{
    private ?FaqPageProviderRegistry $pageProviderRegistry = null;

    public function __construct(
        private readonly FaqHubContent $hub,
        private readonly FaqSeoFactsBuilder $seoFacts,
        private readonly FaqScopeResolver $scope,
        ?FaqPageProviderRegistry $pageProviders = null,
    ) {
        $this->pageProviderRegistry = $pageProviders;
    }

    public function index(): string
    {
        // Chinese source key + path-locale WidgetI18n; __('FAQ') can bake 常问问题 into non-zh pages.
        $title = WidgetI18n::label('常问问题');
        $this->layoutType = ThemeLayout::PAGE_TYPE_FAQ;
        $this->request->setGet('page_type', 'faq');
        $this->request->setGet('theme_public_route', FaqNamespace::PREFIX);
        $this->request->setGet('theme_page_title', $title);

        $spiPages = [];
        foreach ($this->pageProviders()->enabledPages() as $page) {
            $spiPages[] = [
                'icon' => 'book',
                'title' => $page->title(),
                'desc' => $page->summary(),
                'url' => FaqNamespace::PREFIX . '/' . $page->slug(),
                'route' => true,
                'group' => $page->group(),
                'source' => 'spi',
                'page_code' => $page->pageCode(),
            ];
        }

        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('faq_topics', array_merge($spiPages, $this->hub->topics()));
        $this->assign('faq_faqs', $this->hub->faqs());
        $this->assign('faq_quick_links', $this->hub->quickLinks());
        $this->assign('faq_spi_pages', $spiPages);
        $this->assign('seo', $this->seoFacts->buildHubProfile($this->scope->hubCanonical()));

        return (string)$this->fetch('Weline_Faq::templates/frontend/index.phtml');
    }

    private function pageProviders(): FaqPageProviderRegistry
    {
        return $this->pageProviderRegistry ??= \Weline\Framework\Manager\ObjectManager::getInstance(FaqPageProviderRegistry::class);
    }
}
