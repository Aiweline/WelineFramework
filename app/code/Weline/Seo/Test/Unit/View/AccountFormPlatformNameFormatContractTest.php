<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountFormPlatformNameFormatContractTest extends TestCase
{
    public function testFormRequiresPlatformBeforePrefixedAccountName(): void
    {
        $root = dirname(__DIR__, 3);
        $template = $root . '/view/templates/Backend/Account/form.phtml';
        $controller = $root . '/Controller/Backend/Account.php';

        self::assertFileExists($template);
        self::assertFileExists($controller);

        $templateSrc = (string) file_get_contents($template);
        $controllerSrc = (string) file_get_contents($controller);

        $platformPos = strpos($templateSrc, 'class="seo-platform-grid"');
        $namePos = strpos($templateSrc, 'id="seoAccountNameField"');
        self::assertNotFalse($platformPos);
        self::assertNotFalse($namePos);
        self::assertGreaterThan($platformPos, $namePos);

        self::assertStringContainsString('function syncAccountNameFormat', $templateSrc);
        self::assertStringContainsString('function enforceAccountNamePrefix', $templateSrc);
        self::assertStringContainsString('function appendSafeGuideStep', $templateSrc);
        self::assertStringContainsString('appendSafeGuideStep(item, step)', $templateSrc);
        self::assertStringNotContainsString('item.textContent = step;', $templateSrc);
        self::assertStringContainsString('强制要求填写用户名的格式是：', $templateSrc);
        self::assertStringContainsString('data-name-prefix', $templateSrc);

        self::assertStringContainsString("账户名称必须符合格式：%{1}{账户名}", $controllerSrc);
        self::assertStringContainsString('$expectedPrefix', $controllerSrc);
        self::assertStringContainsString('str_starts_with($name, $expectedPrefix)', $controllerSrc);
    }
}
