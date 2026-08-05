<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema\Shard;

use Weline\Framework\Database\Schema\SchemaProviderInterface;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;

/**
 * 发现 extends/module/Weline_Framework/Schema 下的 SchemaProvider，
 * 并将 ShardSchemaFamilyProviderInterface 按 family code 索引。
 */
final class ShardSchemaFamilyProviderRegistry
{
    private const SCHEMA_PATH_PREFIX = 'extends/module/weline_framework/schema/';

    /** @var array<string, ShardSchemaFamilyProviderInterface>|null */
    private ?array $familyProviders = null;

    /** @var list<SchemaProviderInterface>|null */
    private ?array $allProviders = null;

    /**
     * @param array<string, ShardSchemaFamilyProviderInterface> $manualFamilyProviders
     * @param list<SchemaProviderInterface> $manualSchemaProviders
     */
    public function __construct(
        private readonly array $manualFamilyProviders = [],
        private readonly array $manualSchemaProviders = [],
        private readonly bool $scanExtends = true,
    ) {
    }

    public function get(string $familyCode): ?ShardSchemaFamilyProviderInterface
    {
        $providers = $this->getFamilyProviders();
        return $providers[$familyCode] ?? null;
    }

    /**
     * @return array<string, ShardSchemaFamilyProviderInterface>
     */
    public function getFamilyProviders(): array
    {
        $this->ensureLoaded();
        return $this->familyProviders ?? [];
    }

    /**
     * @return list<SchemaProviderInterface>
     */
    public function getAllSchemaProviders(): array
    {
        $this->ensureLoaded();
        return $this->allProviders ?? [];
    }

    /**
     * Test/bootstrap hook: register without scanning extends.
     */
    public function registerFamilyProvider(ShardSchemaFamilyProviderInterface $provider): void
    {
        $this->ensureLoaded();
        $code = $provider->getFamilyCode();
        if ($code === '') {
            throw new \InvalidArgumentException(__('Shard family code 不能为空'));
        }
        if (isset($this->familyProviders[$code])) {
            throw new \RuntimeException(__(
                'Shard family code %{1} 重复注册：%{2} 与 %{3}',
                [$code, $this->familyProviders[$code]::class, $provider::class],
            ));
        }
        $this->familyProviders[$code] = $provider;
        $this->allProviders[] = $provider;
    }

    private function ensureLoaded(): void
    {
        if ($this->familyProviders !== null && $this->allProviders !== null) {
            return;
        }

        $this->familyProviders = [];
        $this->allProviders = [];

        foreach ($this->manualSchemaProviders as $provider) {
            $this->addProvider($provider);
        }
        foreach ($this->manualFamilyProviders as $provider) {
            $this->addProvider($provider);
        }

        if (!$this->scanExtends) {
            return;
        }

        $extendedBy = ExtendsData::getExtendedBy('Weline_Framework');
        foreach ($extendedBy as $extensions) {
            if (!is_array($extensions)) {
                continue;
            }
            foreach ($extensions as $extension) {
                if (!is_array($extension)) {
                    continue;
                }
                $relativePath = str_replace('\\', '/', (string)($extension['relative_path'] ?? ''));
                if (!str_starts_with(strtolower($relativePath), self::SCHEMA_PATH_PREFIX)) {
                    continue;
                }
                $className = $this->resolveClassName($extension);
                if ($className === null || !class_exists($className)) {
                    continue;
                }
                $instance = ObjectManager::getInstance($className);
                if (!$instance instanceof SchemaProviderInterface) {
                    throw new \RuntimeException(__(
                        'Schema 扩展 %{1} 必须实现 SchemaProviderInterface',
                        [$className],
                    ));
                }
                $this->addProvider($instance);
            }
        }
    }

    private function addProvider(SchemaProviderInterface $provider): void
    {
        if ($provider instanceof ShardSchemaFamilyProviderInterface) {
            $code = $provider->getFamilyCode();
            if ($code === '') {
                throw new \InvalidArgumentException(__(
                    'Shard family provider %{1} 的 family code 不能为空',
                    [$provider::class],
                ));
            }
            if (isset($this->familyProviders[$code])) {
                throw new \RuntimeException(__(
                    'Shard family code %{1} 重复：%{2} 与 %{3}',
                    [$code, $this->familyProviders[$code]::class, $provider::class],
                ));
            }
            $this->familyProviders[$code] = $provider;
        }
        $this->allProviders[] = $provider;
    }

    private function resolveClassName(array $extension): ?string
    {
        $fromScan = trim((string)($extension['class_name'] ?? ''));
        if ($fromScan !== '') {
            return $fromScan;
        }

        $sourceFile = (string)($extension['source_file'] ?? '');
        if ($sourceFile === '' || !is_file($sourceFile)) {
            return null;
        }

        $content = file_get_contents($sourceFile);
        if ($content === false) {
            return null;
        }

        $namespace = null;
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches) === 1) {
            $namespace = trim($matches[1]);
        }
        $class = null;
        if (preg_match('/class\s+(\w+)/', $content, $matches) === 1) {
            $class = $matches[1];
        }
        if ($namespace !== null && $class !== null) {
            return $namespace . '\\' . $class;
        }

        return null;
    }
}
