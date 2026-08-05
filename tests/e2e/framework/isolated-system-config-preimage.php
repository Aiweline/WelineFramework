<?php

declare(strict_types=1);

namespace Weline\Test\E2E\Framework;

use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Model\Cache\NamespaceVersion;
use Weline\Framework\Model\Event\Delivery;
use Weline\Framework\Model\Event\Outbox;
use Weline\Framework\Model\Event\ResourceRevision;
use Weline\Server\Service\ServerInstanceManager;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Model\SystemConfigVersion;
use Weline\SystemConfig\Service\ScopeConfigCacheInvalidator;

/**
 * Audit guard for isolated SystemConfig E2E mutations.
 *
 * Config/version/revision/namespace/outbox data is monotonic framework state.
 * This helper never deletes or rewinds it. A fixture restores the logical
 * business configuration through its public rollout/ConfigStore API, then
 * calls assertMonotonicAfterOfficialRestore() to prove the audit state only
 * advanced. The dedicated PostgreSQL clone is the cleanup boundary.
 */
final class IsolatedSystemConfigPreimage
{
    /** @param list<string> $keys @return array<string,mixed> */
    public static function capture(
        string $module,
        string $area,
        array $keys,
        string $scope = SystemConfig::SCOPE_GLOBAL,
        string $locale = SystemConfig::LOCALE_DEFAULT,
    ): array {
        $identity = self::identity($module, $area, $scope, $locale, $keys);
        $connection = self::model(SystemConfig::class)->getConnection();
        return $identity + ['audit' => self::readAudit($identity, $connection)];
    }

    /**
     * Call only after the owning fixture restored the logical value through
     * the public rollout/ConfigStore API.
     *
     * @param array<string,mixed> $snapshot
     * @param list<string> $expectedKeys
     * @return array<string,mixed>
     */
    public static function assertMonotonicAfterOfficialRestore(
        array $snapshot,
        string $expectedModule,
        string $expectedArea,
        array $expectedKeys,
        string $expectedScope = SystemConfig::SCOPE_GLOBAL,
        string $expectedLocale = SystemConfig::LOCALE_DEFAULT,
    ): array {
        $expected = self::identity(
            $expectedModule,
            $expectedArea,
            $expectedScope,
            $expectedLocale,
            $expectedKeys,
        );
        foreach ($expected as $key => $value) {
            if (($snapshot[$key] ?? null) !== $value) {
                throw new \RuntimeException('r43_system_config_audit_identity_mismatch:' . $key);
            }
        }
        $before = is_array($snapshot['audit'] ?? null) ? $snapshot['audit'] : [];
        if ($before === []) {
            throw new \RuntimeException('r43_system_config_audit_snapshot_missing');
        }
        $connection = self::model(SystemConfig::class)->getConnection();
        $after = self::readAudit($expected, $connection);
        self::assertNonDecreasing($before, $after, 'version_count');
        self::assertNonDecreasing($before, $after, 'version_max_id');
        self::assertNonDecreasing($before, $after, 'resource_revision');
        self::assertNonDecreasing($before, $after, 'outbox_count');
        self::assertNonDecreasing($before, $after, 'outbox_max_id');
        self::assertNonDecreasing($before, $after, 'delivery_count');
        self::assertNonDecreasing($before, $after, 'delivery_max_id');
        self::assertNonDecreasing($before, $after, 'scope_generation');
        foreach ((array)($before['namespace_generations'] ?? []) as $namespace => $generation) {
            $current = (int)($after['namespace_generations'][$namespace] ?? 0);
            if ($current < (int)$generation) {
                throw new \RuntimeException('r43_system_config_namespace_generation_regressed:' . $namespace);
            }
        }

        return [
            'monotonic_audit_pass' => true,
            'before' => $before,
            'after' => $after,
            'wls_reload' => self::reloadDedicatedWlsIfConfigured(),
        ];
    }

    /** @param list<string> $keys @return array<string,mixed> */
    private static function identity(
        string $module,
        string $area,
        string $scope,
        string $locale,
        array $keys,
    ): array {
        $module = trim($module);
        $area = trim($area);
        $scope = trim($scope);
        $locale = trim($locale);
        $keys = array_values(array_unique(array_filter(array_map('trim', $keys), static fn(string $key): bool => $key !== '')));
        sort($keys, SORT_STRING);
        if ($module === '' || $area === '' || $scope === '' || $locale === '' || $keys === []) {
            throw new \InvalidArgumentException('r43_system_config_audit_identity_invalid');
        }
        $rawResourceId = implode('|', [$module, $area, $scope, $locale]);
        $resourceId = strlen($rawResourceId) <= 191
            ? $rawResourceId
            : 'sha256:' . hash('sha256', $rawResourceId);
        $namespacePath = ObjectManager::getInstance(NamespacePath::class);
        $namespacePaths = [
            NamespacePath::AUTHORITY_CLOCK,
            $namespacePath->global('system-config', [hash('sha256', $rawResourceId)]),
            $namespacePath->global('storefront', ['config']),
        ];
        $websiteCode = trim((string)(explode('.', strtolower($scope), 2)[0] ?? ''));
        if ($websiteCode !== '' && $websiteCode !== 'default') {
            $namespacePaths[] = $namespacePath->website($websiteCode, ['config']);
        }
        $namespacePaths = array_values(array_unique($namespacePaths));
        sort($namespacePaths, SORT_STRING);

        return [
            'module' => $module,
            'area' => $area,
            'scope' => $scope,
            'locale' => $locale,
            'keys' => $keys,
            'resource_id' => $resourceId,
            'resource_key' => hash('sha256', 'system_config' . "\0" . $resourceId),
            'namespace_paths' => $namespacePaths,
        ];
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private static function readAudit(array $identity, ConnectionFactory $connection): array
    {
        $versions = self::model(SystemConfigVersion::class, $connection)
            ->reset()
            ->where(SystemConfigVersion::schema_fields_MODULE, (string)$identity['module'])
            ->where(SystemConfigVersion::schema_fields_AREA, (string)$identity['area'])
            ->where(SystemConfigVersion::schema_fields_SCOPE, (string)$identity['scope'])
            ->where(SystemConfigVersion::schema_fields_LOCALE, (string)$identity['locale'])
            ->select()->fetchArray();
        $versions = self::rows($versions);

        $revision = self::model(ResourceRevision::class, $connection)
            ->reset()
            ->where(ResourceRevision::schema_fields_ID, (string)$identity['resource_key'])
            ->select()->fetchArray();
        $revisionRows = self::rows($revision);

        $namespaceGenerations = [];
        foreach ((array)$identity['namespace_paths'] as $namespace) {
            $rows = self::model(NamespaceVersion::class, $connection)
                ->reset()
                ->where(NamespaceVersion::schema_fields_HASH, hash('sha256', (string)$namespace))
                ->where(NamespaceVersion::schema_fields_NAMESPACE, (string)$namespace)
                ->select()->fetchArray();
            $row = self::rows($rows)[0] ?? [];
            $namespaceGenerations[(string)$namespace] = (int)($row[NamespaceVersion::schema_fields_GENERATION] ?? 0);
        }
        ksort($namespaceGenerations, SORT_STRING);

        $outboxRows = self::resourceOutboxRows($identity, $connection);
        $deliveryRows = self::queryRows(
            Delivery::class,
            Delivery::schema_fields_RESOURCE_KEY,
            (string)$identity['resource_key'],
            $connection,
        );
        /** @var ScopeConfigCacheInvalidator $scopeGeneration */
        $scopeGeneration = ObjectManager::getInstance(ScopeConfigCacheInvalidator::class);

        return [
            'version_count' => count($versions),
            'version_max_id' => self::maxField($versions, SystemConfigVersion::schema_fields_ID),
            'resource_revision' => (int)($revisionRows[0][ResourceRevision::schema_fields_REVISION] ?? 0),
            'namespace_generations' => $namespaceGenerations,
            'outbox_count' => count($outboxRows),
            'outbox_max_id' => self::maxField($outboxRows, Outbox::schema_fields_ID),
            'delivery_count' => count($deliveryRows),
            'delivery_max_id' => self::maxField($deliveryRows, Delivery::schema_fields_ID),
            'scope_generation' => $scopeGeneration->readGeneration((string)$identity['scope']),
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function resourceOutboxRows(array $identity, ConnectionFactory $connection): array
    {
        $rows = self::model(Outbox::class, $connection)
            ->reset()
            ->where(Outbox::schema_fields_EVENT_NAME, 'Weline_Framework::resource_changed')
            ->select()->fetchArray();
        return array_values(array_filter(self::rows($rows), static function (array $row) use ($identity): bool {
            $payload = json_decode((string)($row[Outbox::schema_fields_PAYLOAD_JSON] ?? ''), true);
            return is_array($payload)
                && (string)($payload['resource']['type'] ?? '') === 'system_config'
                && (string)($payload['resource']['id'] ?? '') === (string)$identity['resource_id'];
        }));
    }

    /** @return list<array<string,mixed>> */
    private static function queryRows(string $class, string $field, string|int $value, ConnectionFactory $connection): array
    {
        $rows = self::model($class, $connection)->reset()->where($field, $value)->select()->fetchArray();
        return self::rows($rows);
    }

    /** @param list<array<string,mixed>> $rows */
    private static function maxField(array $rows, string $field): int
    {
        $max = 0;
        foreach ($rows as $row) {
            $max = max($max, (int)($row[$field] ?? 0));
        }
        return $max;
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private static function assertNonDecreasing(array $before, array $after, string $field): void
    {
        if ((int)($after[$field] ?? 0) < (int)($before[$field] ?? 0)) {
            throw new \RuntimeException('r43_system_config_audit_regressed:' . $field);
        }
    }

    /** @return array<string,mixed> */
    private static function reloadDedicatedWlsIfConfigured(): array
    {
        $instance = trim((string)getenv('WELINE_E2E_WLS_INSTANCE'));
        if ($instance === '') {
            return [
                'configured' => false,
                'reloaded' => false,
                'reason' => 'focused_cli_without_wls_instance',
            ];
        }
        if (preg_match('/^ai-test-commerce-r43-[a-z0-9-]+$/D', $instance) !== 1) {
            throw new \RuntimeException('r43_wls_instance_name_rejected');
        }

        /** @var ServerInstanceManager $instances */
        $instances = ObjectManager::getInstance(ServerInstanceManager::class);
        $raw = $instances->getRawInstanceData($instance);
        if (!is_array($raw)) {
            throw new \RuntimeException('r43_wls_instance_not_found');
        }
        $port = (int)($raw['port'] ?? 0);
        if ($port < 9502 || $port === 9501) {
            throw new \RuntimeException('r43_wls_instance_port_rejected:' . $port);
        }

        $command = [PHP_BINARY, BP . 'bin/w', 'server:reload', $instance];
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, BP);
        if (!is_resource($process)) {
            throw new \RuntimeException('r43_wls_reload_process_unavailable');
        }
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new \RuntimeException('r43_wls_reload_failed:' . $exitCode);
        }

        return [
            'configured' => true,
            'reloaded' => true,
            'instance' => $instance,
            'port' => $port,
            'exit_code' => $exitCode,
            'stdout_sha256' => hash('sha256', $stdout),
            'stderr_sha256' => hash('sha256', $stderr),
        ];
    }

    /** @return object */
    private static function model(string $class, ?ConnectionFactory $connection = null): object
    {
        $model = ObjectManager::getInstance($class, [], false);
        if ($connection !== null) {
            $model->setConnection($connection);
        }
        return $model;
    }

    /** @return list<array<string,mixed>> */
    private static function rows(mixed $rows): array
    {
        return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
    }
}
