<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Storage\Api\Data\StorageConfigSnapshot;
use Weline\Storage\Api\Data\StorageDiskCode;
use Weline\Storage\Model\StorageConfig;

final class StorageConfigRepository
{
    /** @var list<array<string,mixed>>|null */
    private ?array $requestEnabledRows = null;
    /** @var list<array<string,mixed>>|null */
    private ?array $requestConfiguredRows = null;

    public function __construct(private readonly StorageDriverProviderRegistry $providers)
    {
    }

    public function canonicalize(string $diskCode): string
    {
        $diskCode = strtolower(trim($diskCode));
        if ($diskCode === 'local' || $diskCode === '__local__') {
            return StorageDiskCode::BUILTIN_LOCAL_MEDIA;
        }
        // Resolve configured names and read-only aliases before accepting a
        // syntactically valid code. A former canonical code may itself be an
        // alias after a legacy migration.
        $row = $this->findEnabledByName($diskCode);
        if ($row !== null) {
            return $this->canonicalCodeForRow($row);
        }
        try {
            return (string)StorageDiskCode::parse($diskCode);
        } catch (\InvalidArgumentException) {
        }
        throw new \InvalidArgumentException((string)__('存储磁盘不存在：%{1}', [$diskCode]));
    }

    public function snapshot(string $diskCode): StorageConfigSnapshot
    {
        $input = strtolower(trim($diskCode));
        $canonical = $this->canonicalize($input);
        $row = $this->findEnabledByName($canonical);
        if ($row === null && $canonical !== $input) {
            $row = $this->findEnabledByName($input);
        }
        if ($row === null) {
            if ($canonical !== StorageDiskCode::BUILTIN_LOCAL_MEDIA) {
                throw new \RuntimeException((string)__('存储磁盘配置不可用：%{1}', [$canonical]));
            }
            if ($this->builtinLocalIsExplicitlyConfigured()) {
                // An explicit disabled/broken local::filesystem::media row is
                // an administrative decision, not permission to resurrect the
                // implicit PUB/media disk behind the caller's back.
                throw new \RuntimeException((string)__('存储磁盘配置不可用：%{1}', [$canonical]));
            }
            $config = [
                'root_path' => rtrim(PUB, '/\\') . DIRECTORY_SEPARATOR . 'media',
                'base_url' => '/pub/media',
                'visibility' => 'public',
            ];
            return new StorageConfigSnapshot(
                $canonical,
                1,
                $config,
                $this->providers->objectNamespaceFingerprint('local::filesystem', $config),
            );
        }

        $rawConfig = trim((string)($row[StorageConfig::schema_fields_CONFIG] ?? ''));
        try {
            $config = $rawConfig === '' ? [] : json_decode($rawConfig, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException((string)__('存储磁盘配置 JSON 无效：%{1}', [$canonical]));
        }
        if (!is_array($config)) {
            throw new \RuntimeException((string)__('存储磁盘配置 JSON 无效：%{1}', [$canonical]));
        }
        $config = StorageConfig::revealSecrets($config);
        $sourceConfigId = (int)($row[StorageConfig::schema_fields_CONFIG_ID] ?? 0);
        if ($sourceConfigId < 1) {
            throw new \RuntimeException((string)__('存储磁盘配置身份无效：%{1}', [$canonical]));
        }
        return new StorageConfigSnapshot(
            $canonical,
            max(1, (int)($row[StorageConfig::schema_fields_CONFIG_REVISION] ?? 1)),
            $config,
            $this->providers->objectNamespaceFingerprint(
                StorageDiskCode::parse($canonical)->providerCode(),
                $config,
            ),
            $sourceConfigId,
        );
    }

    public function defaultDiskCode(): string
    {
        $defaults = array_values(array_filter(
            $this->configuredRows(),
            static fn (array $row): bool => (int)($row[StorageConfig::schema_fields_IS_DEFAULT] ?? 0) === 1,
        ));
        if (count($defaults) > 1
            || ($defaults !== []
                && (int)($defaults[0][StorageConfig::schema_fields_STATUS] ?? StorageConfig::STATUS_DISABLED)
                    !== StorageConfig::STATUS_ENABLED)
        ) {
            throw new \RuntimeException((string)__('默认存储磁盘配置冲突或不可用。'));
        }
        if ($defaults !== []) {
            return $this->canonicalCodeForRow($defaults[0]);
        }
        $builtinLocal = $this->builtinLocalConfiguredRow();
        if ($builtinLocal !== null) {
            if ((int)($builtinLocal[StorageConfig::schema_fields_STATUS] ?? StorageConfig::STATUS_DISABLED)
                !== StorageConfig::STATUS_ENABLED
            ) {
                throw new \RuntimeException((string)__('默认存储磁盘配置冲突或不可用。'));
            }
            return $this->canonicalCodeForRow($builtinLocal);
        }
        return StorageDiskCode::BUILTIN_LOCAL_MEDIA;
    }

    /** @return list<array<string,mixed>> */
    public function catalog(): array
    {
        $items = [];
        $seen = [];
        foreach ($this->enabledRows() as $row) {
            $code = $this->canonicalCodeForRow($row);
            $seen[$code] = true;
            $items[] = [
                'disk_code' => $code,
                'name' => $code,
                'legacy_name' => (string)($row[StorageConfig::schema_fields_NAME] ?? ''),
                'provider_code' => StorageDiskCode::parse($code)->providerCode(),
                'display_name' => (string)($row[StorageConfig::schema_fields_DISPLAY_NAME] ?? $code),
                'config_revision' => max(1, (int)($row[StorageConfig::schema_fields_CONFIG_REVISION] ?? 1)),
                'is_default' => (int)($row[StorageConfig::schema_fields_IS_DEFAULT] ?? 0) === 1,
            ];
        }
        if (!isset($seen[StorageDiskCode::BUILTIN_LOCAL_MEDIA])
            && !$this->builtinLocalIsExplicitlyConfigured()
        ) {
            array_unshift($items, [
                'disk_code' => StorageDiskCode::BUILTIN_LOCAL_MEDIA,
                'name' => StorageDiskCode::BUILTIN_LOCAL_MEDIA,
                'legacy_name' => 'local',
                'provider_code' => 'local::filesystem',
                'display_name' => (string)__('本地媒体存储'),
                'config_revision' => 1,
                'is_default' => $this->defaultDiskCode() === StorageDiskCode::BUILTIN_LOCAL_MEDIA,
            ]);
        }
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function enabledRows(): array
    {
        if ($this->requestEnabledRows !== null) {
            return $this->requestEnabledRows;
        }
        /** @var StorageConfig $model */
        $model = ObjectManager::create(StorageConfig::class, [], false);
        $rows = $model->getEnabledConfigs();
        $this->requestEnabledRows = is_array($rows) ? array_values($rows) : [];
        StorageRuntimeDiagnostics::configCacheLoaded(count($this->requestEnabledRows));
        return $this->requestEnabledRows;
    }

    public function resetRequestState(): void
    {
        if ($this->requestEnabledRows !== null) {
            StorageRuntimeDiagnostics::configCacheReleased(count($this->requestEnabledRows));
        }
        if ($this->requestConfiguredRows !== null) {
            StorageRuntimeDiagnostics::configCacheReleased(count($this->requestConfiguredRows));
        }
        $this->requestEnabledRows = null;
        $this->requestConfiguredRows = null;
    }

    /** @return array<string,mixed>|null */
    private function findEnabledByName(string $name): ?array
    {
        return $this->findByName($name, $this->enabledRows());
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed>|null */
    private function findByName(string $name, array $rows): ?array
    {
        $name = strtolower(trim($name));
        $matches = [];
        foreach ($rows as $row) {
            if (strtolower(trim((string)($row[StorageConfig::schema_fields_NAME] ?? ''))) === $name) {
                $identity = (int)($row[StorageConfig::schema_fields_CONFIG_ID] ?? 0);
                $matches[$identity > 0 ? 'id:' . $identity : 'code:' . $this->canonicalCodeForRow($row)] = $row;
            }
        }

        foreach ($rows as $row) {
            if (in_array($name, $this->legacyAliases($row), true)) {
                $identity = (int)($row[StorageConfig::schema_fields_CONFIG_ID] ?? 0);
                $matches[$identity > 0 ? 'id:' . $identity : 'code:' . $this->canonicalCodeForRow($row)] = $row;
            }
        }
        if (count($matches) > 1) {
            throw new \RuntimeException((string)__('存储磁盘旧别名存在歧义：%{1}', [$name]));
        }

        return $matches === [] ? null : reset($matches);
    }

    /** @return list<array<string,mixed>> */
    private function configuredRows(): array
    {
        if ($this->requestConfiguredRows !== null) {
            return $this->requestConfiguredRows;
        }
        /** @var StorageConfig $model */
        $model = ObjectManager::create(StorageConfig::class, [], false);
        $rows = $model->clearData()->reset()
            ->order(StorageConfig::schema_fields_CONFIG_ID, 'ASC')
            ->limit(1001)
            ->select()
            ->fetchArray();
        $rows = is_array($rows) ? array_values($rows) : [];
        if (count($rows) > 1000) {
            throw new \RuntimeException((string)__('存储磁盘配置数量超过运行时上限。'));
        }
        $this->requestConfiguredRows = $rows;
        StorageRuntimeDiagnostics::configCacheLoaded(count($rows));
        return $rows;
    }

    private function builtinLocalIsExplicitlyConfigured(): bool
    {
        return $this->builtinLocalConfiguredRow() !== null;
    }

    /** @return array<string,mixed>|null */
    private function builtinLocalConfiguredRow(): ?array
    {
        foreach ([StorageDiskCode::BUILTIN_LOCAL_MEDIA, 'local', '__local__'] as $name) {
            $row = $this->findByName($name, $this->configuredRows());
            if ($row !== null) {
                return $row;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $row @return list<string> */
    private function legacyAliases(array $row): array
    {
        $config = json_decode((string)($row[StorageConfig::schema_fields_CONFIG] ?? ''), true);
        $aliases = is_array($config) && is_array($config['legacy_aliases'] ?? null)
            ? $config['legacy_aliases']
            : [];
        $result = [];
        foreach (array_slice($aliases, 0, 100) as $alias) {
            if (!is_string($alias)) {
                continue;
            }
            $alias = strtolower(trim($alias));
            if ($alias !== ''
                && strlen($alias) <= 190
                && preg_match('/[\x00-\x1F\x7F]/', $alias) !== 1
            ) {
                $result[$alias] = $alias;
            }
        }
        return array_values($result);
    }

    /** @param array<string,mixed> $row */
    private function canonicalCodeForRow(array $row): string
    {
        $name = strtolower(trim((string)($row[StorageConfig::schema_fields_NAME] ?? '')));
        try {
            return (string)StorageDiskCode::parse($name);
        } catch (\InvalidArgumentException) {
        }
        $provider = StorageConfig::providerCodeForDriver(
            (string)($row[StorageConfig::schema_fields_DRIVER] ?? 'local'),
        );
        $instance = preg_replace('/[^a-z0-9_-]+/', '_', $name) ?: 'media';
        return (string)StorageDiskCode::fromProvider($provider, trim($instance, '_') ?: 'media');
    }
}
