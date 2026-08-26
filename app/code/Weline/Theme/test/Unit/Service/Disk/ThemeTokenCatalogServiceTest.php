<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Disk;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\Disk\ThemeDiskEditorService;
use Weline\Theme\Service\Disk\ThemeTokenCatalogService;

final class ThemeTokenCatalogServiceTest extends TestCore
{
    public function testCatalogSummaryOmitsDiskTokensForEditorTransport(): void
    {
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->clearData()->clearQuery()->getActiveTheme('frontend');

        $catalog = ObjectManager::getInstance(ThemeTokenCatalogService::class)
            ->getCatalog('frontend', $theme, false);

        self::assertNotSame([], $catalog['disks'] ?? []);
        foreach ($catalog['disks'] as $disk) {
            self::assertIsArray($disk);
            self::assertSame([], $disk['tokens'] ?? null);
            self::assertGreaterThan(0, (int)($disk['token_count'] ?? 0));
        }

        $payload = ObjectManager::getInstance(ThemeDiskEditorService::class)
            ->getTokensPayload($theme, 'frontend', 'default');
        self::assertLessThanOrEqual(200, $this->maxListCount($payload));
    }

    public function testDiskTokensCanBeLoadedByRef(): void
    {
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->clearData()->clearQuery()->getActiveTheme('frontend');

        $catalog = ObjectManager::getInstance(ThemeTokenCatalogService::class)
            ->getCatalog('frontend', $theme, false);
        $disk = $catalog['panels']['color']['disks'][0] ?? null;
        self::assertIsArray($disk);

        $tokens = ObjectManager::getInstance(ThemeTokenCatalogService::class)
            ->getDiskTokensForRef('frontend', $theme, 'color', (string)$disk['ref']);
        self::assertNotSame([], $tokens);
        self::assertSame((int)$disk['token_count'], \count($tokens));
    }

    /** @param mixed $value */
    private function maxListCount(mixed $value): int
    {
        if (!\is_array($value)) {
            return 0;
        }

        $max = \array_is_list($value) ? \count($value) : 0;
        foreach ($value as $item) {
            $max = \max($max, $this->maxListCount($item));
        }

        return $max;
    }
}
