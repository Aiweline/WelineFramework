<?php

declare(strict_types=1);

namespace Weline\Cdn\Observer;

use Weline\Cdn\Api\OriginNoStoreEdgeHeaderWriterInterface;
use Weline\Cdn\Service\AdapterResolver;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\HeaderCollector;

/**
 * When Framework marks a response no-store (shared cache forbidden), let each
 * CDN vendor adapter write its own edge cache headers. Theme/business modules
 * must not set CDN-Cache-Control / Cloudflare-* directly.
 */
final class ApplyOriginNoStoreEdgeHeaders implements ObserverInterface
{
    public function __construct(
        private readonly AdapterResolver $adapterResolver,
    ) {
    }

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

        foreach ($this->adapterResolver->getAllAdapters() as $adapter) {
            if ($adapter instanceof OriginNoStoreEdgeHeaderWriterInterface) {
                $adapter->applyOriginNoStoreEdgeHeaders();
            }
        }
    }
}
