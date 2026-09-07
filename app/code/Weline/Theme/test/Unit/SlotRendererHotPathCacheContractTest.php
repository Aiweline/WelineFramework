<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SlotRenderer 热路径不得再打 theme_runtime SharedState（与 Partials chrome 策略对齐）。
 */
final class SlotRendererHotPathCacheContractTest extends TestCase
{
    private function readService(): string
    {
        $path = dirname(__DIR__, 2) . '/Service/SlotRendererService.php';
        $src = file_get_contents($path);
        self::assertIsString($src);

        return $src;
    }

    public function testRuntimeCacheGetSetAreHotPathNoOps(): void
    {
        $src = $this->readService();

        self::assertStringContainsString('Historical theme_runtime SharedState get/set burns ~200ms', $src);
        self::assertStringContainsString('private function runtimeCacheGet(string $key): mixed', $src);
        self::assertStringContainsString('private function runtimeCacheSet(string $key, mixed $value, int $ttl): void', $src);
        self::assertStringContainsString('return null;', $src);
        self::assertStringContainsString('unset($key, $value, $ttl);', $src);

        $getPos = strpos($src, 'private function runtimeCacheGet(string $key): mixed');
        $setPos = strpos($src, 'private function runtimeCacheSet(string $key, mixed $value, int $ttl): void');
        $runtimePos = strpos($src, 'private static function runtimeCache(): ?SharedCacheStateInterface');
        self::assertNotFalse($getPos);
        self::assertNotFalse($setPos);
        self::assertNotFalse($runtimePos);

        $getBody = substr($src, $getPos, $runtimePos - $getPos);
        self::assertStringNotContainsString("\$cache->get('theme_runtime'", $getBody);
        self::assertStringNotContainsString("\$cache->set('theme_runtime'", $getBody);
    }

    public function testPublishStillPurgesSharedThemeRuntimeNamespace(): void
    {
        $src = $this->readService();
        self::assertStringContainsString('function purgeRuntimeCacheNamespace(): void', $src);
        self::assertStringContainsString("\$cache->clearNamespace('theme_runtime')", $src);
    }
}
