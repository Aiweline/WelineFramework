<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Api;

use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Event\ResourceChange\ResourceRevisionService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Model\Event\ResourceRevision;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Model\SystemConfigVersion;
use Weline\SystemConfig\Service\SystemConfigResourceChangePublisher;

/**
 * Public configuration storage boundary.
 *
 * New scope-aware writes must pass an explicit scope. setConfig() remains only
 * as the compatibility path for existing global configuration writers.
 */
final class ConfigStore
{
    public const SCOPE_GLOBAL = ConfigReader::SCOPE_GLOBAL;
    public const LOCALE_DEFAULT = ConfigReader::LOCALE_DEFAULT;
    public const area_BACKEND = ConfigReader::area_BACKEND;
    public const area_FRONTEND = ConfigReader::area_FRONTEND;

    private readonly SystemConfig $config;

    public function __construct(?SystemConfig $config = null)
    {
        $this->config = $config ?? ObjectManager::getInstance(SystemConfig::class);
    }

    /**
     * Create a non-shared store bound to one explicit database connection.
     *
     * Migration commands must not reuse the process-wide SystemConfig model:
     * its query state may already belong to the source database.
     */
    public static function forConnection(ConnectionFactory $connection): self
    {
        $config = ObjectManager::create(SystemConfig::class, [], false);
        if (!$config instanceof SystemConfig) {
            throw new \RuntimeException('system_config_isolated_model_unavailable');
        }
        $config->setConnection($connection);
        $version = ObjectManager::make(SystemConfigVersion::class);
        $version->setConnection($connection);
        ObjectManager::setInstance(SystemConfigVersion::class, $version);

        $revision = ObjectManager::make(ResourceRevision::class);
        $revision->setConnection($connection);
        $revisionService = new ResourceRevisionService(
            $revision,
            ObjectManager::getInstance(TransactionCoordinatorInterface::class),
        );
        ObjectManager::setInstance(ResourceRevisionService::class, $revisionService);
        $publisher = new SystemConfigResourceChangePublisher(
            $revisionService,
            ObjectManager::getInstance(ResourceChangeFactory::class),
            ObjectManager::getInstance(NamespacePath::class),
        );
        ObjectManager::setInstance(SystemConfigResourceChangePublisher::class, $publisher);

        return new self($config);
    }

    public function getConfig(
        string $key,
        string $module,
        string $area,
        mixed $default = null,
        ?string $scope = null,
        ?string $locale = null,
    ): mixed {
        return $this->config->getConfig($key, $module, $area, $default, $scope, $locale);
    }

    /**
     * Resolve a config value directly from the current database transaction.
     *
     * Unlike getConfig(), this path intentionally bypasses cache envelopes so
     * an atomic producer can take its after-write snapshot before commit.
     *
     * @return array<string, mixed>
     */
    public function resolveConfig(
        string $key,
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
        mixed $default = null,
    ): array {
        return $this->config->resolveConfig($key, $module, $area, $scope, $locale, $default);
    }

    /**
     * TASK-P1C-001 typed resolve。
     */
    public function resolveTypedConfig(
        string $key,
        string $module,
        string $area,
        \Weline\Framework\Runtime\ScopeIdentity $identity,
        ?string $locale = null,
        mixed $default = null,
    ): \Weline\SystemConfig\Api\Scope\ConfigScopeValue {
        if (\func_num_args() >= 6) {
            return $this->config->resolveTypedConfig($key, $module, $area, $identity, $locale, $default);
        }

        return $this->config->resolveTypedConfig($key, $module, $area, $identity, $locale);
    }

    /** Keep cross-module writes on the caller's already-active main connection. */
    public function useConnection(ConnectionFactory $connection): self
    {
        $this->config->setConnection($connection);
        return $this;
    }

    public function setConfig(string $key, string $value, string $module, string $area): bool
    {
        return $this->config->setConfig($key, $value, $module, $area);
    }

    public function setScopedConfig(
        string $key,
        mixed $value,
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
        array $options = [],
    ): bool {
        return $this->config->setScopedConfig($key, $value, $module, $area, $scope, $locale, $options);
    }

    public function deleteScopedConfig(
        string $key,
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
        array $options = [],
    ): bool {
        return $this->config->deleteScopedConfig($key, $module, $area, $scope, $locale, $options);
    }
}
