<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Smtp\Service\MailShellHanfuDefaultsService;

final class MailShellHanfuDefaultsContractTest extends TestCase
{
    public function testDefaultsServiceExposesHanfuShellMarkup(): void
    {
        $svc = new MailShellHanfuDefaultsService();
        $header = $svc->defaultHeaderHtml();
        $footer = $svc->defaultFooterHtml();
        self::assertStringContainsString('{{var.brand_header_text}}', $header);
        self::assertStringContainsString('{{var.brand_display_name}}', $header);
        self::assertStringContainsString('{{var.site_description}}', $header);
        self::assertStringNotContainsString('!important', $header);
        self::assertStringContainsString('{{var.brand_footer_heading}}', $footer);
        self::assertStringContainsString('{{var.site_url}}', $footer);
        self::assertStringNotContainsString('p05113ef3', $footer);

        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/Setup/Upgrade.php');
        self::assertStringContainsString('MailShellHanfuDefaultsService', $src);
        self::assertStringContainsString('setMailShellBackgrounds', (string)file_get_contents(
            dirname(__DIR__, 2) . '/Helper/Data.php'
        ));
    }

    public function testEnsureDoesNotWriteBackOnEmptyHeader(): void
    {
        $svcSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/MailShellHanfuDefaultsService.php');
        // 禁止「空 header 即写回」：needHeader 不得用 header === '' 触发
        self::assertDoesNotMatchRegularExpression(
            '/\$needHeader\s*=\s*\$force\s*\|\|\s*trim\(\(string\)\(\$current\[\'header\'\]/s',
            $svcSrc
        );
        self::assertStringNotContainsString(
            "trim((string)(\$current['header'] ?? '')) === ''",
            $svcSrc
        );
        self::assertStringContainsString("str_contains(\$headerHtml, '!important')", $svcSrc);
        self::assertStringContainsString("str_contains(\$headerHtml, '#ff5d05')", $svcSrc);
        self::assertStringContainsString('使用主题/模块 shell.phtml', $svcSrc);
    }
}
