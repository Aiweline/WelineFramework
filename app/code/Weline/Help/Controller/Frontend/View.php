<?php

declare(strict_types=1);

namespace Weline\Help\Controller\Frontend;

use Weline\Cms\Service\PageService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Help\Api\Uri\HelpNamespace;
use Weline\Help\Service\HelpScopeResolver;
use Weline\Help\Service\HelpSeoFactsBuilder;
use Weline\Theme\Model\ThemeLayout;

final class View extends FrontendController
{
    public function __construct(
        private readonly HelpSeoFactsBuilder $seoFacts,
        private readonly HelpScopeResolver $scope,
    ) {
    }

    public function index(): string
    {
        $slug = trim(strtolower((string)$this->request->getParam('slug', '')));
        if ($slug === '') {
            $this->getResponse()->setCode(404);

            return (string)$this->fetch('Weline_Theme::theme/frontend/layouts/not_found/default.phtml');
        }

        $page = null;
        try {
            /** @var PageService $pageService */
            $pageService = ObjectManager::getInstance(PageService::class);
            $page = $pageService->getPage([
                'path_group' => HelpNamespace::PREFIX,
                'slug' => $slug,
                'status' => 'published',
            ]);
        } catch (\Throwable) {
            $page = null;
        }

        if (!is_array($page) || (int)($page['page_id'] ?? 0) <= 0) {
            $this->getResponse()->setCode(404);

            return (string)$this->fetch('Weline_Theme::theme/frontend/layouts/not_found/default.phtml');
        }

        $title = trim((string)($page['title'] ?? $slug));
        $canonical = $this->scope->articleCanonical($slug);
        $this->layoutType = ThemeLayout::PAGE_TYPE_FAQ;
        $this->request->setGet('page_type', 'help_article');
        $this->request->setGet('theme_public_route', HelpNamespace::PREFIX . '/' . $slug);
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
        $this->assign('help_article', $page);
        $this->assign('help_article_payload', is_array($payload) ? $payload : []);
        $this->assign('seo', $this->seoFacts->buildArticleProfile($page, $canonical));

        return (string)$this->fetch('Weline_Help::templates/frontend/view.phtml');
    }
}
