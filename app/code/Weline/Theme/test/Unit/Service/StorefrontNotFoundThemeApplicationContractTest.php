<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Contract: storefront 404 static generation must resolve theme via ThemeApplicationContext
 * (never ScopeIdentity), force serial publish, and never reload published snapshots on fallback.
 */
final class StorefrontNotFoundThemeApplicationContractTest extends TestCase
{
    private function generatorSource(): string
    {
        $path = \dirname(__DIR__, 3) . '/Service/StorefrontNotFoundStaticGenerator.php';
        self::assertFileExists($path);

        return (string)\file_get_contents($path);
    }

    public function testPublishAllForcesSerialConcurrency(): void
    {
        $src = $this->generatorSource();
        self::assertStringContainsString("'concurrency' => 1", $src);
    }

    public function testRenderUsesThemeApplicationContextProducerNotScopeIdentity(): void
    {
        $src = $this->generatorSource();
        self::assertStringContainsString('ThemeApplicationContextProducer', $src);
        self::assertStringContainsString('installWebsiteThemeApplication', $src);
        self::assertDoesNotMatchRegularExpression(
            '/resolveThemeForScope\s*\(\s*PreviewContextService::AREA_FRONTEND\s*,\s*\$identity\s*\)/',
            $src,
            'resolveThemeForScope must not receive ScopeIdentity'
        );
        self::assertMatchesRegularExpression(
            '/resolveThemeForScope\s*\(\s*PreviewContextService::AREA_FRONTEND\s*,\s*\$application\s*\)/',
            $src
        );
    }

    public function testFallbackShellSkipsStaticSnapshotReload(): void
    {
        $src = $this->generatorSource();
        self::assertStringContainsString("'skip_static' => true", $src);
        self::assertStringContainsString(
            'ThemeApplicationContext::REQUEST_KEY_PREFIX',
            $src,
            'Must clear prior site ThemeApplicationContext between publishes'
        );
    }
}
