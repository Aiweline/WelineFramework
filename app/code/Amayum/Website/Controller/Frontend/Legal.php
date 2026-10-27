<?php

declare(strict_types=1);

namespace Amayum\Website\Controller\Frontend;

final class Legal extends BasePage
{
    protected ?string $layoutType = 'legal';
    protected string $pageType = 'legal';
    protected string $contentTemplate = 'legal/index.phtml';
    protected string $bodyClass = 'page--legal';

    public function index(): string
    {
        $websiteCode = $this->siteProfile->currentWebsiteCode();
        $profile = $this->siteProfile->profile($websiteCode);
        $this->assign([
            'legal_updated' => '2026-10-27',
            'legal_name' => (string)($profile['legal_name'] ?? 'Aiweline（深圳）'),
            'contact_email' => (string)($profile['contact_email'] ?? 'hello@amayum.com'),
        ]);

        return $this->renderPage();
    }

    protected function buildSeo(string $websiteCode): array
    {
        return $this->seoFacts->legal($websiteCode);
    }
}
