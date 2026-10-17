<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Changed;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Product\Extends\Module\Weline_Framework\Changed\Type\CatalogCategoryChangedType;
use Weline\Product\Service\CatalogCategoryChangedPublisher;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllProductChangedContractsPass().
 */
final class ProductChangedContractsTest extends TestCase
{
    public function testAllProductChangedContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'CatalogCategoryChangedType::testRecipePurgesFpcWhenUrlsPresent' => function () {
            $this->assert_CatalogCategoryChangedType_testRecipePurgesFpcWhenUrlsPresent();
        },
            'CatalogCategoryChangedType::testNotifyCategoryChangedWiresPublisher' => function () {
            $this->assert_CatalogCategoryChangedType_testNotifyCategoryChangedWiresPublisher();
        },
        ];
    }

private function assert_CatalogCategoryChangedType_testRecipePurgesFpcWhenUrlsPresent(): void
{
    $type = new CatalogCategoryChangedType();
    self::assertSame('catalog_category', $type->code());
    $change = ResourceChange::fromArray(['schema_version' => 1, 'event_id' => \str_repeat('cd', 16), 'event_name' => ResourceChange::EVENT_NAME, 'occurred_at' => '2026-10-11T00:00:00.000000Z', 'resource' => ['type' => 'catalog_category', 'id' => '0:1', 'action' => 'upsert', 'revision' => 1], 'website' => ['id' => 0, 'code' => 'default', 'previous_code' => null, 'site_id' => 0], 'impact' => ['namespaces' => ['website/default/catalog'], 'previous_namespaces' => [], 'urls' => ['/', '/categories', '/category/hanfu'], 'previous_urls' => []], 'changed_fields' => ['reason'], 'before' => [], 'after' => ['reason' => 'test'], 'origin' => ['area' => 'backend', 'entry' => 'test', 'request_id' => '', 'instance' => '', 'trigger_by' => ['type' => 'system', 'id' => null]], 'context' => ['website_id' => 0, 'website_code' => 'default', 'lang' => 'zh_Hans_CN', 'currency' => 'CNY', 'area' => 'backend', 'timezone' => 'UTC', 'user' => ['type' => 'system', 'id' => null]]]);
    $codes = \array_map(static fn(InvalidationEffect $e): string => $e->code, $type->recipe($change));
    self::assertContains(InvalidationEffect::CODE_BUMP_NAMESPACES, $codes);
    self::assertContains(InvalidationEffect::CODE_PURGE_FPC_URLS, $codes);
    self::assertContains(InvalidationEffect::CODE_CDN_PURGE, $codes);
}

private function assert_CatalogCategoryChangedType_testNotifyCategoryChangedWiresPublisher(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontCatalogCacheCoordinator.php');
    self::assertStringContainsString('CatalogCategoryChangedPublisher', $src);
    self::assertStringContainsString('->publish($websiteId, $reason, $categoryId)', $src);
    self::assertTrue(\class_exists(CatalogCategoryChangedPublisher::class));
    self::assertTrue(\class_exists(StorefrontCatalogCacheCoordinator::class));
}
}
