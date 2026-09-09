<?php

declare(strict_types=1);

namespace Weline\Faq\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Faq\Api\Uri\FaqNamespace;
use Weline\Faq\Service\FaqHubContent;
use Weline\Faq\Service\FaqScopeResolver;
use Weline\Faq\Service\FaqSeoFactsBuilder;
use Weline\Theme\Model\ThemeLayout;

final class Index extends FrontendController
{
    public function __construct(
        private readonly FaqHubContent $hub,
        private readonly FaqSeoFactsBuilder $seoFacts,
        private readonly FaqScopeResolver $scope,
    ) {
    }

    public function index(): string
    {
        $title = (string)__('FAQ');
        $this->layoutType = ThemeLayout::PAGE_TYPE_FAQ;
        $this->request->setGet('page_type', 'faq');
        $this->request->setGet('theme_public_route', FaqNamespace::PREFIX);
        $this->request->setGet('theme_page_title', $title);

        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('faq_topics', $this->hub->topics());
        $this->assign('faq_faqs', $this->hub->faqs());
        $this->assign('faq_quick_links', $this->hub->quickLinks());
        $this->assign('seo', $this->seoFacts->buildHubProfile($this->scope->hubCanonical()));

        return (string)$this->fetch('Weline_Faq::templates/frontend/index.phtml');
    }
}
