<?php

declare(strict_types=1);

namespace Amayum\Website\Controller\Frontend;

final class CaseStudies extends BasePage
{
    protected ?string $layoutType = 'case_studies';
    protected string $pageType = 'case_studies';
    protected string $contentTemplate = 'case-studies/index.phtml';
    protected string $bodyClass = 'page--case-studies';

    public function index(): string
    {
        $this->assign([
            'examples' => $this->caseStudies->all(),
            'examples_not_proven' => [
                '不证明框架的每个模块',
                '不证明多网关',
                '不证明所有语言',
                '不证明增长/转化',
                '不证明合规认证',
                '不证明 WLS GA',
            ],
        ]);

        return $this->renderPage();
    }

    protected function buildSeo(string $websiteCode): array
    {
        return $this->seoFacts->caseStudies($websiteCode);
    }
}
