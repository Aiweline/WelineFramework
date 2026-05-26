<?php
declare(strict_types=1);

namespace Weline\Api\test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Api\Model\ApiApp;
use Weline\Api\Model\ApiAppAuthorizationCode;
use Weline\Api\Model\ApiAppInstallation;
use Weline\Api\Model\ApiAppInstallationScope;
use Weline\Api\Model\ApiAppToken;
use Weline\Framework\Database\Schema\SchemaParser;

final class ApiAppSchemaTest extends TestCase
{
    public function testApiAppAuthorizationModelsDeclarePrimaryColumns(): void
    {
        $expected = [
            ApiApp::class => ApiApp::schema_fields_ID,
            ApiAppInstallation::class => ApiAppInstallation::schema_fields_ID,
            ApiAppInstallationScope::class => ApiAppInstallationScope::schema_fields_ID,
            ApiAppToken::class => ApiAppToken::schema_fields_ID,
            ApiAppAuthorizationCode::class => ApiAppAuthorizationCode::schema_fields_ID,
        ];

        foreach ($expected as $class => $primaryColumn) {
            $schema = (new SchemaParser())->parse($class);
            self::assertNotNull($schema, $class);

            $columns = [];
            foreach ($schema->columns as $column) {
                $columns[$column->name] = $column;
            }

            self::assertArrayHasKey($primaryColumn, $columns, $class);
            self::assertTrue($columns[$primaryColumn]->primaryKey, $class);
        }
    }

    public function testApiAppCredentialsHashAndVerifySecret(): void
    {
        $app = new ApiApp();
        $app->setClientSecret('plain-secret');

        self::assertNotSame('plain-secret', $app->getClientSecretHash());
        self::assertTrue($app->verifyClientSecret('plain-secret'));
        self::assertFalse($app->verifyClientSecret('wrong-secret'));
    }
}
