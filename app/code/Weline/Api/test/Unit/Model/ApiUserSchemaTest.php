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

    public function testApiUserSchemaDeclaresInstallerExpiryDefaults(): void
    {
        $schema = (new SchemaParser())->parse(ApiUser::class);

        self::assertNotNull($schema);

        $columns = [];
        foreach ($schema->columns as $column) {
            $columns[$column->name] = $column;
        }

        self::assertArrayHasKey(ApiUser::schema_fields_token_expire_time, $columns);
        self::assertArrayHasKey(ApiUser::schema_fields_refresh_token_expire_time, $columns);
        self::assertFalse($columns[ApiUser::schema_fields_token_expire_time]->nullable);
        self::assertFalse($columns[ApiUser::schema_fields_refresh_token_expire_time]->nullable);
        self::assertSame(ApiUser::DEFAULT_TOKEN_EXPIRE_TIME, $columns[ApiUser::schema_fields_token_expire_time]->default);
        self::assertSame(ApiUser::DEFAULT_REFRESH_TOKEN_EXPIRE_TIME, $columns[ApiUser::schema_fields_refresh_token_expire_time]->default);
    }

    public function testDefaultApiUserBootstrapPopulatesExpiryValuesBeforeInsert(): void
    {
        $apiUser = new ApiUser();
        $apiUser->setUsername('admin')
            ->setEmail('admin@example.com')
            ->setPassword('admin')
            ->autoGenerateApiCredentials();

        $apiUser->save_before();

        self::assertSame(ApiUser::DEFAULT_TOKEN_EXPIRE_TIME, $apiUser->getData(ApiUser::schema_fields_token_expire_time));
        self::assertSame(ApiUser::DEFAULT_REFRESH_TOKEN_EXPIRE_TIME, $apiUser->getData(ApiUser::schema_fields_refresh_token_expire_time));
    }
}
