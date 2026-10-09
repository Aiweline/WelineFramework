<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Css;

use PHPUnit\Framework\TestCase;

/**
 * REQ-THEME-0007: module widget CSS/PHTML must not hardcode visual literals.
 * Scanner twin: Theme/bin/widget-theme-token-audit.py
 */
final class WidgetThemeTokenHardcodeContractTest extends TestCase
{
    public function testWidgetVisualSourcesHaveZeroTokenHardcodes(): void
    {
        $script = dirname(__DIR__, 3) . '/bin/widget-theme-token-audit.py';
        self::assertFileExists($script);

        $cmd = 'python3 ' . escapeshellarg($script) . ' --json';
        $json = shell_exec($cmd);
        self::assertIsString($json, 'widget-theme-token-audit.py must produce JSON');
        $report = json_decode($json, true);
        self::assertIsArray($report);
        self::assertTrue(
            (bool)($report['ok'] ?? false),
            'widget theme token audit failed: bad_files='
            . (string)($report['bad_files'] ?? '?')
            . ' by_code=' . json_encode($report['by_code'] ?? [], JSON_UNESCAPED_UNICODE)
            . ' first=' . json_encode(($report['files'][0] ?? null), JSON_UNESCAPED_UNICODE)
        );
        self::assertSame(0, (int)($report['bad_files'] ?? -1));
    }
}
