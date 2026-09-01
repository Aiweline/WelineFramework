<?php

declare(strict_types=1);

namespace Weline\DataTable\Service;

use Weline\DataTable\Model\TestOrder;
use Weline\DataTable\Model\TestProduct;
use Weline\DataTable\Model\TestUser;
use Weline\DataTable\Model\TestUserAddress;
use Weline\DataTable\Model\TestUserProfile;
use Weline\Framework\App\Exception;

/**
 * Explicit resource boundary for the built-in DataTable provider and legacy REST compatibility layer.
 *
 * Business modules must publish their own QueryProvider and policy. A browser-supplied PHP class name
 * is never an authorization mechanism.
 */
final class DataTableResourceRegistry
{
    /** @var array<string,array{model:class-string,capabilities:list<string>}> */
    private const RESOURCES = [
        'demo.users' => [
            'model' => TestUser::class,
            'capabilities' => ['read', 'create', 'update', 'delete', 'export', 'preferences'],
        ],
        'demo.products' => [
            'model' => TestProduct::class,
            'capabilities' => ['read', 'create', 'update', 'delete', 'export', 'preferences'],
        ],
        'demo.orders' => [
            'model' => TestOrder::class,
            'capabilities' => ['read', 'create', 'update', 'delete', 'export', 'preferences'],
        ],
        'demo.user_profiles' => [
            'model' => TestUserProfile::class,
            'capabilities' => ['read', 'create', 'update', 'delete', 'export', 'preferences'],
        ],
        'demo.user_addresses' => [
            'model' => TestUserAddress::class,
            'capabilities' => ['read', 'create', 'update', 'delete', 'export', 'preferences'],
        ],
    ];

    /** @return array<string,array{model:class-string,capabilities:list<string>}> */
    public static function all(): array
    {
        return self::RESOURCES;
    }

    public static function assertRegisteredModel(string $modelClass): void
    {
        if (self::resourceForModel($modelClass) === null) {
            throw new Exception(__('DataTable resource is not registered for model %{1}.', [$modelClass]));
        }
    }

    public static function resourceForModel(string $modelClass): ?string
    {
        foreach (self::RESOURCES as $resource => $definition) {
            if ($definition['model'] === $modelClass) {
                return $resource;
            }
        }

        return null;
    }

    /** @return array{resource:string,model:class-string,capabilities:list<string>} */
    public static function definitionForModel(string $modelClass): array
    {
        $resource = self::resourceForModel($modelClass);
        if ($resource === null) {
            self::assertRegisteredModel($modelClass);
        }

        return ['resource' => (string)$resource] + self::RESOURCES[(string)$resource];
    }

    /**
     * @return array{read:bool,create:bool,update:bool,delete:bool,export:bool,preferences:bool,composite_write:bool}
     */
    public static function capabilitiesForModels(array $modelClasses): array
    {
        $common = null;
        foreach ($modelClasses as $modelClass) {
            $definition = self::definitionForModel((string)$modelClass);
            $common = $common === null
                ? $definition['capabilities']
                : array_values(array_intersect($common, $definition['capabilities']));
        }
        $common ??= [];

        return [
            'read' => in_array('read', $common, true),
            'create' => in_array('create', $common, true),
            'update' => in_array('update', $common, true),
            'delete' => count($modelClasses) === 1 && in_array('delete', $common, true),
            'export' => count($modelClasses) === 1 && in_array('export', $common, true),
            'preferences' => in_array('preferences', $common, true),
            'composite_write' => count($modelClasses) > 1,
        ];
    }
}
