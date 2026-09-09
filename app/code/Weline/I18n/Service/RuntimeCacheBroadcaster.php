<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeNamespaceInvalidationPublisherInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;

/**
 * Publishes committed translation versions without evicting unrelated pools.
 */
final class RuntimeCacheBroadcaster
{
    public function __construct(
        private readonly RuntimeProviderResolver $runtimeProviders,
    ) {
    }

    public function broadcast(): void
    {
        ObjectManager::getInstance(I18nResourceChangePublisher::class)
            ->publishAction('dictionary-cache-invalidate', []);
    }

    /** @param array<string,int> $changes */
    public function broadcastCommitted(int $authorityClock, array $changes): void
    {
        try {
            $provider = $this->runtimeProviders->resolve(RuntimeNamespaceInvalidationPublisherInterface::class);
            if ($provider instanceof RuntimeNamespaceInvalidationPublisherInterface) {
                $provider->publish($authorityClock, $changes, null, (string)(RequestContext::getId() ?? ''));
            }
        } catch (\Throwable) {
            // The committed DB authority is checked at the next request even
            // when optional IPC delivery is unavailable.
        }
    }
}
