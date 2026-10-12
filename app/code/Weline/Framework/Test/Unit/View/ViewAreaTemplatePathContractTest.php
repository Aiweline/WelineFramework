<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\ViewAreaTemplatePath;

final class ViewAreaTemplatePathContractTest extends TestCase
{
    public function testStripAndJoinUseLowercaseAreaLayerOnly(): void
    {
        self::assertSame('Log', ViewAreaTemplatePath::stripLeadingAreaSegment('Backend/Log'));
        self::assertSame('Log', ViewAreaTemplatePath::stripLeadingAreaSegment('backend/Log'));
        self::assertSame('account/login', ViewAreaTemplatePath::stripLeadingAreaSegment('Frontend/account/login'));
        self::assertSame(
            'templates/backend/Log/index',
            ViewAreaTemplatePath::joinTemplates('backend', 'Log/index')
        );
        self::assertSame(
            'templates/frontend/account/login',
            ViewAreaTemplatePath::joinTemplates('frontend', 'account/login')
        );
        self::assertSame(
            'themes/backend/Log/index',
            ViewAreaTemplatePath::joinThemes('Backend', 'Log/index')
        );
    }

    public function testPcControllerFetchJoinsAreaLayer(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Controller/PcController.php'
        );
        self::assertStringContainsString('ViewAreaTemplatePath::joinTemplates', $src);
        self::assertStringContainsString('ViewAreaTemplatePath::joinThemes', $src);
        self::assertStringContainsString('resolveViewTemplateArea', $src);
        self::assertStringContainsString('stripLeadingAreaSegment', $src);
        self::assertStringNotContainsString(
            "fetchTemplateWithEvents('templates' . DS . \$fileName)",
            $src
        );
    }
}
