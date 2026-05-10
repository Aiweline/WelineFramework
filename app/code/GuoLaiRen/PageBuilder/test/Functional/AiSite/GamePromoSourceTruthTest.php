<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Functional\AiSite;

final class GamePromoSourceTruthTest extends AiSiteFunctionalTestCase
{
    protected function getTestCaseData(): array
    {
        return [
            'brief' => "新手礼包页\n移动端棋牌合集推广\n立即下载安装",
            'instruction' => 'game landing APK',
            'page_types' => ['home_page'],
            'expected_locale' => 'en_US',
        ];
    }
}
