<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Live preview currency switch must keep /~preview/{token}/… and never re-stack /~site.
 */
final class CurrencySwitcherLivePreviewPathContractTest extends TestCase
{
    public function testCurrencyJsPeelsLivePreviewMountInFallbackBuilder(): void
    {
        $runtime = $this->read('view/statics/js/currency.js');

        self::assertStringContainsString('peelLivePreviewPathMount', $runtime);
        self::assertStringContainsString('LIVE_PREVIEW_TOKEN_PATTERN', $runtime);
        self::assertStringContainsString('buildCurrencyUrlFromPath', $runtime);
        self::assertStringContainsString('never leave token segments', $runtime);
        self::assertStringContainsString('/~preview/${live.token}', $runtime);
        self::assertStringContainsString("__initialized === 3", $runtime);
        self::assertStringContainsString('__initialized: 3', $runtime);

        $builderAt = strpos($runtime, 'function buildCurrencyUrlFromPath');
        self::assertNotFalse($builderAt);
        $builderBody = substr($runtime, $builderAt, 1800);
        self::assertStringContainsString('peelLivePreviewPathMount(pathOnly)', $builderBody);
        self::assertStringContainsString("part === '~preview'", $builderBody);
        self::assertStringContainsString("part === '~site'", $builderBody);
        self::assertStringContainsString('!live.token && isBackendLocalizedPath', $builderBody);
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . ltrim($relative, '/');
        $content = file_get_contents($path);
        self::assertIsString($content, $path);

        return $content;
    }
}
