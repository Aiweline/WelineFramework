<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\ThemeUpdate;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Model\ThemeUpdateBatch;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateBatchService;

/**
 * 代次目录/配置键的契约测试（不依赖 DB）。
 */
final class ThemeUpdateBatchServiceContractTest extends TestCase
{
    public function testStaticRootUsesBatchNamespace(): void
    {
        $root = ThemeUpdateBatchService::staticRoot('tu-20261022-015300-ab12cd', '/tmp/pub-static');

        self::assertSame('/tmp/pub-static/tu-20261022-015300-ab12cd', str_replace('\\', '/', $root));
    }

    public function testEntityRootLivesUnderThemeLayoutEntities(): void
    {
        $root = str_replace('\\', '/', ThemeUpdateBatchService::entityRoot('tu-20261022-015300-ab12cd', '/tmp/entities'));

        self::assertSame('/tmp/entities/tu-20261022-015300-ab12cd', $root);
    }

    public function testPointerConfigKeyIsStable(): void
    {
        self::assertSame('0|3|frontend', ThemeUpdateBatchService::pointerConfigKey(0, 3));
        self::assertSame('9|3|backend', ThemeUpdateBatchService::pointerConfigKey(9, 3, 'backend'));
        self::assertSame('0|0|frontend', ThemeUpdateBatchService::pointerConfigKey(-5, -1));
    }

    public function testPointerConfigValueCarriesBatchAndEpoch(): void
    {
        $batch = new ThemeUpdateBatch();
        $batch->setData([
            ThemeUpdateBatch::schema_fields_BATCH_ID => 'tu-20261022-015300-ab12cd',
            ThemeUpdateBatch::schema_fields_STATIC_NS => 'tu-20261022-015300-ab12cd',
            ThemeUpdateBatch::schema_fields_ENTITY_ROOT => 'tu-20261022-015300-ab12cd',
        ]);

        self::assertSame([
            'batch_id' => 'tu-20261022-015300-ab12cd',
            'static_ns' => 'tu-20261022-015300-ab12cd',
            'entity_root' => 'tu-20261022-015300-ab12cd',
            'theme_prefix' => '',
            'epoch' => 7,
        ], ThemeUpdateBatchService::pointerConfigValue($batch, 7));

        // epoch 至少为 1（0/负数被纠正）
        self::assertSame(1, ThemeUpdateBatchService::pointerConfigValue($batch, 0)['epoch']);

        // theme_prefix 来自 batch.json（运行时用它识别「主题自有资源」）
        $batch->setData([
            ThemeUpdateBatch::schema_fields_MANIFEST_JSON => '{"theme_prefix":"Weline/hanfu","verify":{"ok":true}}',
        ]);
        $value = ThemeUpdateBatchService::pointerConfigValue($batch, 3);
        self::assertSame('Weline/hanfu', $value['theme_prefix']);
        self::assertSame(3, $value['epoch']);
    }

    public function testBatchServableRequiresVerifiedManifest(): void
    {
        $batch = new ThemeUpdateBatch();
        $batch->setData([
            ThemeUpdateBatch::schema_fields_STATUS => ThemeUpdateBatch::STATUS_VERIFIED,
            ThemeUpdateBatch::schema_fields_MANIFEST_JSON => '{"verify":{"ok":true}}',
        ]);
        self::assertTrue($batch->isServable());
        self::assertTrue($batch->isManifestVerified());

        $failed = new ThemeUpdateBatch();
        $failed->setData([
            ThemeUpdateBatch::schema_fields_STATUS => ThemeUpdateBatch::STATUS_FAILED,
            ThemeUpdateBatch::schema_fields_MANIFEST_JSON => '{"verify":{"ok":false}}',
        ]);
        self::assertFalse($failed->isServable());
        self::assertFalse($failed->isManifestVerified());

        // 曾被切换过的代次（superseded）可以作为 promote 目标再切回
        $superseded = new ThemeUpdateBatch();
        $superseded->setData([
            ThemeUpdateBatch::schema_fields_STATUS => ThemeUpdateBatch::STATUS_SUPERSEDED,
            ThemeUpdateBatch::schema_fields_MANIFEST_JSON => '{"verify":{"ok":true}}',
        ]);
        self::assertTrue($superseded->isServable());
        self::assertTrue($superseded->isRollbackEligible());
    }
}
