<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;

final class ThemeRuntimeCacheCleanerStoreModeTest extends TestCase
{
    #[DataProvider('modes')]
    public function testOnlyTheEditedStoreModeGenerationIsAdvanced(string $mode): void
    {
        $paths = new NamespacePath();
        $scopes = $this->createMock(ScopeHierarchyInterface::class);
        $scopes->method('fromStorageScope')->willReturn(
            ScopeIdentity::channel(12, 'shop-a', 'store-a', 'channel-a', 'normal'),
        );
        $generations = $this->createMock(NamespaceGenerationInterface::class);
        $generations->expects(self::once())->method('bump')
            ->with('website/shop-a/theme/store/store-a/' . $mode . '/channel/channel-a')
            ->willReturn(['authority_clock' => 1, 'changes' => []]);
        ObjectManager::setInstance(NamespacePath::class, $paths);
        ObjectManager::setInstance(ScopeHierarchyInterface::class, $scopes);
        ObjectManager::setInstance(NamespaceGenerationInterface::class, $generations);
        try {
            (new ThemeRuntimeCacheCleaner())->clearLayoutEntityCaches(null, 'shop-a.store-a.channel-a', $mode);
        } finally {
            foreach ([NamespacePath::class, ScopeHierarchyInterface::class, NamespaceGenerationInterface::class] as $class) {
                ObjectManager::removeInstance($class);
            }
        }
    }

    public static function modes(): array
    {
        return [['test'], ['dev'], ['normal']];
    }
}
