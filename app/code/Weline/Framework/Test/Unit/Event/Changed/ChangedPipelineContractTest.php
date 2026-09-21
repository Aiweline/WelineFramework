<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Event\Changed;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Changed\ChangedCapabilityInterface;
use Weline\Framework\Event\Changed\ChangedTypeInterface;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Product\Extends\Module\Weline_Framework\Changed\Type\ProductSearchProjectionChangedType;
use Weline\Theme\Extends\Module\Weline_Framework\Changed\Type\ThemeChangedType;

final class ChangedPipelineContractTest extends TestCase
{
    public function testEffectPhasesFrozen(): void
    {
        $sync = new InvalidationEffect(InvalidationEffect::CODE_BUMP_NAMESPACES, InvalidationEffect::PHASE_SYNC);
        $after = new InvalidationEffect(InvalidationEffect::CODE_PURGE_FPC_URLS, InvalidationEffect::PHASE_AFTER_COMMIT);
        self::assertSame('sync', $sync->phase);
        self::assertSame('after_commit', $after->phase);
    }

    public function testInvalidPhaseRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InvalidationEffect('x', 'later');
    }

    public function testProductRecipeRequiresUrlsMaterialOnUpsert(): void
    {
        $type = new ProductSearchProjectionChangedType();
        self::assertSame('product_search_projection', $type->code());
        $change = $this->change('product_search_projection', 'upsert', [
            'namespaces' => ['website/default/catalog'],
            'urls' => ['https://example.test/p/1'],
            'previous_urls' => [],
        ]);
        $effects = $type->recipe($change);
        $codes = array_map(static fn(InvalidationEffect $e) => $e->code, $effects);
        self::assertContains(InvalidationEffect::CODE_BUMP_NAMESPACES, $codes);
        self::assertContains(InvalidationEffect::CODE_PURGE_FPC_URLS, $codes);
        self::assertContains(InvalidationEffect::CODE_CDN_PURGE, $codes);
    }

    public function testThemePublishUsesPurgeAllWithWhitelistModule(): void
    {
        $type = new ThemeChangedType();
        $change = $this->change('theme', 'publish', [
            'namespaces' => ['website/default/theme'],
            'urls' => [],
            'previous_urls' => [],
        ]);
        $effects = $type->recipe($change);
        $purgeAll = null;
        foreach ($effects as $effect) {
            if ($effect->code === InvalidationEffect::CODE_PURGE_FPC_ALL) {
                $purgeAll = $effect;
            }
        }
        self::assertInstanceOf(InvalidationEffect::class, $purgeAll);
        self::assertSame('Weline_Theme', $purgeAll->payload['source_module'] ?? null);
    }

    public function testThemePreviewForbidsPurgeAll(): void
    {
        $type = new ThemeChangedType();
        $change = ResourceChange::fromArray(array_replace_recursive($this->basePayload('theme', 'publish'), [
            'after' => ['preview' => true],
            'impact' => ['namespaces' => ['website/default/theme'], 'urls' => [], 'previous_urls' => [], 'previous_namespaces' => []],
        ]));
        $codes = array_map(
            static fn(InvalidationEffect $e) => $e->code,
            $type->recipe($change),
        );
        self::assertNotContains(InvalidationEffect::CODE_PURGE_FPC_ALL, $codes);
    }

    public function testHardCutCacheObserversRemoved(): void
    {
        $root = dirname(__DIR__, 5);
        self::assertFileDoesNotExist($root . '/Event/ResourceChange/Observer/CacheNamespaceObserver.php');
        self::assertFileDoesNotExist($root . '/Event/ResourceChange/Observer/CacheImpactObserver.php');
        self::assertStringContainsString('changed/capability', ChangedCapabilityInterface::EXTENDS_RELATIVE_PREFIX);
        self::assertStringContainsString('changed/type', ChangedTypeInterface::EXTENDS_RELATIVE_PREFIX);
    }

    /** @param array<string,mixed> $impact */
    private function change(string $type, string $action, array $impact): ResourceChange
    {
        return ResourceChange::fromArray(array_replace_recursive($this->basePayload($type, $action), [
            'impact' => $impact + ['previous_namespaces' => []],
        ]));
    }

    /** @return array<string,mixed> */
    private function basePayload(string $type, string $action): array
    {
        return [
            'schema_version' => 1,
            'event_id' => str_repeat('ab', 16),
            'event_name' => ResourceChange::EVENT_NAME,
            'occurred_at' => '2026-09-21T02:00:00.000000Z',
            'resource' => ['type' => $type, 'id' => '1', 'action' => $action, 'revision' => 1],
            'website' => ['id' => 0, 'code' => 'default', 'previous_code' => null, 'site_id' => 0],
            'impact' => [
                'namespaces' => [],
                'previous_namespaces' => [],
                'urls' => [],
                'previous_urls' => [],
            ],
            'changed_fields' => ['x'],
            'before' => [],
            'after' => ['status' => 'published'],
            'origin' => [
                'area' => 'backend',
                'entry' => 'test',
                'request_id' => '',
                'instance' => '',
                'trigger_by' => ['type' => 'system', 'id' => null],
            ],
            'context' => [
                'website_id' => 0,
                'website_code' => 'default',
                'lang' => 'zh_Hans_CN',
                'currency' => 'CNY',
                'area' => 'backend',
                'timezone' => 'UTC',
                'user' => ['type' => 'system', 'id' => null],
            ],
        ];
    }
}
