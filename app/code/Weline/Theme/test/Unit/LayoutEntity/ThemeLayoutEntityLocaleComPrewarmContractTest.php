<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

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
        self::assertStringContainsString("options['compile']", $publisher);
        self::assertStringContainsString('rollbackPromote', $publisher);
        self::assertStringContainsString('class ThemeLayoutEntityFormalLocaleCompileService', $service);
        self::assertStringContainsString('TemplateCompileService', $service);
        self::assertStringContainsString('主题布局发布', $service);
        self::assertStringContainsString('阶段1/2 Taglib编译', $service);
        self::assertStringContainsString('阶段2/2 资源合包预热', $service);
        self::assertStringContainsString('Taglib · 主题=', $service);
        self::assertStringContainsString('formatThemeProgressLabel', $service);
        self::assertStringContainsString('loadThemeForIdentity', $service);
        self::assertStringContainsString('theme#' , $service);
        self::assertStringNotContainsString(
            "resolveTheme('frontend'",
            $service,
            'progress must not use process-global Default frontend theme',
        );
        self::assertStringContainsString('progressBar', $service);
        self::assertStringContainsString('finishProgressLine', $service);
        self::assertStringContainsString('进程池', $service);
        self::assertStringContainsString('compileAfterPromoteFromPaths', $service);
        self::assertStringContainsString('snapshotFromPublishedCandidates', $service);
        // Promote candidates must be closed over (PHP 8.4 typed arg ≠ silent null).
        self::assertStringContainsString('$candidateMap = $candidates', $service);
        self::assertTrue(
            (bool)preg_match(
                '/function\s*\(\s*\)\s*use\s*\([\s\S]*?\$candidateMap[\s\S]*?\)\s*:\s*void/m',
                $service,
            ),
            'runPinnedToIdentityWebsite closure must use ($candidateMap)',
        );
        // Prefer candidate snapshot before disk capture (avoid OwnerLock vs solidify WRITE).
        $loop = (string)preg_replace(
            '/.*foreach \(\$pages as (?:\$pageIndex => )?\$page\) \{/s',
            'foreach ($pages as $page) {',
            $service,
            1,
        );
        $preferPos = strpos($loop, 'snapshotFromPublishedCandidates');
        $capturePos = strpos($loop, 'ThemeLayoutSourceSnapshot::capture');
        self::assertNotFalse($preferPos);
        self::assertNotFalse($capturePos);
        self::assertLessThan($capturePos, $preferPos, 'candidate snapshot must run before disk capture');
        self::assertStringContainsString("'nested'", $service);
        self::assertStringContainsString('resolveConcurrency', $service);
        self::assertStringContainsString('getWebsiteLanguageCodes', $service);
        self::assertStringNotContainsString('w_log_warning', $publisher);
        self::assertStringNotContainsString('LocaleComPrewarm', $publisher);
    }

    public function testFormalCompilePinsWebsiteFromIdentityBeforeCompile(): void
    {
        $service = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityFormalLocaleCompileService.php'
        );
        self::assertStringContainsString('runPinnedToIdentityWebsite', $service);
        self::assertStringContainsString('runWithCompileTimeWebsitePin', $service);
        self::assertStringContainsString('WebsiteData::setWebsite', $service);
        self::assertStringContainsString('resolveWebsiteForCanonicalScope', $service);
        self::assertStringContainsString('compilePinnedSources', $service);
        self::assertStringContainsString('warmPublishedResourcePacks', $service);
        self::assertStringContainsString('ThemeHeadChromeCssPack', $service);
        self::assertStringContainsString('warmAreaPacks', $service);
        self::assertMatchesRegularExpression(
            '/runPinnedToIdentityWebsite\s*\(\s*\$identity\s*,/',
            $service
        );
    }
}
