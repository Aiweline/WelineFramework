<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Api;

use PHPUnit\Framework\TestCase;
use Weline\Product\Api\Data\ProductAdminCommand;
use Weline\Product\Service\ProductRestInput;

final class ProductRestInputTest extends TestCase
{
    protected function setUp(): void
    {
        self::assertTrue(class_exists(ProductRestInput::class), '产品 REST 请求适配尚未实现');
    }

    public function testCreateUsesServerActionAndDoesNotImpersonateAnAdministrator(): void
    {
        $body = ProductRestInput::decode('{"website_id":0,"actor_id":999,"action":"publish","payload":{"name":"接口商品","sku":"REST-001"}}');
        $command = ProductRestInput::command(ProductAdminCommand::ACTION_CREATE, $body, 42);
        self::assertSame('create', $command->action);
        self::assertSame(0, $command->websiteId);
        self::assertSame(0, $command->actorId);
        self::assertNull($command->globalProductUuid);
        self::assertSame(['name' => '接口商品', 'sku' => 'REST-001'], $command->payload);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $command->requestHash);
    }

    public function testUpdatePreservesOnlySubmittedFieldsAndExistingVersionContract(): void
    {
        $body = ProductRestInput::decode('{"website_id":0,"global_product_uuid":"12345678-1234-4234-8234-123456789abc","payload":{"local_version":0,"name":"新名称"}}');
        $command = ProductRestInput::command(ProductAdminCommand::ACTION_SAVE, $body, 42);
        self::assertSame('save', $command->action);
        self::assertSame('12345678-1234-4234-8234-123456789abc', $command->globalProductUuid);
        self::assertSame(['local_version' => 0, 'name' => '新名称'], $command->payload);
        self::assertArrayNotHasKey('media_assignments', $command->payload);
        self::assertArrayNotHasKey('store_ids', $command->payload);
    }

    public function testCreateRequestHashIsStableWithinAndIsolatedBetweenInstallations(): void
    {
        $body = ['website_id' => 0, 'request_hash' => str_repeat('a', 64), 'payload' => ['name' => '商品', 'sku' => 'R-1']];
        $first = ProductRestInput::command('create', $body, 42)->requestHash;
        self::assertSame($first, ProductRestInput::command('create', $body, 42)->requestHash);
        self::assertNotSame($first, ProductRestInput::command('create', $body, 43)->requestHash);
        $body['website_id'] = 1;
        self::assertNotSame($first, ProductRestInput::command('create', $body, 42)->requestHash);
    }

    public function testMalformedOrNonObjectJsonIsRejected(): void
    {
        foreach (['{', '[]', 'null', '"text"'] as $json) {
            try {
                ProductRestInput::decode($json);
                self::fail('无效 JSON 必须被拒绝');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('product_api_json_object_required', $exception->getMessage());
            }
        }
    }

    public function testInvalidWebsiteAndVersionCannotBeCoercedIntoAnotherScope(): void
    {
        foreach ([-1, 0.5, true, '0oops', '999999999999999999999999'] as $value) {
            try {
                ProductRestInput::nonNegativeInt($value, 'website_id');
                self::fail('不合法的站点 ID 必须被拒绝');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('product_api_website_id_invalid', $exception->getMessage());
            }
        }
        $this->expectExceptionMessage('product_api_local_version_invalid');
        ProductRestInput::command('save', [
            'website_id' => 0,
            'global_product_uuid' => '12345678-1234-4234-8234-123456789abc',
            'payload' => ['local_version' => '1oops', 'name' => '不可写入'],
        ], 42);
    }

    public function testMissingEditVersionUsesExistingRequiredVersionFailure(): void
    {
        $this->expectExceptionMessage('product_admin_local_version_required');
        ProductRestInput::command('save', [
            'website_id' => 0,
            'global_product_uuid' => '12345678-1234-4234-8234-123456789abc',
            'payload' => ['name' => '不可覆盖'],
        ], 42);
    }

    public function testPublishMapsProductIdentityAndBothOptimisticVersions(): void
    {
        $command = ProductRestInput::command(ProductAdminCommand::ACTION_PUBLISH, [
            'website_id' => 0,
            'global_product_uuid' => '12345678-1234-4234-8234-123456789abc',
            'expected_version' => 3,
            'payload' => ['local_version' => 7, 'locale' => 'en_US', 'currency' => 'CNY'],
        ], 42);
        self::assertSame('publish', $command->action);
        self::assertSame('12345678-1234-4234-8234-123456789abc', $command->globalProductUuid);
        self::assertSame(3, $command->expectedVersion);
        self::assertSame(['local_version' => 7, 'locale' => 'en_US', 'currency' => 'CNY'], $command->payload);
    }

    public function testPublishRequiresTheCurrentGlobalIdentityVersion(): void
    {
        $this->expectExceptionMessage('product_admin_expected_version_required');
        ProductRestInput::command(ProductAdminCommand::ACTION_PUBLISH, [
            'website_id' => 0,
            'global_product_uuid' => '12345678-1234-4234-8234-123456789abc',
            'payload' => ['local_version' => 7],
        ], 42);
    }

    public function testPublishCannotCoerceEitherOptimisticVersion(): void
    {
        foreach ([['expected_version' => '3oops', 'local_version' => 7, 'error' => 'product_api_expected_version_invalid'],
            ['expected_version' => 3, 'local_version' => '7oops', 'error' => 'product_api_local_version_invalid']] as $case) {
            try {
                ProductRestInput::command(ProductAdminCommand::ACTION_PUBLISH, [
                    'website_id' => 0,
                    'global_product_uuid' => '12345678-1234-4234-8234-123456789abc',
                    'expected_version' => $case['expected_version'],
                    'payload' => ['local_version' => $case['local_version']],
                ], 42);
                self::fail('发布不能使用强制转换的版本');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($case['error'], $exception->getMessage());
            }
        }
    }

    public function testApiUserIdempotencyIsStableAndIsolatedFromOtherUsersAndApplications(): void
    {
        $body = ['website_id' => 0, 'request_hash' => str_repeat('b', 64), 'payload' => ['name' => '商品', 'sku' => 'R-USER']];
        $first = ProductRestInput::command('create', $body, 0, 'api_user:42');
        self::assertSame($first->requestHash, ProductRestInput::command('create', $body, 0, 'api_user:42')->requestHash);
        self::assertNotSame($first->requestHash, ProductRestInput::command('create', $body, 0, 'api_user:43')->requestHash);
        self::assertNotSame($first->requestHash, ProductRestInput::command('create', $body, 42)->requestHash);
        self::assertSame(0, $first->actorId);
        self::assertSame(hash('sha256', 'product-rest:42:0:create:' . str_repeat('b', 64)), ProductRestInput::command('create', $body, 42)->requestHash);
    }
}
