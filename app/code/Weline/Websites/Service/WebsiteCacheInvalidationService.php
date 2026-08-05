<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Url;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;
use Weline\Framework\Runtime\RuntimeNamespaceInvalidationPublisherInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Websites\Model\Website;
use Weline\Websites\Observer\DetectWebsite;

/**
 * Unifies Website namespace generation and commit-sensitive runtime effects.
 *
 * Controller producers may defer model hooks until the complete immutable
 * ResourceChange has run its critical sync observers. Standalone model writes
 * still open a short generation transaction after their own business commit.
 */
final class WebsiteCacheInvalidationService
{
    private const STATE_KEY = 'websites.cache_invalidation_transactions';
    private const COMPONENT_WEBSITE = 'website';
    private const COMPONENT_DOMAIN = 'domain';
    private const COMPONENT_CURRENCY = 'currency';
    private const COMPONENT_LANGUAGE = 'language';
    private const COMPONENT_START_PAGE = 'config/start-page';
    private const COMPONENT_CATALOG = 'catalog';

    public function __construct(
        private readonly NamespaceGenerationRepository $namespaces,
        private readonly NamespacePath $namespacePath,
        private readonly TransactionCoordinatorInterface $transactions,
        private readonly RuntimeProviderResolver $runtimeProviders,
        private readonly WebsiteChangeSnapshotFactory $snapshots,
    ) {
    }

    public function beginDeferred(ConnectionFactory $connection): void
    {
        $this->namespaces->assertConnectionAffinity($connection);
        if (!$this->transactions->isActive($connection)) {
            throw new \LogicException(__('网站缓存失效延迟作用域只能在活动事务中开启'));
        }
        $connectionKey = TransactionContext::logicalConnectionKey($connection->getConnector());
        $state = $this->state($connectionKey);
        $state['deferred'] = true;
        $this->storeState($connectionKey, $state);
        $this->registerRollback($connection, $connectionKey);
    }

    /**
     * Flush a controller producer after w_changed() completed its sync phase.
     * A second bump is harmless: NamespaceGenerationRepository deduplicates it
     * against the critical CacheNamespaceObserver in the same transaction.
     *
     * @param list<string> $paths
     * @param list<string> $components
     */
    public function flushDeferred(
        ConnectionFactory $connection,
        array $paths,
        array $components = [
            self::COMPONENT_WEBSITE,
            self::COMPONENT_DOMAIN,
            self::COMPONENT_CURRENCY,
            self::COMPONENT_LANGUAGE,
            self::COMPONENT_START_PAGE,
        ],
    ): void {
        $this->namespaces->assertConnectionAffinity($connection);
        $connectionKey = TransactionContext::logicalConnectionKey($connection->getConnector());
        $state = $this->state($connectionKey);
        $state['deferred'] = false;
        $state['paths'] = $this->stringList(array_merge($state['paths'], $paths));
        $state['components'] = $this->componentList(array_merge($state['components'], $components));
        $this->storeState($connectionKey, $state);
        $this->bumpActiveState($connection, $connectionKey);
    }

    /**
     * @param list<string> $components
     */
    public function invalidateWebsite(
        ConnectionFactory $connection,
        int $websiteId,
        array $components = [self::COMPONENT_WEBSITE],
        ?string $previousCode = null,
    ): void {
        if ($websiteId < Website::ID_DEFAULT) {
            throw new \InvalidArgumentException(__('website_id 不能为负数'));
        }
        $this->namespaces->assertConnectionAffinity($connection);
        $snapshot = $this->snapshots->capture($websiteId, $connection);
        if ($snapshot === null) {
            throw new \RuntimeException(__('待失效的网站不存在：%{1}', [$websiteId]));
        }
        $code = (string)$snapshot[Website::schema_fields_CODE];
        $paths = $this->pathsForCodes([$code, (string)$previousCode], $components);
        $this->invalidatePaths($connection, $paths, $components);
    }

    /** @param list<string> $components */
    public function invalidateDeletedWebsite(
        ConnectionFactory $connection,
        string $websiteCode,
        array $components = [
            self::COMPONENT_WEBSITE,
            self::COMPONENT_DOMAIN,
            self::COMPONENT_CURRENCY,
            self::COMPONENT_LANGUAGE,
            self::COMPONENT_START_PAGE,
        ],
    ): void {
        $this->namespaces->assertConnectionAffinity($connection);
        $this->invalidatePaths(
            $connection,
            $this->pathsForCodes([$websiteCode], $components),
            $components,
        );
    }

    /**
     * @param list<string> $paths
     * @param list<string> $components
     */
    public function invalidatePaths(ConnectionFactory $connection, array $paths, array $components): void
    {
        $paths = $this->stringList($paths);
        if ($paths === []) {
            return;
        }
        $this->namespaces->assertConnectionAffinity($connection);
        $components = $this->componentList($components);
        $connectionKey = TransactionContext::logicalConnectionKey($connection->getConnector());

        if (!$this->transactions->isActive($connection)) {
            $result = $this->namespaces->bumpMany($paths);
            $this->applyCommitted($result['authority_clock'], $result['changes'], $components);
            return;
        }

        $state = $this->state($connectionKey);
        $state['paths'] = $this->stringList(array_merge($state['paths'], $paths));
        $state['components'] = $this->componentList(array_merge($state['components'], $components));
        $this->storeState($connectionKey, $state);
        $this->registerRollback($connection, $connectionKey);
        if (!$state['deferred']) {
            $this->bumpActiveState($connection, $connectionKey);
        }
    }

    private function bumpActiveState(ConnectionFactory $connection, string $connectionKey): void
    {
        $state = $this->state($connectionKey);
        if ($state['paths'] === []) {
            return;
        }
        $result = $this->namespaces->bumpMany($state['paths']);
        $state = $this->state($connectionKey);
        $state['authority_clock'] = max($state['authority_clock'], (int)$result['authority_clock']);
        foreach ($result['changes'] as $namespace => $generation) {
            $state['changes'][(string)$namespace] = max(
                (int)($state['changes'][(string)$namespace] ?? 0),
                (int)$generation,
            );
        }
        ksort($state['changes'], SORT_STRING);
        $this->storeState($connectionKey, $state);
        $this->registerCommit($connection, $connectionKey);
    }

    private function registerRollback(ConnectionFactory $connection, string $connectionKey): void
    {
        $state = $this->state($connectionKey);
        if ($state['rollback_registered']) {
            return;
        }
        $state['rollback_registered'] = true;
        $this->storeState($connectionKey, $state);
        $this->transactions->afterRollback(
            $connection,
            'websites_cache_invalidation_rollback',
            fn(): bool => $this->forgetState($connectionKey),
        );
    }

    private function registerCommit(ConnectionFactory $connection, string $connectionKey): void
    {
        $state = $this->state($connectionKey);
        if ($state['commit_registered']) {
            return;
        }
        $state['commit_registered'] = true;
        $this->storeState($connectionKey, $state);
        $this->transactions->afterCommit(
            $connection,
            'websites_cache_invalidation_commit',
            function () use ($connectionKey): void {
                $committed = $this->state($connectionKey);
                $this->forgetState($connectionKey);
                if ($committed['authority_clock'] < 1 || $committed['changes'] === []) {
                    return;
                }
                $this->applyCommitted(
                    $committed['authority_clock'],
                    $committed['changes'],
                    $committed['components'],
                );
            },
        );
    }

    /** @param array<string,int> $changes @param list<string> $components */
    private function applyCommitted(int $authorityClock, array $changes, array $components): void
    {
        $config = $this->namespaceConfig();
        if ($this->boolValue($config['legacy_full_clear_fallback'] ?? true)) {
            $this->runLegacyFallback($components);
        }

        try {
            Url::bumpWebsiteParserSitesVersion();
        } catch (\Throwable) {
            $this->logFailure('parser_version_failed');
        }
        try {
            DetectWebsite::clearProcessCache();
        } catch (\Throwable) {
            $this->logFailure('detect_process_cache_failed');
        }

        if (!$this->boolValue($config['publisher_enabled'] ?? false)) {
            return;
        }

        $resolution = $this->runtimeProviders->resolveDetailed(
            RuntimeNamespaceInvalidationPublisherInterface::class,
        );
        if (!$resolution->isAvailable()
            || !($resolution->provider instanceof RuntimeNamespaceInvalidationPublisherInterface)) {
            if ($resolution->status !== 'not_configured') {
                $this->logFailure($resolution->errorCode !== ''
                    ? $resolution->errorCode
                    : 'namespace_publisher_unavailable');
            }
            return;
        }

        try {
            $result = $resolution->provider->publish(
                $authorityClock,
                $changes,
                null,
                (string)(RequestContext::getId() ?? WelineEnv::get('request.id', '')),
            );
            if (($result['accepted'] ?? $result['success'] ?? false) !== true) {
                $this->logFailure('namespace_publish_rejected');
            }
        } catch (\Throwable) {
            $this->logFailure('namespace_publish_failed');
        }
    }

    /** @param list<string> $components */
    private function runLegacyFallback(array $components): void
    {
        $pools = ['website', 'website_detect'];
        if (in_array(self::COMPONENT_CURRENCY, $components, true)) {
            $pools[] = 'currency';
        }
        if (in_array(self::COMPONENT_LANGUAGE, $components, true)) {
            $pools[] = 'i18n';
        }
        if (in_array(self::COMPONENT_CATALOG, $components, true)) {
            $pools[] = 'product';
        }
        foreach (array_values(array_unique($pools)) as $pool) {
            try {
                w_cache($pool)->clear();
            } catch (\Throwable) {
                $this->logFailure('legacy_pool_clear_' . $pool . '_failed');
            }
        }

        $broadcaster = $this->runtimeProviders->resolve(RuntimeControlBroadcasterInterface::class);
        if (!$broadcaster instanceof RuntimeControlBroadcasterInterface) {
            return;
        }
        try {
            $result = $broadcaster->cacheClear();
            if (($result['accepted'] ?? $result['success'] ?? false) !== true) {
                $this->logFailure('legacy_cache_clear_rejected');
            }
        } catch (\Throwable) {
            $this->logFailure('legacy_cache_clear_failed');
        }
    }

    /** @param list<string> $codes @param list<string> $components @return list<string> */
    private function pathsForCodes(array $codes, array $components): array
    {
        $components = $this->componentList($components);
        $paths = [];
        foreach ($this->stringList($codes) as $code) {
            foreach ($components as $component) {
                $segments = match ($component) {
                    self::COMPONENT_WEBSITE => [],
                    self::COMPONENT_START_PAGE => ['config', 'start-page'],
                    default => [$component],
                };
                $paths[] = $this->namespacePath->website($code, $segments);
            }
        }
        if (in_array(self::COMPONENT_WEBSITE, $components, true)
            || in_array(self::COMPONENT_DOMAIN, $components, true)) {
            $paths[] = $this->namespacePath->global('websites-registry');
        }
        return $this->stringList($paths);
    }

    /** @return array<string,mixed> */
    private function namespaceConfig(): array
    {
        $module = Env::module_env('Weline_Framework');
        $cache = is_array($module) && is_array($module['cache'] ?? null) ? $module['cache'] : [];
        $namespace = is_array($cache['namespace'] ?? null) ? $cache['namespace'] : [];

        $namespace['publisher_enabled'] = Env::get(
            'cache.namespace.publisher_enabled',
            $namespace['publisher_enabled'] ?? false,
        );
        $namespace['legacy_full_clear_fallback'] = Env::get(
            'cache.namespace.legacy_full_clear_fallback',
            $namespace['legacy_full_clear_fallback'] ?? true,
        );

        return $namespace;
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    /** @return list<string> */
    private function stringList(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $result[$value] = $value;
            }
        }
        $result = array_values($result);
        sort($result, SORT_STRING);
        return $result;
    }

    /** @return list<string> */
    private function componentList(array $values): array
    {
        $allowed = [
            self::COMPONENT_WEBSITE,
            self::COMPONENT_DOMAIN,
            self::COMPONENT_CURRENCY,
            self::COMPONENT_LANGUAGE,
            self::COMPONENT_START_PAGE,
            self::COMPONENT_CATALOG,
        ];
        $result = [];
        foreach ($this->stringList($values) as $value) {
            if (!in_array($value, $allowed, true)) {
                throw new \InvalidArgumentException(__('未知网站缓存组件：%{1}', [$value]));
            }
            $result[] = $value;
        }
        return $result;
    }

    /**
     * @return array{
     *   deferred:bool,
     *   rollback_registered:bool,
     *   commit_registered:bool,
     *   authority_clock:int,
     *   paths:list<string>,
     *   components:list<string>,
     *   changes:array<string,int>
     * }
     */
    private function state(string $connectionKey): array
    {
        $all = RequestContext::get(self::STATE_KEY, []);
        $state = is_array($all) ? ($all[$connectionKey] ?? []) : [];
        return [
            'deferred' => (bool)($state['deferred'] ?? false),
            'rollback_registered' => (bool)($state['rollback_registered'] ?? false),
            'commit_registered' => (bool)($state['commit_registered'] ?? false),
            'authority_clock' => max(0, (int)($state['authority_clock'] ?? 0)),
            'paths' => $this->stringList(is_array($state['paths'] ?? null) ? $state['paths'] : []),
            'components' => $this->componentList(
                is_array($state['components'] ?? null) ? $state['components'] : [],
            ),
            'changes' => is_array($state['changes'] ?? null) ? $state['changes'] : [],
        ];
    }

    /** @param array<string,mixed> $state */
    private function storeState(string $connectionKey, array $state): void
    {
        $all = RequestContext::get(self::STATE_KEY, []);
        $all = is_array($all) ? $all : [];
        $all[$connectionKey] = $state;
        RequestContext::set(self::STATE_KEY, $all);
    }

    private function forgetState(string $connectionKey): bool
    {
        $all = RequestContext::get(self::STATE_KEY, []);
        if (!is_array($all)) {
            return false;
        }
        unset($all[$connectionKey]);
        if ($all === []) {
            RequestContext::remove(self::STATE_KEY);
        } else {
            RequestContext::set(self::STATE_KEY, $all);
        }
        return true;
    }

    private function logFailure(string $errorCode): void
    {
        if (!function_exists('w_log_warning')) {
            return;
        }
        $errorCode = strtolower((string)preg_replace('/[^a-z0-9_]+/i', '_', $errorCode));
        $errorCode = trim(substr($errorCode, 0, 64), '_');
        w_log_warning(
            'cache_namespace_publish_failed',
            [
                'instance' => (string)WelineEnv::get('wls.instance_name', ''),
                'error_code' => $errorCode !== '' ? $errorCode : 'unknown_failure',
            ],
            'website_cache_invalidation',
        );
    }
}
