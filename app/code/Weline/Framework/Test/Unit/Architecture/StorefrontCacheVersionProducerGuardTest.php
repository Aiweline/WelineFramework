<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use Weline\Currency\Model\Currency;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;

final class StorefrontCacheVersionProducerGuardTest extends TestCase
{
    public function testStoreAndChannelWritesOwnCatalogGeneration(): void
    {
        $storeSave = $this->methodSource(Store::class, 'save_after');
        self::assertStringContainsString('WebsiteCacheInvalidationService', $storeSave);
        self::assertStringContainsString("['catalog']", $storeSave);

        $channelSave = $this->methodSource(SalesChannel::class, 'save_after');
        $channelDelete = $this->methodSource(SalesChannel::class, 'delete_after');
        $channelOwnerSave = $this->methodSource(SalesChannel::class, 'save');
        $channelOwnerDelete = $this->methodSource(SalesChannel::class, 'delete');
        self::assertStringContainsString('invalidateCatalog', $channelSave);
        self::assertStringContainsString('invalidateCatalog', $channelDelete);
        self::assertStringContainsString('TransactionCoordinatorInterface::class', $channelOwnerSave);
        self::assertStringContainsString('TransactionCoordinatorInterface::class', $channelOwnerDelete);
    }

    public function testCurrencyAndThemeWritesOwnAggregateGenerations(): void
    {
        $currency = $this->methodSource(Currency::class, 'invalidateStorefrontPriceVersion');
        $currencyOwnerSave = $this->methodSource(Currency::class, 'save');
        $currencyOwnerDelete = $this->methodSource(Currency::class, 'delete');
        self::assertStringContainsString("global('storefront', ['price'])", $currency);
        self::assertStringContainsString('NamespaceGenerationInterface::class', $currency);
        self::assertStringContainsString('TransactionCoordinatorInterface::class', $currencyOwnerSave);
        self::assertStringContainsString('TransactionCoordinatorInterface::class', $currencyOwnerDelete);

        $theme = $this->methodSource(ThemeRuntimeCacheCleaner::class, 'clearNonGlobalCaches');
        self::assertStringContainsString("global('storefront', ['theme'])", $theme);
        self::assertStringContainsString('NamespaceGenerationInterface::class', $theme);
        self::assertStringContainsString("'storefront_theme_generation'", $theme);
        self::assertStringContainsString('runStep', $theme);
    }

    public function testThemeGenerationFailureDoesNotAbortFollowingCleanupSteps(): void
    {
        $cleaner = new ThemeRuntimeCacheCleaner();
        $runStep = new \ReflectionMethod($cleaner, 'runStep');
        $result = ['steps' => [], 'failures' => []];

        $failingArgs = [
            &$result,
            'storefront_theme_generation',
            static fn(): never => throw new \RuntimeException('generation unavailable'),
        ];
        $runStep->invokeArgs($cleaner, $failingArgs);

        $continued = false;
        $nextArgs = [
            &$result,
            'framework_non_global_pools',
            static function () use (&$continued): void {
                $continued = true;
            },
        ];
        $runStep->invokeArgs($cleaner, $nextArgs);

        self::assertFalse($result['steps']['storefront_theme_generation']);
        self::assertSame('generation unavailable', $result['failures']['storefront_theme_generation']);
        self::assertTrue($continued);
        self::assertTrue($result['steps']['framework_non_global_pools']);
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $file = $reflection->getFileName();
        self::assertIsString($file);
        $lines = file($file);
        self::assertIsArray($lines);
        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
