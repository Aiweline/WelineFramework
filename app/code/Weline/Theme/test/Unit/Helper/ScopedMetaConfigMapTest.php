<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Meta\Api\Data\MetaConfigRecord;
use Weline\Theme\Helper\ScopedMetaConfigMap;

final class ScopedMetaConfigMapTest extends TestCase
{
    public function testChildScopeOverridesParentAndKeepsParentOnlyKeys(): void
    {
        $records = [
            $this->record('default', 'a', null, 'parent-null'),
            $this->record('default', 'b', null, 'parent-only'),
            $this->record('theme', 'a', 'it_IT', 'child-it'),
        ];

        self::assertSame(
            ['a' => 'child-it', 'b' => 'parent-only'],
            ScopedMetaConfigMap::merge($records, ['theme', 'default'], 'it_IT'),
        );
    }

    public function testScopePrecedenceWinsBeforeLocaleFallback(): void
    {
        $records = [
            $this->record('default', 'a', 'zh_Hans_CN', 'parent-zh'),
            $this->record('theme', 'a', null, 'child-null'),
        ];

        self::assertSame(
            ['a' => 'child-null'],
            ScopedMetaConfigMap::merge($records, ['theme', 'default'], 'zh_Hans_CN'),
        );
    }

    public function testLocaleFallbackPrefersRequestedThenChineseThenNull(): void
    {
        $records = [
            $this->record('default', 'a', null, 'null'),
            $this->record('default', 'b', 'zh_Hans_CN', 'zh'),
            $this->record('default', 'c', 'it_IT', 'it'),
            $this->record('default', 'd', 'fr_FR', 'fr'),
        ];

        self::assertSame(
            ['a' => 'null', 'b' => 'zh', 'c' => 'it'],
            ScopedMetaConfigMap::merge($records, ['default'], 'it_IT'),
        );
    }

    public function testEqualRankKeepsTheFirstRecord(): void
    {
        $records = [
            $this->record('default', 'a', 'it_IT', 'first'),
            $this->record('default', 'a', 'it_IT', 'second'),
        ];

        self::assertSame(
            ['a' => 'first'],
            ScopedMetaConfigMap::merge($records, ['default'], 'it_IT'),
        );
    }

    public function testScopesOutsideTheChainAreIgnored(): void
    {
        $records = [
            $this->record('draft', 'a', null, 'draft'),
            $this->record('default', 'a', null, 'default'),
        ];

        self::assertSame(
            ['a' => 'default'],
            ScopedMetaConfigMap::merge($records, ['theme', 'default'], 'it_IT'),
        );
    }

    public function testEmptyChainOrRecordsReturnEmptyMap(): void
    {
        self::assertSame([], ScopedMetaConfigMap::merge([], ['default'], 'it_IT'));
        self::assertSame([], ScopedMetaConfigMap::merge(
            [$this->record('default', 'a', null, 'value')],
            [],
            'it_IT',
        ));
    }

    private function record(
        string $scope,
        string $configKey,
        ?string $locale,
        string $value,
    ): MetaConfigRecord {
        return new MetaConfigRecord(
            id: 1,
            namespace: 'theme.frontend',
            configKey: $configKey,
            value: $value,
            scope: $scope,
            locale: $locale,
            identifyId: '42',
            metaId: null,
            metaIdentify: null,
        );
    }
}
