<?php

declare(strict_types=1);

namespace Weline\Api\test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Api\Model\ApiUser;
use Weline\Framework\Database\Schema\SchemaParser;

final class ApiUserSchemaTest extends TestCase
{
    public function testApiUserSchemaDeclaresInstallerTimestampColumns(): void
    {
        $schema = (new SchemaParser())->parse(ApiUser::class);

        self::assertNotNull($schema);

        $columnNames = [];
        foreach ($schema->columns as $column) {
            $columnNames[] = $column->name;
        }

        self::assertContains(ApiUser::schema_fields_created_at, $columnNames);
        self::assertContains(ApiUser::schema_fields_updated_at, $columnNames);
    }
}
