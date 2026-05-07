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

    public function testApiUserSchemaDeclaresInstallerBootstrapDefaults(): void
    {
        $schema = (new SchemaParser())->parse(ApiUser::class);

        self::assertNotNull($schema);

        $columns = [];
        foreach ($schema->columns as $column) {
            $columns[$column->name] = $column;
        }

        foreach ($this->defaultBootstrapFields() as $field => $default) {
            self::assertArrayHasKey($field, $columns);
            self::assertFalse($columns[$field]->nullable, $field . ' should be non-nullable.');
            self::assertSame($default, $columns[$field]->default, $field . ' default should match installer bootstrap.');
        }
    }

    public function testDefaultApiUserBootstrapPopulatesNonNullableValuesBeforeInsert(): void
    {
        $apiUser = new ApiUser();
        $apiUser->setUsername('admin')
            ->setEmail('admin@example.com')
            ->setPassword('admin')
            ->autoGenerateApiCredentials();

        $apiUser->save_before();

        foreach ($this->defaultBootstrapFields() as $field => $default) {
            self::assertSame($default, $apiUser->getData($field), $field . ' should be populated before insert.');
        }
    }

    private function defaultBootstrapFields(): array
    {
        return [
            ApiUser::schema_fields_token_expire_time => ApiUser::DEFAULT_TOKEN_EXPIRE_TIME,
            ApiUser::schema_fields_refresh_token_expire_time => ApiUser::DEFAULT_REFRESH_TOKEN_EXPIRE_TIME,
            ApiUser::schema_fields_is_enabled => ApiUser::DEFAULT_IS_ENABLED,
            ApiUser::schema_fields_is_deleted => ApiUser::DEFAULT_IS_DELETED,
            ApiUser::schema_fields_ip_whitelist_enabled => ApiUser::DEFAULT_IP_WHITELIST_ENABLED,
            ApiUser::schema_fields_allowed_ips => ApiUser::DEFAULT_ALLOWED_IPS,
            ApiUser::schema_fields_user_agent_restriction_enabled => ApiUser::DEFAULT_USER_AGENT_RESTRICTION_ENABLED,
            ApiUser::schema_fields_allowed_user_agents => ApiUser::DEFAULT_ALLOWED_USER_AGENTS,
            ApiUser::schema_fields_is_sandbox => ApiUser::DEFAULT_IS_SANDBOX,
        ];
    }
}
