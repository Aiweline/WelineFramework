<?php

declare(strict_types=1);

namespace Weline\Websites\Service\Preload;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Preload\AbstractWorkerPreloadProvider;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadResult;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\StorefrontRenderContextInstaller;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\ThemeApplicationContextProducer;

/**
 * Warms runtime theme application contexts for every storefront website.
 *
 * Root cause this removes: `InstallThemeApplicationContext` (dispatched from
 * `App::applyParsedUrl` → `application_context_ready`) resolves the theme
 * application chain and reads the version resource snapshot on every request,
 * because no layer cached the result — 5.4 ms of the request's
 * `process_url_parse_ms` on a cold Worker, and again after each reload.
 *
 * The producer now keeps a process-level context cache; this provider fills it
 * at worker start-up, so the request path only does an array lookup.
 *
 * Registered by the module itself through etc/module.php `provides`:
 *   'worker_preload_provider.Weline_Websites' => this class
 * The framework runtime therefore no longer carries website/theme warm-up code.
 */
final class StorefrontThemeApplicationPreloadProvider extends AbstractWorkerPreloadProvider
{
    public function code(): string
    {
        return 'websites_theme_application';
    }

    /**
     * Theme scopes are resolved per website, and the website catalogue is
     * published by the framework's website-directory warm-up. Run after it.
     */
    public function dependsOn(): array
    {
        return ['website_directory'];
    }

    /**
     * Storefront warm-up only; backend/CLI runtimes never build these contexts.
     */
    public function isEnabled(WorkerPreloadContext $context): bool
    {
        if ($context->runtimeMode() !== \Weline\Framework\Runtime\RuntimeInterface::MODE_WLS) {
            return false;
        }
        $role = \strtolower(\trim($context->role()));

        return !\in_array($role, ['dispatcher', 'master', 'session', 'memory', 'supervisor', 'maintenance'], true);
    }

    public function priority(): int
    {
        return 60;
    }

    public function invalidationKeys(): array
    {
        return ['website', 'theme_version'];
    }

    public function preload(WorkerPreloadContext $context): WorkerPreloadResult
    {
        unset($context);
        $start = \microtime(true);
        $memoryStart = \memory_get_usage(true);

        $warmed = 0;
        $skipped = 0;
        try {
            if (!\class_exists(Website::class)) {
                return WorkerPreloadResult::skipped($this->code(), $this->phase(), 'websites_module_missing');
            }
            /** @var ThemeApplicationContextProducer $producer */
            $producer = ObjectManager::getInstance(ThemeApplicationContextProducer::class);
            $renderInstaller = ObjectManager::getInstance(StorefrontRenderContextInstaller::class);

            foreach (Website::fetchCatalogRows() as $row) {
                $websiteId = (int)($row['website_id'] ?? -1);
                if ($websiteId < Website::ID_DEFAULT) {
                    continue;
                }
                $websiteCode = \trim((string)($row['code'] ?? ''));
                if ($websiteCode === '') {
                    $skipped++;
                    continue;
                }
                $defaultLocale = \trim((string)($row['default_language'] ?? ''));
                if ($defaultLocale === '') {
                    $skipped++;
                    continue;
                }
                $displayName = (string)($row['name'] ?? '');
                $identity = ScopeIdentity::channel(
                    $websiteId,
                    $websiteCode,
                    Website::CODE_DEFAULT,
                    Website::CODE_DEFAULT,
                    ScopeIdentity::MODE_NORMAL,
                );
                $producer->build(
                    identity: $identity,
                    storeMode: ScopeIdentity::MODE_NORMAL,
                    defaultLocale: $defaultLocale,
                    displayName: $displayName,
                    purpose: 'runtime',
                );
                // Render-context snapshot for the same scope: the request path
                // installs it from this cache instead of projecting website
                // localisation rows + resolving the locale catalogue again.
                $renderInstaller->warmSnapshot(
                    $websiteId,
                    $websiteCode,
                    \trim((string)($row['url'] ?? '')),
                    $defaultLocale,
                    \trim((string)($row['default_currency'] ?? '')),
                    \trim((string)($row['default_timezone'] ?? '')),
                );
                $warmed++;
            }
        } catch (\Throwable $e) {
            // Warm-up is an optimisation: never block Worker READY.
            return WorkerPreloadResult::failed(
                $this->code(),
                $this->phase(),
                'theme_application_preload_failed: ' . $e->getMessage(),
                \round((\microtime(true) - $start) * 1000, 2),
                \memory_get_usage(true) - $memoryStart,
            );
        }

        return WorkerPreloadResult::warmed(
            $this->code(),
            $this->phase(),
            $warmed,
            \round((\microtime(true) - $start) * 1000, 2),
            \memory_get_usage(true) - $memoryStart,
            ['skipped' => $skipped],
        );
    }
}
