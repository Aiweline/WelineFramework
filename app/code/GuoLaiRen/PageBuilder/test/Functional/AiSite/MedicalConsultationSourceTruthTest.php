<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Functional\AiSite;

final class MedicalConsultationSourceTruthTest extends AiSiteFunctionalTestCase
{
    protected function getTestCaseData(): array
    {
        return [
            'brief' => "在线皮肤科复诊预约\n医生资质公示\n隐私与合规提示",
            'page_types' => ['home_page', 'contact_page'],
            'expected_locale' => 'zh_Hans_CN',
        ];
    }
}
