<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Api\CartItemSnapshotProviderInterface;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;

class CartItemSnapshotProviderRegistry
{
    private const EXTENDS_PREFIX = 'extends/module/weline_cart/cartitemsnapshotprovider/';

    /**
     * @var list<array{class: class-string<CartItemSnapshotProviderInterface>, module: string}>|null
     */
    private ?array $providers = null;

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function resolve(int $productId, array $params = []): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        foreach ($this->all() as $definition) {
            try {
                $provider = ObjectManager::getInstance($definition['class']);
                if (!$provider instanceof CartItemSnapshotProviderInterface) {
                    continue;
                }

                $snapshot = $provider->resolveCartItemSnapshot($productId, $params);
                if (!is_array($snapshot)) {
                    continue;
                }

                $sourceModule = $definition['module'];
                if ($sourceModule !== '') {
                    $snapshot['source_module'] = trim((string)($snapshot['source_module'] ?? '')) ?: $sourceModule;
                    $snapshot['source_app'] = trim((string)($snapshot['source_app'] ?? ''))
                        ?: $this->sourceAppFromModule($sourceModule);
                }

                return $snapshot;
            } catch (\Throwable $throwable) {
                if (function_exists('w_log_error')) {
                    w_log_error(
                        'Cart item snapshot provider failed: '
                        . $definition['class']
                        . ', error: '
                        . $throwable->getMessage()
                    );
                }
            }
        }

        return null;
    }

    /**
     * @return list<array{class: class-string<CartItemSnapshotProviderInterface>, module: string}>
     */
    public function all(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $definitions = [];
        foreach (ExtendsData::getExtendedBy('Weline_Cart') as $sourceModule => $extensions) {
            foreach ($extensions as $extension) {
                if (!is_array($extension)) {
                    continue;
                }
                $relativePath = strtolower(str_replace('\\', '/', (string)($extension['relative_path'] ?? '')));
                if (!str_starts_with($relativePath, self::EXTENDS_PREFIX)) {
                    continue;
                }

                $className = $this->extensionClass((string)$sourceModule, $extension);
                if ($className === '' || !is_subclass_of($className, CartItemSnapshotProviderInterface::class, true)) {
                    continue;
                }

                /** @var class-string<CartItemSnapshotProviderInterface> $className */
                $definitions[$className] = [
                    'class' => $className,
                    'module' => (string)$sourceModule,
                ];
            }
        }

        return $this->providers = array_values($definitions);
    }

    public function clear(): void
    {
        $this->providers = null;
    }

    /**
     * @param array<string, mixed> $extension
     */
    private function extensionClass(string $sourceModule, array $extension): string
    {
        foreach (['class', 'class_name'] as $key) {
            $className = trim((string)($extension[$key] ?? ''));
            if ($className !== '') {
                return ltrim($className, '\\');
            }
        }

        $relativePath = str_replace('\\', '/', (string)($extension['relative_path'] ?? ''));
        if (!str_starts_with(strtolower($relativePath), 'extends/module/')) {
            return '';
        }
        $classPath = substr($relativePath, strlen('extends/module/'));
        if (!str_ends_with(strtolower($classPath), '.php')) {
            return '';
        }

        $classPath = substr($classPath, 0, -4);
        $moduleNamespace = str_replace('_', '\\', trim($sourceModule));
        if ($moduleNamespace === '' || $classPath === '') {
            return '';
        }

        return $moduleNamespace . '\\Extends\\Module\\' . str_replace('/', '\\', $classPath);
    }

    private function sourceAppFromModule(string $moduleName): string
    {
        return str_contains($moduleName, '_')
            ? (string)strstr($moduleName, '_', true)
            : $moduleName;
    }
}
