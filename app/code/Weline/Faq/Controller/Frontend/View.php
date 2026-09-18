<?php

declare(strict_types=1);

namespace Weline\Faq\Controller\Frontend;

use Weline\Cms\Service\PageService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Faq\Api\Uri\FaqNamespace;
use Weline\Faq\Service\FaqPageProviderRegistry;
use Weline\Faq\Service\FaqScopeResolver;
use Weline\Faq\Service\FaqSeoFactsBuilder;
use Weline\Theme\Model\ThemeLayout;

final class View extends FrontendController
{
    private ?FaqPageProviderRegistry $pageProviderRegistry = null;

    public function __construct(
        private readonly FaqSeoFactsBuilder $seoFacts,
        private readonly FaqScopeResolver $scope,
        ?FaqPageProviderRegistry $pageProviders = null,
    ) {
        $this->pageProviderRegistry = $pageProviders;
    }

    public function index(): string
    {
        $slug = trim(strtolower((string)$this->request->getParam('slug', '')));
        if ($slug === '') {
            $this->request->getResponse()->setCode(404);

            return (string)$this->fetch('Weline_Theme::theme/frontend/layouts/not_found/default.phtml');
        }

        // SPI pages win over CMS for the same slug (plan: SPI first).
        $provider = $this->pageProviders()->findBySlug($slug);
        if ($provider !== null) {
            $this->renderSpiPage($provider->title(), $slug, $provider->template(), $provider->summary());

            return (string)$this->fetch('Weline_Faq::templates/frontend/view.phtml');
        }

        $page = null;
        try {
            /** @var PageService $pageService */
            $pageService = ObjectManager::getInstance(PageService::class);
            $page = $pageService->getPage([
                'path_group' => FaqNamespace::PREFIX,
                'slug' => $slug,
                'status' => 'published',
            ]);
        } catch (\Throwable) {
            $page = null;
        }

        if (!is_array($page) || (int)($page['page_id'] ?? 0) <= 0) {
            $this->request->getResponse()->setCode(404);

            return (string)$this->fetch('Weline_Theme::theme/frontend/layouts/not_found/default.phtml');
        }

        $title = trim((string)($page['title'] ?? $slug));
        $canonical = $this->scope->articleCanonical($slug);
        $this->layoutType = ThemeLayout::PAGE_TYPE_FAQ;
        $this->request->setGet('page_type', 'faq_article');
        $this->request->setGet('theme_page_title', $title);

        $payload = null;
        try {
            $payload = ObjectManager::getInstance(PageService::class)->renderPagePayload([
                'page_id' => (int)$page['page_id'],
                'preview' => false,
            ]);
        } catch (\Throwable) {
            $payload = null;
        }

        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('faq_article', $page);
        $this->assign('faq_article_payload', is_array($payload) ? $payload : []);
        $this->assign('faq_spi_template', '');
        $this->assign('seo', $this->seoFacts->buildArticleProfile($page, $canonical));

        return (string)$this->fetch('Weline_Faq::templates/frontend/view.phtml');
    }

    private function renderSpiPage(string $title, string $slug, string $template, string $summary): void
    {
        $title = trim($title) !== '' ? trim($title) : $slug;
        $canonical = $this->scope->articleCanonical($slug);
        $this->layoutType = ThemeLayout::PAGE_TYPE_FAQ;
        $this->request->setGet('page_type', 'faq_article');
        $this->request->setGet('theme_page_title', $title);

        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('faq_article', [
            'title' => $title,
            'slug' => $slug,
            'summary' => $summary,
            'source' => 'spi',
        ]);
        $this->assign('faq_article_payload', []);
        $this->assign('faq_spi_template', $template);
        $this->assign('seo', $this->seoFacts->buildArticleProfile([
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $summary,
        ], $canonical));
    }

    private function pageProviders(): FaqPageProviderRegistry
    {
        return $this->pageProviderRegistry ??= ObjectManager::getInstance(FaqPageProviderRegistry::class);
    }
}
