<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Soft pressure roles: CONTENT may drop; REGISTRY must survive soft.
 */
final class SoftSurvivorCatalog
{
    public const ROLE_CONTENT = 'content';
    public const ROLE_REGISTRY = 'registry';

    /**
     * @return array<string, string> symbol => role
     */
    public static function matrix(): array
    {
        return [
            'Weline\\Framework\\Phrase\\DictionaryCacheNamespace::$bagRefs' => self::ROLE_CONTENT,
            'Weline\\Framework\\View\\Taglib\\Ast\\NodePool::$textNodes' => self::ROLE_CONTENT,
            'Weline\\Framework\\View\\TemplateCacheManager' => self::ROLE_CONTENT,
            // Process FPC bags hold rebuildable pointer receipts (body on Shared/html-disk).
            // Soft may unload non-pinned entries; homepage/critical receipts stay via softCompact.
            'Weline\\Framework\\Router\\FullPageCacheCoordinator::$processFpcPayloadCache' => self::ROLE_CONTENT,
            // Authority bag for all generated PHP registries (MUST-IN). Domain
            // statics below remain dual-registered while callers still keep shaped
            // views; soft pressure must not drop either until fully delegated.
            'Weline\\Framework\\Compilation\\CompiledPhpRegistry::$entries' => self::ROLE_REGISTRY,
            'Weline\\Framework\\Service\\Query\\QueryProviderRegistry::$processCompiledRegistry' => self::ROLE_REGISTRY,
            'Weline\\Widget\\Service\\WidgetRegistry::$staticCachedRegistry' => self::ROLE_REGISTRY,
            'Weline\\Framework\\Event\\EventRegistry::$runtimeRegistryCache' => self::ROLE_REGISTRY,
            'Weline\\Framework\\Extends\\ExtendsData::$cachedRegistry' => self::ROLE_REGISTRY,
            'Weline\\Framework\\Compilation\\CompiledExtensionRegistry::$registries' => self::ROLE_REGISTRY,
            'Weline\\Widget\\Service\\ParamSchemaRegistry::$staticCachedRegistry' => self::ROLE_REGISTRY,
        ];
    }

    public static function roleFor(string $symbol): ?string
    {
        return self::matrix()[$symbol] ?? null;
    }

    public static function isRegistrySoftSurvivor(string $symbol): bool
    {
        return self::roleFor($symbol) === self::ROLE_REGISTRY;
    }
}
