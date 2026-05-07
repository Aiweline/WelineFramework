<?php

declare(strict_types=1);

namespace Weline\AutoLeadAgent\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\AutoLeadAgent\Model\TargetWebsite;
use Weline\Framework\Database\Schema\SchemaParser;

final class TargetWebsiteSchemaTest extends TestCase
{
    public function testTargetWebsiteSchemaDeclaresRequiredTimestampColumns(): void
    {
        $schema = (new SchemaParser())->parse(TargetWebsite::class);

        self::assertNotNull($schema);

        $columns = [];
        foreach ($schema->columns as $column) {
            $columns[$column->name] = $column;
        }

        foreach ([TargetWebsite::schema_fields_CREATED_AT, TargetWebsite::schema_fields_UPDATED_AT] as $field) {
            self::assertArrayHasKey($field, $columns);
            self::assertFalse($columns[$field]->nullable, $field . ' should remain non-nullable.');
        }
    }

    public function testSaveBeforePopulatesRequiredTimestamps(): void
    {
        $targetWebsite = new TargetWebsite();
        $targetWebsite->save_before();

        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string)$targetWebsite->getData(TargetWebsite::schema_fields_CREATED_AT)
        );
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string)$targetWebsite->getData(TargetWebsite::schema_fields_UPDATED_AT)
        );
    }

    public function testSaveBeforePreservesExistingCreatedAt(): void
    {
        $targetWebsite = new TargetWebsite();
        $createdAt = '2026-01-01 00:00:00';

        $targetWebsite->setData(TargetWebsite::schema_fields_CREATED_AT, $createdAt);
        $targetWebsite->save_before();

        self::assertSame($createdAt, $targetWebsite->getData(TargetWebsite::schema_fields_CREATED_AT));
        self::assertNotEmpty($targetWebsite->getData(TargetWebsite::schema_fields_UPDATED_AT));
    }
}
