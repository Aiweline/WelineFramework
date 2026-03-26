<?php

declare(strict_types=1);

namespace Weline\DataTable\Test\Unit;

use ReflectionMethod;
use Weline\DataTable\Service\DemoTableService;
use Weline\Framework\UnitTest\TestCore;

class DemoTableServiceTest extends TestCore
{
    /**
     * @dataProvider resolveFieldTypeProvider
     */
    public function testResolveFieldTypePrefersSemanticWidgetTypes(string $fieldName, string $dbType, string $expectedType): void
    {
        $service = new DemoTableService();
        $method = new ReflectionMethod(DemoTableService::class, 'resolveFieldType');
        $method->setAccessible(true);

        $actualType = $method->invoke($service, $fieldName, $dbType);

        $this->assertSame($expectedType, $actualType);
    }

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function resolveFieldTypeProvider(): array
    {
        return [
            'status int uses select' => ['status', 'int(1)', 'select'],
            'payment status int uses select' => ['payment_status', 'tinyint(1)', 'select'],
            'order status int uses select' => ['order_status', 'int(1)', 'select'],
            'vip flag uses select' => ['is_vip', 'smallint(1)', 'select'],
            'age stays number' => ['age', 'int(3)', 'number'],
            'photo stays image' => ['photo', 'varchar(255)', 'image'],
            'attachment stays file' => ['attachment', 'varchar(255)', 'file'],
        ];
    }

    /**
     * @dataProvider resolveFieldOptionsProvider
     */
    public function testResolveFieldOptionsReturnsOptionLists(string $modelClass, string $fieldName, array $expectedValues): void
    {
        $service = new DemoTableService();
        $method = new ReflectionMethod(DemoTableService::class, 'resolveFieldOptions');
        $method->setAccessible(true);

        $options = $method->invoke($service, $modelClass, $fieldName);

        $this->assertIsArray($options);
        $this->assertNotEmpty($options);

        $actualValues = array_map(
            static fn (array $option): string => (string)($option['value'] ?? ''),
            $options
        );

        foreach ($expectedValues as $expectedValue) {
            $this->assertContains((string)$expectedValue, $actualValues);
        }
    }

    /**
     * @return array<string,array{0:string,1:string,2:array<int|string>}>
     */
    public static function resolveFieldOptionsProvider(): array
    {
        return [
            'user status options' => ['Weline\DataTable\Model\TestUser', 'status', [0, 1]],
            'order status options' => ['Weline\DataTable\Model\TestOrder', 'order_status', [0, 1, 2, 3, 4]],
            'payment status options' => ['Weline\DataTable\Model\TestOrder', 'payment_status', [0, 1, 2]],
        ];
    }

    /**
     * @dataProvider normalizeSortsProvider
     */
    public function testNormalizeSortsDefaultsToNewestRecordsFirst(array $sorts, array $modelConfig, array $expected): void
    {
        $service = new DemoTableService();
        $method = new ReflectionMethod(DemoTableService::class, 'normalizeSorts');
        $method->setAccessible(true);

        $actual = $method->invoke($service, $sorts, $modelConfig);

        $this->assertSame($expected, $actual);
    }

    /**
     * @return array<string,array{0:array<string,string>,1:array<string,mixed>,2:array<string,string>}>
     */
    public static function normalizeSortsProvider(): array
    {
        return [
            'preserves explicit sort direction' => [
                ['name' => 'asc'],
                [
                    'models' => ['TestUser' => 'Weline\DataTable\Model\TestUser'],
                    'main_model' => 'Weline\DataTable\Model\TestUser',
                    'aliases' => ['Weline\DataTable\Model\TestUser' => 'TestUser'],
                ],
                ['name' => 'ASC'],
            ],
            'single model defaults to id desc' => [
                [],
                [
                    'models' => ['TestUser' => 'Weline\DataTable\Model\TestUser'],
                    'main_model' => 'Weline\DataTable\Model\TestUser',
                    'aliases' => ['Weline\DataTable\Model\TestUser' => 'TestUser'],
                ],
                ['id' => 'DESC'],
            ],
            'multi model defaults to main alias id desc' => [
                [],
                [
                    'models' => [
                        'u' => 'Weline\DataTable\Model\TestUser',
                        'o' => 'Weline\DataTable\Model\TestOrder',
                    ],
                    'main_model' => 'Weline\DataTable\Model\TestUser',
                    'aliases' => [
                        'Weline\DataTable\Model\TestUser' => 'u',
                        'Weline\DataTable\Model\TestOrder' => 'o',
                    ],
                ],
                ['u.id' => 'DESC'],
            ],
        ];
    }
}
