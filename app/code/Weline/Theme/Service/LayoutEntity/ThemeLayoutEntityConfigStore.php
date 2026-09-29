<?php
declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;
use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Version\ThemeVersionIdentity;

/** Compatibility boundary: layout relationships are emitted only into PHTML at save time. */
final class ThemeLayoutEntityConfigStore
{

    public function __construct(
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ?StorefrontScopeHotCache $hotCache = null,
    ) {  }

    public function readBoundConfig(EntityRenderBinding $binding): array
    { return []; }

    public function readBoundAssets(EntityRenderBinding $binding): array
    { return []; }

    public static function cachePolicy(): CachePolicy
    { return new CachePolicy(resource: 'theme.layout_entity.config', pool: 'theme_layout', scope: 'channel', vary: [], dependencies: ['theme'], freshTtlSeconds: 3600, staleTtlSeconds: 86400); }

    public function readPageAssets(ThemeVersionIdentity $identity, string $layoutIdentityHash): array
    { return []; }

    public function readChromeAssets(ThemeVersionIdentity $identity): array
    { return []; }

    public function readPageConfig(ThemeVersionIdentity $identity, string $layoutIdentityHash): array
    { return []; }

    public function readChromeConfig(ThemeVersionIdentity $identity): array
    { return []; }

    public function bindingForConfigSource(string $configSource, ?EntityRenderBinding $binding): ?EntityRenderBinding
    { return null; }

    public function readNodeConfig(
        string $configSource,
        string $nodeUid,
        int $themeId,
        string $scopeKey,
        string $versionKey,
        ?EntityRenderBinding $binding = null,
    ): array { return []; }

    public function hydratePageNodeFromStructure(
        array $entry,
        string $nodeUid,
        string $structurePath = '',
    ): array { return $entry; }

    public function needsStructureHydration(array $entry): bool
    { return false; }
}
