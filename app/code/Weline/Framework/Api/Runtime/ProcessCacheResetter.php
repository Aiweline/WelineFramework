<?php

declare(strict_types=1);

namespace Weline\Framework\Api\Runtime;

use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;
use Weline\Framework\View\Taglib\Ast\NodePool;

/**
 * Framework hard/clear bags formerly cleared via Guard FQCN.
 */
final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        if ($context->isSoftContent() || !$context->allowsRegistryClear()) {
            return 0;
        }

        $cleared = 0;

        if (\class_exists(\Weline\Framework\Phrase\Parser::class, false)
            || \class_exists(\Weline\Framework\Phrase\Parser::class, true)
        ) {
            \Weline\Framework\Phrase\Parser::clearWorkerCaches();
            $cleared++;
        }

        if (\class_exists(\Weline\Framework\Event\EventData::class, false)) {
            \Weline\Framework\Event\EventData::clearCache();
            $cleared++;
        }

        if (\class_exists(\Weline\Framework\Event\EventRegistry::class, false)
            && \method_exists(\Weline\Framework\Event\EventRegistry::class, 'clearRuntimeCache')
        ) {
            \Weline\Framework\Event\EventRegistry::clearRuntimeCache();
            $cleared++;
        }

        if (\class_exists(\Weline\Framework\Extends\ExtendsData::class, false)) {
            \Weline\Framework\Extends\ExtendsData::clearCache();
            $cleared++;
        }

        if (\class_exists(\Weline\Framework\Router\Core::class, false)) {
            \Weline\Framework\Router\Core::resetGeneratedRouterFileCache();
            $cleared++;
        }

        if (\class_exists(\Weline\Framework\Support\Php84::class, false)) {
            \Weline\Framework\Support\Php84::clearCache();
            $cleared++;
        }

        if (\class_exists(\Weline\Framework\System\Process\Processer::class, false)) {
            \Weline\Framework\System\Process\Processer::clearTrustedPidCache();
            \Weline\Framework\System\Process\Processer::clearPortCache();
            \Weline\Framework\System\Process\Processer::clearLogEnabledCache();
            $cleared++;
        }

        if (\class_exists(NodePool::class, false) || \class_exists(NodePool::class, true)) {
            NodePool::reset();
            $cleared++;
        }

        return $cleared;
    }

    public function diagCounts(): array
    {
        return [];
    }
}
