<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\TemplateCompileLocaleJob;
use Weline\Framework\View\TemplateCompileService;

final class TemplateCompileServiceContractTest extends TestCase
{
    public function testServiceExposesPinnedCompileWithCallerLocalesAndProgress(): void
    {
        $path = dirname(__DIR__, 3) . '/View/TemplateCompileService.php';
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertStringContainsString('final class TemplateCompileService', $source);
        self::assertStringContainsString('compilePinnedSources', $source);
        self::assertStringContainsString('compilePinnedSourcesInProcess', $source);
        self::assertStringContainsString('compilePinnedSourcesWithProcessPool', $source);
        self::assertStringContainsString('WELINE_TEMPLATE_COMPILE_CONCURRENCY', $source);
        self::assertStringContainsString('WELINE_TEMPLATE_COMPILE_NESTED', $source);
        self::assertStringContainsString('compile-locale-job.php', $source);
        self::assertStringContainsString('isInsideWlsWorker', $source);
        self::assertStringContainsString('isNestedCompileWorker', $source);
        self::assertStringContainsString('compileFile', $source);
        self::assertStringContainsString('onProgress', $source);
        self::assertStringNotContainsString('WebsiteLanguage', $source);
        self::assertStringNotContainsString('Weline\\Websites', $source);
    }

    public function testLocaleJobProgressLineRoundTrip(): void
    {
        $parsed = TemplateCompileLocaleJob::parseProgressLine(
            TemplateCompileLocaleJob::PROGRESS_PREFIX . "\t3\t10\ten_US\tpage:home\n"
        );
        self::assertNotNull($parsed);
        self::assertSame(3, $parsed['done']);
        self::assertSame(10, $parsed['total']);
        self::assertSame('en_US', $parsed['locale']);
        self::assertSame('page:home', $parsed['label']);
    }

    public function testResolveConcurrencyClampsAndDefaults(): void
    {
        $svc = new TemplateCompileService();
        self::assertSame(1, $svc->resolveConcurrency(0));
        self::assertSame(1, $svc->resolveConcurrency(1));
        self::assertSame(10, $svc->resolveConcurrency(10));
        self::assertSame(32, $svc->resolveConcurrency(100));
    }

    public function testWorkerScriptExistsBesideViewModule(): void
    {
        $script = dirname(__DIR__, 3) . '/View/bin/compile-locale-job.php';
        self::assertFileExists($script);
        $src = (string)file_get_contents($script);
        self::assertStringContainsString('TemplateCompileLocaleJob::run', $src);
    }
}
