<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\ThemeUpdate;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\StaticBuildId;
use Weline\Framework\Http\Fpc\FpcHtmlDiskStore;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime;

final class ThemeUpdateRuntimePageGenerationTest extends TestCase
{
    protected function tearDown(): void
    {
        ThemeUpdateRuntime::reset();
    }

    public function testTuEpochChangesPageGenerationEvenWhenVersionAndBuildStay(): void
    {
        StaticBuildId::override('abc123def456');
        $base = [
            'batch_id' => 'tu-20261022-015300-ab12cd',
            'static_ns' => 'tu-20261022-015300-ab12cd',
            'entity_root' => 'tu-20261022-015300-ab12cd',
            'theme_prefix' => 'Weline/hanfu',
            'pair_segment' => 'hanfu/v3',
        ];

        ThemeUpdateRuntime::forceBatch($base + ['epoch' => 1]);
        $first = ThemeUpdateRuntime::pageGeneration();
        $firstToken = FpcHtmlDiskStore::pageGenerationToken();

        ThemeUpdateRuntime::forceBatch($base + ['epoch' => 2]);
        $second = ThemeUpdateRuntime::pageGeneration();
        $secondToken = FpcHtmlDiskStore::pageGenerationToken();

        self::assertStringContainsString('hanfu/v3~e1~abc123def456', $first);
        self::assertStringContainsString('hanfu/v3~e2~abc123def456', $second);
        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $firstToken);
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $secondToken);
        self::assertNotSame($firstToken, $secondToken);
    }

    public function testNoTuPointerKeepsLegacyPairBuildShape(): void
    {
        StaticBuildId::override('abc123def456');
        ThemeUpdateRuntime::forceBatch([
            'batch_id' => 'tu-20261022-015300-ab12cd',
            'static_ns' => 'tu-20261022-015300-ab12cd',
            'entity_root' => 'tu-20261022-015300-ab12cd',
            'theme_prefix' => 'Weline/hanfu',
            'pair_segment' => 'hanfu/v3',
            'epoch' => 0,
        ]);

        self::assertSame('hanfu/v3~abc123def456', ThemeUpdateRuntime::pageGeneration());
    }

    public function testEpochForThemeAndPointerCacheGenerationFollowForcedEpoch(): void
    {
        StaticBuildId::override('abc123def456');
        ThemeUpdateRuntime::forceBatch([
            'batch_id' => 'tu-20261022-015300-ab12cd',
            'static_ns' => 'tu-20261022-015300-ab12cd',
            'entity_root' => 'tu-20261022-015300-ab12cd',
            'theme_prefix' => 'Weline/hanfu',
            'pair_segment' => 'hanfu/v3',
            'epoch' => 5,
        ]);

        self::assertSame(5, ThemeUpdateRuntime::epochForTheme(1));
        self::assertSame('hanfu/v3~e5', ThemeUpdateRuntime::pointerCacheGeneration());
    }
}
