<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\View\Taglib\Ast\AstNodePoolReclaimable;
use Weline\Framework\View\TemplateCacheProcessReclaimable;

/**
 * Single shared MemoryReclaimableRegistry for Guard + HostPressure.
 */
final class ProcessMemoryReclaimFacade
{
    public const SOFT_EVICT_BYTES = 8 * 1048576;
    public const HARD_EVICT_BYTES = 16 * 1048576;

    private static ?MemoryReclaimableRegistry $registry = null;

    public static function registry(): MemoryReclaimableRegistry
    {
        if (self::$registry instanceof MemoryReclaimableRegistry) {
            return self::$registry;
        }
        self::$registry = new MemoryReclaimableRegistry();
        self::bootstrap(self::$registry);

        return self::$registry;
    }

    public static function resetForTests(): void
    {
        self::$registry = null;
    }

    private static function bootstrap(MemoryReclaimableRegistry $registry): void
    {
        if (\class_exists(\Weline\Framework\Phrase\DictionaryCacheNamespace::class, false)
            || \class_exists(\Weline\Framework\Phrase\DictionaryCacheNamespace::class, true)
        ) {
            $registry->register(
                \Weline\Framework\Phrase\DictionaryCacheNamespace::processMemoryReclaimable()
            );
        }
        $registry->register(new TemplateCacheProcessReclaimable());
        $registry->register(new AstNodePoolReclaimable());
        $registry->register(new FullPageCacheReclaimableAdapter());
    }
}
