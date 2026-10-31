<?php

declare(strict_types=1);

namespace Weline\Websites\Service\Preload;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Preload\AbstractWorkerPreloadProvider;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadResult;
use Weline\Websites\Observer\DetectWebsite;

/**
 * Primes the parser site map and the host-match cache for every storefront host
 * this Worker can serve.
 *
 * `Url::parser()` keeps the site catalogue in a process static and fills it from
 * the `Weline_Framework_Url::detect_website` event; without warm-up the first
 * parse of each Worker re-dispatches that whole event (measured ~4.7 ms of extra
 * work inside the first request). Site matching then costs another 13–15 ms on
 * its first dispatch.
 *
 * Runs after {@see WebsiteDirectoryPreloadProvider}: it reads the catalogue that
 * provider published.
 */
final class SiteDetectionPreloadProvider extends AbstractWorkerPreloadProvider
{
    public function code(): string
    {
        return 'site_detection';
    }

    public function priority(): int
    {
        return 58;
    }

    public function dependsOn(): array
    {
        return ['website_directory'];
    }

    public function isEnabled(WorkerPreloadContext $context): bool
    {
        if ($context->runtimeMode() !== \Weline\Framework\Runtime\RuntimeInterface::MODE_WLS) {
            return false;
        }
        $role = \strtolower(\trim($context->role()));

        return !\in_array($role, ['dispatcher', 'master', 'session', 'memory', 'supervisor', 'maintenance'], true);
    }

    public function invalidationKeys(): array
    {
        return ['website', 'website_domain'];
    }

    public function preload(WorkerPreloadContext $context): WorkerPreloadResult
    {
        unset($context);
        $start = \microtime(true);
        $memoryStart = \memory_get_usage(true);

        $hosts = [];
        $configured = \strtolower(\trim((string)(\Weline\Framework\App\Env::get('wls.host') ?? '')));
        if ($configured !== '') {
            $hosts[$configured] = true;
        }
        foreach (['WLS_HOST', 'HTTP_HOST', 'SERVER_NAME'] as $serverKey) {
            $value = \strtolower(\trim((string)($_SERVER[$serverKey] ?? '')));
            if ($value !== '') {
                $hosts[$value] = true;
            }
        }
        if ($hosts === []) {
            return WorkerPreloadResult::skipped($this->code(), $this->phase(), 'no_host');
        }

        $items = 0;

        // Parser site map (process static inside Url).
        if (\class_exists(\Weline\Framework\Http\Url::class)) {
            \Weline\Framework\Http\Url::warmParserSites();
            $items++;
        }

        // Host match cache: first detect_website dispatch costs 13–15 ms.
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        foreach (\array_keys($hosts) as $host) {
            $payload = new DataObject(['url' => 'https://' . $host . '/']);
            $eventsManager->dispatch('Weline_Framework_Url::detect_website', $payload);
            $items++;
        }

        // Resolve each host once so the match caches are populated.
        $detector = ObjectManager::getInstance(DetectWebsite::class);
        foreach (\array_keys($hosts) as $host) {
            $detector->resolveWebsiteContext('https://' . $host . '/');
            $items++;
        }

        return WorkerPreloadResult::warmed(
            $this->code(),
            $this->phase(),
            $items,
            \round((\microtime(true) - $start) * 1000, 2),
            \memory_get_usage(true) - $memoryStart,
            ['hosts' => \array_keys($hosts)],
        );
    }
}
