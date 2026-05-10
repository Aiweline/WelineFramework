<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Functional\AiSite;

final class IndiaCardGameAPKSourceTruthTest extends AiSiteFunctionalTestCase
{
    protected function getTestCaseData(): array
    {
        return [
            'brief' => "印度市场棋牌 APK 下载推广站点\n主打 Teen Patti 真金娱乐\n强调安全下载与快速安装",
            'instruction' => '首页突出 APK 下载按钮',
            'page_types' => ['home_page'],
            'expected_locale' => 'zh_Hans_CN',
        ];
    }
}
