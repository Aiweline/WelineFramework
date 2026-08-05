<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;

final class StoreChannelSchemaContractTest extends TestCase
{
    public function testStoreAndChannelDeclareTheirCompleteCompositeUniqueKeys(): void
    {
        $parser = new SchemaParser();
        $store = $parser->parse(Store::class);
        $channel = $parser->parse(SalesChannel::class);

        self::assertNotNull($store);
        self::assertNotNull($channel);
        self::assertTrue($this->hasUniqueIndex(
            $store->indexes,
            'uk_website_store_code',
            [Store::schema_fields_WEBSITE_ID, Store::schema_fields_CODE],
        ));
        self::assertTrue($this->hasUniqueIndex(
            $channel->indexes,
            'uk_store_channel_code',
            [SalesChannel::schema_fields_STORE_ID, SalesChannel::schema_fields_CODE],
        ));
        self::assertContains(Store::schema_fields_LIFECYCLE_STATUS, array_column(
            array_map('get_object_vars', $store->columns),
            'name',
        ));
        self::assertContains(Store::schema_fields_TOMBSTONED_AT, array_column(
            array_map('get_object_vars', $store->columns),
            'name',
        ));
    }

    public function testModuleAndRegistrationVersionsAreReleasedTogether(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $manifest = require $moduleRoot . '/etc/module.php';
        $registration = (string)file_get_contents($moduleRoot . '/register.php');

        self::assertSame('1.7.1', $manifest['version'] ?? null);
        self::assertMatchesRegularExpression(
            "/'Weline_Websites',\\s*__DIR__,\\s*'1\\.7\\.1'/s",
            $registration,
        );
        $index = (string)file_get_contents($moduleRoot . '/doc/AI-INDEX.md');
        self::assertStringContainsString('doc/store-saleschannel-scope.md', $index);
    }

    /** @param list<IndexDefinition> $indexes @param list<string> $columns */
    private function hasUniqueIndex(array $indexes, string $name, array $columns): bool
    {
        foreach ($indexes as $index) {
            if ($index->name === $name && $index->type === 'UNIQUE' && $index->columns === $columns) {
                return true;
            }
        }
        return false;
    }
}
