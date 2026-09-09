<?php

declare(strict_types=1);

namespace Weline\Help\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Help\Api\Uri\HelpNamespace;
use Weline\Help\Service\HelpHubContent;
use Weline\Help\Service\HelpScopeResolver;
use Weline\Help\Service\HelpSeoFactsBuilder;
use Weline\Theme\Model\ThemeLayout;

final class Index extends FrontendController
{
    public function __construct(
        private readonly HelpHubContent $hub,
        private readonly HelpSeoFactsBuilder $seoFacts,
        private readonly HelpScopeResolver $scope,
    ) {
    }

    public function index(): string
    {
        $title = (string)__('帮助中心');
        $this->layoutType = ThemeLayout::PAGE_TYPE_FAQ;
        $this->request->setGet('page_type', 'faq');
        $this->request->setGet('theme_public_route', HelpNamespace::PREFIX);
        $this->request->setGet('theme_page_title', $title);

        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('help_topics', $this->hub->topics());
        $this->assign('help_faqs', $this->hub->faqs());
        $this->assign('help_quick_links', $this->hub->quickLinks());
        $this->assign('seo', $this->seoFacts->buildHubProfile($this->scope->hubCanonical()));

        return (string)$this->fetch('Weline_Help::templates/frontend/index.phtml');
    }
}
