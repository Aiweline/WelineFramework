<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Test\Console\E2e\CleanupBrowsers;
use Weline\Framework\Test\Service\E2eBrowserCleanupService;

final class E2eBrowserCleanupContractTest extends TestCase
{
    public function testCleanupCommandTipMentionsAutomationLeftovers(): void
    {
        $command = new CleanupBrowsers();
        $tip = $command->tip();
        self::assertIsString($tip);
        self::assertNotSame('', trim($tip));
        self::assertTrue(
            str_contains($tip, 'Playwright') || str_contains($tip, 'Chrome') || str_contains($tip, '浏览器'),
            'tip should mention browser cleanup'
        );
    }

    public function testCleanupServiceDryRunReturnsStructuredReport(): void
    {
        $service = new E2eBrowserCleanupService();
        $report = $service->cleanup(['dry_run' => true]);

        self::assertArrayHasKey('ok', $report);
        self::assertArrayHasKey('killedPids', $report);
        self::assertArrayHasKey('removedPaths', $report);
        self::assertArrayHasKey('closedTabs', $report);
        self::assertTrue($report['dryRun']);
        self::assertIsArray($report['killedPids']);
        self::assertIsArray($report['removedPaths']);
    }

    public function testCleanupScriptExistsAndExportsMatcherHelpers(): void
    {
        $script = BP . 'tests/e2e/framework/cleanup-orphaned-browsers.js';
        self::assertFileExists($script);
        $source = (string)file_get_contents($script);
        self::assertStringContainsString('isOrphanAutomationBrowser', $source);
        self::assertStringContainsString('chrome-headless-shell', $source);
        self::assertStringContainsString('Google Chrome.app/Contents/MacOS/Google Chrome', $source);
    }
}
