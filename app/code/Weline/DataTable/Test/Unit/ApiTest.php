<?php

declare(strict_types=1);

namespace Weline\DataTable\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\DataTable\Api\Rest\V1\DataTable;
use Weline\DataTable\Exception\DataTableException;
use Weline\DataTable\Helper\ErrorHandler;
use Weline\DataTable\Helper\PermissionManager;
use Weline\DataTable\Helper\ValidationManager;

final class ApiTest extends TestCase
{
    public function testDataTableException(): void
    {
        $exception = DataTableException::modelNotFound('TestModel');
        self::assertEquals(DataTableException::CODE_MODEL_NOT_FOUND, $exception->getCode());
        self::assertStringContainsString('模型类不存在', $exception->getMessage());
    }

    public function testErrorHandler(): void
    {
        $exception = new \Exception('测试异常');
        $result = ErrorHandler::handleException($exception, 'test');

        self::assertIsArray($result);
        self::assertArrayHasKey('code', $result);
        self::assertArrayHasKey('msg', $result);
    }

    public function testValidationManager(): void
    {
        $data = [
            'email' => 'test@example.com',
            'age' => 25,
        ];

        $rules = [
            'email' => [
                ValidationManager::RULE_REQUIRED => true,
                ValidationManager::RULE_EMAIL => true,
            ],
            'age' => [
                ValidationManager::RULE_REQUIRED => true,
                ValidationManager::RULE_INTEGER => true,
                ValidationManager::RULE_MIN => 18,
                ValidationManager::RULE_MAX => 100,
            ],
        ];

        $result = ValidationManager::validate($data, $rules);
        self::assertIsArray($result);
        self::assertArrayHasKey('valid', $result);
        self::assertArrayHasKey('errors', $result);
    }

    public function testPermissionManager(): void
    {
        self::assertIsBool(PermissionManager::canViewField('TestModel', 'name'));
        self::assertIsBool(PermissionManager::canEditField('TestModel', 'name'));
        self::assertIsBool(PermissionManager::canPerformAction('TestModel', PermissionManager::PERMISSION_CREATE));
    }

    public function testDataTableFieldTypeValidation(): void
    {
        $dataTableApi = new DataTable();
        $reflection = new \ReflectionClass($dataTableApi);

        $isNumericMethod = $reflection->getMethod('isNumericField');
        $isNumericMethod->setAccessible(true);
        self::assertTrue($isNumericMethod->invoke($dataTableApi, 'id'));
        self::assertTrue($isNumericMethod->invoke($dataTableApi, 'price'));
        self::assertFalse($isNumericMethod->invoke($dataTableApi, 'name'));

        $isDateMethod = $reflection->getMethod('isDateField');
        $isDateMethod->setAccessible(true);
        self::assertTrue($isDateMethod->invoke($dataTableApi, 'created_at'));
        self::assertTrue($isDateMethod->invoke($dataTableApi, 'updated_at'));
        self::assertFalse($isDateMethod->invoke($dataTableApi, 'email'));
    }
}
