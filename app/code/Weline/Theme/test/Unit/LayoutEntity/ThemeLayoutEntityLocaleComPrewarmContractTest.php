<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBatchPublisher;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityFormalLocaleCompileService;

final class ThemeLayoutEntityLocaleComPrewarmContractTest extends TestCase
{
    public function testPublishGateUsesFormalLocaleCompileFailClosed(): void
    {
        $publisher = file_get_contents(dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityBatchPublisher.php');
        $service = file_get_contents(dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityFormalLocaleCompileService.php');
        self::assertIsString($publisher);
        self::assertIsString($service);
        self::assertStringContainsString('ThemeLayoutEntityFormalLocaleCompileService::class', $publisher);
        self::assertStringContainsString('compileAfterPromote', $publisher);
        self::assertStringContainsString('rollbackPlan', $publisher);
        self::assertStringContainsString('class ThemeLayoutEntityFormalLocaleCompileService', $service);
        self::assertStringContainsString('TemplateCompileService', $service);
        self::assertStringContainsString('主题布局语言编译', $service);
        self::assertStringContainsString('getWebsiteLanguageCodes', $service);
        self::assertStringNotContainsString('w_log_warning', $publisher);
        self::assertStringNotContainsString('LocaleComPrewarm', $publisher);
    }
}
