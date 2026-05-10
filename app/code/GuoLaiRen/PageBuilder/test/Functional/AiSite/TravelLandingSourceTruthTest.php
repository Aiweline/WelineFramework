<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Functional\AiSite;

final class TravelLandingSourceTruthTest extends AiSiteFunctionalTestCase
{
    protected function getTestCaseData(): array
    {
        return [
            'brief' => "川藏线小团深度游\n含领队与保险说明\n旺季余位实时更新",
            'page_types' => ['home_page'],
            'expected_locale' => 'zh_Hans_CN',
        ];
    }
}
