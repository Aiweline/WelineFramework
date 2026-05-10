<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Functional\AiSite;

final class SaaSSourceTruthTest extends AiSiteFunctionalTestCase
{
    protected function getTestCaseData(): array
    {
        return [
            'brief' => "B2B inventory SaaS\nmulti-warehouse sync\n14-day trial signup",
            'page_types' => ['home_page'],
            'expected_locale' => 'en_US',
        ];
    }
}
