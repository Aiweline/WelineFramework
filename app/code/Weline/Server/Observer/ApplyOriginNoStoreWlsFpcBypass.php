<?php

declare(strict_types=1);

namespace Weline\Server\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\GuardHeaders;
use Weline\Framework\Http\HeaderCollector;

/**
 * When origin Cache-Control is no-store (shared cache forbidden), mark the live
 * response as FPC bypass for WLS observability. Publish itself is blocked by
 * Framework FullPageCacheCoordinator::canPublishResponse (SharedResponseCachePolicy
 * + shared HeaderCollector no-store).
 */
final class ApplyOriginNoStoreWlsFpcBypass implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $cacheControl = (string)($event->getData('cache_control') ?? '');
        if ($cacheControl === '') {
            $raw = HeaderCollector::getInstance()->getHeader('Cache-Control');
            if (is_array($raw)) {
                $cacheControl = implode(',', array_map('strval', $raw));
            } else {
                $cacheControl = (string)$raw;
            }
        }
        if ($cacheControl === '' || preg_match('/\bno-store\b/i', $cacheControl) !== 1) {
            return;
        }

        // X-Weline-FPC is what WlsRuntime::currentFpcStatus historically read;
        // X-Wls-Fpc-Status + Cache-Status cover edge/observability consumers.
        HeaderCollector::getInstance()
            ->setHeader(GuardHeaders::CACHE_STATUS, GuardHeaders::STATUS_BYPASS)
            ->setHeader('X-Weline-FPC', 'BYPASS')
            ->setHeader('X-Wls-Fpc-Status', 'BYPASS');
    }
}
