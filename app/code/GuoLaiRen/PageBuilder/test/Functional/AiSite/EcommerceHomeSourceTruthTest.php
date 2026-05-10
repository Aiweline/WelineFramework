<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Functional\AiSite;

final class EcommerceHomeSourceTruthTest extends AiSiteFunctionalTestCase
{
    protected function getTestCaseData(): array
    {
        return [
            'brief' => "家居收纳电商首页\nSKU 分类与包邮策略\n用户评价模块",
            'page_types' => ['home_page'],
            'expected_locale' => 'zh_Hans_CN',
        ];
    }
}
