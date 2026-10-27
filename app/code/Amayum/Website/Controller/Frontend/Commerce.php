<?php

declare(strict_types=1);

namespace Amayum\Website\Controller\Frontend;

final class Commerce extends BasePage
{
    protected ?string $layoutType = 'commerce';
    protected string $pageType = 'commerce';
    protected string $contentTemplate = 'commerce/index.phtml';
    protected string $bodyClass = 'page--commerce';

    public function index(): string
    {
        $websiteCode = $this->siteProfile->currentWebsiteCode();
        $this->assign([
            'proof_facts' => $this->siteProfile->proofFacts($websiteCode),
            'status_legend' => [
                ['key' => 'verified', 'label' => '已验证', 'description' => '有代码、文档或运行证据。'],
                ['key' => 'partial', 'label' => '部分', 'description' => '有实现，但有明确边界或需要配置。'],
                ['key' => 'not_implemented', 'label' => '未实现', 'description' => '当前仓库没有该能力。'],
            ],
        ]);

        return $this->renderPage();
    }

    protected function buildSeo(string $websiteCode): array
    {
        return $this->seoFacts->commerce($websiteCode);
    }
}
