<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Preload\Provider;

use Weline\Framework\Compilation\CompiledExtensionRegistry;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Hook\Config\HookReader;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Plugin\PluginRegistry;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadProviderInterface;
use Weline\Framework\Runtime\Preload\WorkerPreloadResult;

final class ExtensionRegistryPreloadProvider implements WorkerPreloadProviderInterface
{
    public function code(): string
    {
        return 'extension_registry';
    }

    public function phase(): string
    {
        return WorkerPreloadContext::PHASE_BOOTSTRAP;
    }

    public function priority(): int
    {
        return 30;
    }

    public function isEnabled(WorkerPreloadContext $context): bool
    {
        return true;
    }

    public function preload(WorkerPreloadContext $context): WorkerPreloadResult
    {
        $start = \microtime(true);
        $memoryStart = \memory_get_usage(true);
        $stats = [];

        HookReader::preloadGeneratedHookRegistry();
        $stats['hook_reader'] = 1;

        $extendsRegistry = ExtendsData::getRegistry();
        $stats['extends'] = \is_array($extendsRegistry) ? \count($extendsRegistry) : 0;

        $stats['plugins'] = $this->countRegistryRows(PluginRegistry::class, 'plugins');
        $stats['hooks'] = CompiledExtensionRegistry::count('hooks.php', 'hooks');
        $stats['taglibs'] = CompiledExtensionRegistry::count('taglibs.php', 'tags');
        $stats['widgets'] = CompiledExtensionRegistry::count('widgets.php', 'widgets');

        $items = 0;
        foreach ($stats as $value) {
            $items += (int)$value;
        }

        return WorkerPreloadResult::warmed(
            $this->code(),
            $context->phase(),
            $items,
            \round((\microtime(true) - $start) * 1000, 2),
            \memory_get_usage(true) - $memoryStart,
            $stats
        );
    }

    public function invalidationKeys(): array
    {
        return [
            'generated/hooks.php',
            'generated/extends.php',
            'generated/plugins.php',
            'generated/taglibs.php',
            'generated/widgets.php',
        ];
    }

    private function countRegistryRows(string $className, ?string $key): int
    {
        if (!\class_exists($className)) {
            return 0;
        }

        $instance = ObjectManager::getInstance($className);
        if (!\method_exists($instance, 'getRegistry')) {
            return 0;
        }

        $registry = $instance->getRegistry();
        if (!\is_array($registry)) {
            return 0;
        }

        if ($key === null) {
            return \count($registry);
        }

        return \is_array($registry[$key] ?? null) ? \count($registry[$key]) : 0;
    }
}
