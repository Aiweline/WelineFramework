<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Functional\AiSite;

final class LocalRestaurantSourceTruthTest extends AiSiteFunctionalTestCase
{
    protected function getTestCaseData(): array
    {
        return [
            'brief' => "老城区川菜馆线上预订\n电话订座与外卖指引\n周末套餐优惠",
            'instruction' => '',
            'page_types' => ['home_page', 'contact_page'],
            'expected_locale' => 'zh_Hans_CN',
        ];
    }
}
