<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Functional\AiSite;

final class EducationSourceTruthTest extends AiSiteFunctionalTestCase
{
    protected function getTestCaseData(): array
    {
        return [
            'brief' => "少儿编程训练营落地页\n小班直播课试听预约\n家长信任与安全承诺",
            'page_types' => ['home_page'],
            'expected_locale' => 'zh_Hans_CN',
        ];
    }
}
