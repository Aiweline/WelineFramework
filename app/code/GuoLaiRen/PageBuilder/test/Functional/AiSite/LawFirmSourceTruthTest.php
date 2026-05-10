<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Functional\AiSite;

final class LawFirmSourceTruthTest extends AiSiteFunctionalTestCase
{
    protected function getTestCaseData(): array
    {
        return [
            'brief' => "商事争议解决律师事务所\n免费咨询预约表单\n案例摘要与团队介绍",
            'page_types' => ['home_page', 'about_page'],
            'expected_locale' => 'zh_Hans_CN',
        ];
    }
}
