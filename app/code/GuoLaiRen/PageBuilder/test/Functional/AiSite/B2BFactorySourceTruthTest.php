<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Functional\AiSite;

final class B2BFactorySourceTruthTest extends AiSiteFunctionalTestCase
{
    protected function getTestCaseData(): array
    {
        return [
            'brief' => "精密注塑件工厂\n出口认证与交期承诺\n索取报价入口",
            'page_types' => ['home_page', 'about_page'],
            'expected_locale' => 'en_US',
        ];
    }
}
