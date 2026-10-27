<?php

declare(strict_types=1);

namespace Amayum\Website\Controller\Frontend;

final class Homepage extends BasePage
{
    protected ?string $layoutType = 'homepage';
    protected string $pageType = 'homepage';
    protected string $contentTemplate = 'homepage/index.phtml';
    protected string $bodyClass = 'page--homepage';

    public function index(): string
    {
        $websiteCode = $this->siteProfile->currentWebsiteCode();
        $this->assign([
            'proof_facts' => $this->siteProfile->proofFacts($websiteCode),
            'examples' => $this->caseStudies->all(),
            'guides' => $this->guides->all(),
        ]);

        return $this->renderPage();
    }

    protected function buildSeo(string $websiteCode): array
    {
        return $this->seoFacts->homepage($websiteCode);
    }
}
