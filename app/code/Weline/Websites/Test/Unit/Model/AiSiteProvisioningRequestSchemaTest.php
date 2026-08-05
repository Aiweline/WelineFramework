<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\SchemaDiffEngine;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Websites\Model\AiSiteProvisioningRequest;

final class AiSiteProvisioningRequestSchemaTest extends TestCase
{
    public function testDeclarativeSchemaCreatesTheProvisioningTableOnce(): void
    {
        $schema = (new SchemaParser())->parse(AiSiteProvisioningRequest::class);
        self::assertNotNull($schema);
        self::assertSame(
            AiSiteProvisioningRequest::schema_table,
            \trim($schema->tableName, "`\""),
        );

        $firstRun = (new SchemaDiffEngine())->diff($schema, null);
        self::assertCount(1, $firstRun);
        self::assertSame(SchemaDiffOp::KIND_CREATE_TABLE, $firstRun[0]->kind);
        self::assertSame([], (new SchemaDiffEngine())->diff($schema, $schema));
    }

    public function testProvisioningSchemaKeepsDefaultWebsiteZeroDistinctFromUnbound(): void
    {
        $schema = (new SchemaParser())->parse(AiSiteProvisioningRequest::class);
        self::assertNotNull($schema);
        $columns = [];
        foreach ($schema->columns as $column) {
            $columns[$column->name] = $column;
        }

        self::assertFalse($columns[AiSiteProvisioningRequest::schema_fields_ADMIN_USER_ID]->nullable);
        self::assertTrue($columns[AiSiteProvisioningRequest::schema_fields_REQUESTED_WEBSITE_ID]->nullable);
        self::assertSame(0, $columns[AiSiteProvisioningRequest::schema_fields_PURCHASE_CONFIRMED]->default);
        self::assertSame(0, $columns[AiSiteProvisioningRequest::schema_fields_PURCHASE_ATTEMPTED]->default);
        self::assertSame(0, $columns[AiSiteProvisioningRequest::schema_fields_PURCHASE_ORDER_ID]->default);
        self::assertSame(0, $columns[AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND]->default);
        self::assertSame(0, $columns[AiSiteProvisioningRequest::schema_fields_WEBSITE_ID]->default);

        $indexes = [];
        foreach ($schema->indexes as $index) {
            $indexes[$index->name] = $index;
        }
        self::assertSame(
            [
                AiSiteProvisioningRequest::schema_fields_SOURCE_MODULE,
                AiSiteProvisioningRequest::schema_fields_CLIENT_REQUEST_ID,
            ],
            $indexes['uk_ai_site_provisioning_source_command']->columns,
        );
        self::assertSame('UNIQUE', $indexes['uk_ai_site_provisioning_source_command']->type);
        self::assertSame(
            [AiSiteProvisioningRequest::schema_fields_ADMIN_USER_ID],
            $indexes['idx_ai_site_provisioning_admin']->columns,
        );
    }
}
