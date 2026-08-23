<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Storage\Api\Data\StorageConfigField;
use Weline\Storage\Api\Data\StorageDiskCode;
use Weline\Storage\Api\StorageDriverConfigurationProviderInterface;
use Weline\Storage\Api\StorageDriverProviderInterface;

final class StorageDriverProviderRegistry
{
    public const CAPABILITY_PREFIX = 'storage.driver_provider.';
    private const MAX_PROVIDERS = 64;

    /** @var array<string,class-string>|null */
    private ?array $providerImplementations = null;

    public function __construct(private readonly ?ServiceProviderRegistry $serviceProviders = null)
    {
    }

    public function get(string $providerCode): StorageDriverProviderInterface
    {
        $providerCode = strtolower(trim($providerCode));
        $implementations = $this->providerImplementations();
        if (!isset($implementations[$providerCode])) {
            throw new \RuntimeException((string)__('存储驱动提供者不可用：%{1}', [$providerCode]));
        }
        $provider = ObjectManager::getInstance($implementations[$providerCode]);
        if (!$provider instanceof StorageDriverProviderInterface) {
            throw new \RuntimeException($implementations[$providerCode] . ' must implement ' . StorageDriverProviderInterface::class);
        }
        return $provider;
    }

    /** @return array<string,StorageDriverProviderInterface> */
    public function all(): array
    {
        $providers = [];
        foreach (array_keys($this->providerImplementations()) as $code) {
            $providers[$code] = $this->get($code);
        }
        return $providers;
    }

    public function configurable(string $providerCode): StorageDriverConfigurationProviderInterface
    {
        $provider = $this->get($providerCode);
        if (!$provider instanceof StorageDriverConfigurationProviderInterface) {
            throw new \InvalidArgumentException((string)__('存储驱动未提供可配置契约：%{1}', [$providerCode]));
        }
        $this->validatedConfigurationFields($provider);
        return $provider;
    }

    /** @return array<string,string> */
    public function configurationOptions(): array
    {
        $options = [];
        foreach ($this->all() as $code => $provider) {
            if (!$provider instanceof StorageDriverConfigurationProviderInterface) {
                continue;
            }
            $label = trim($provider->displayName());
            if (
                $label === ''
                || preg_match('//u', $label) !== 1
                || preg_match('/[\x00-\x1F\x7F]/', $label) === 1
                || strlen($label) > 255
            ) {
                throw new \RuntimeException((string)__('存储 Provider 显示名称无效：%{1}', [$code]));
            }
            $this->validatedConfigurationFields($provider);
            $options[$code] = $label;
        }
        return $options;
    }

    /** @return array<string,list<array<string,mixed>>> */
    public function configurationFieldSets(): array
    {
        $sets = [];
        foreach ($this->configurationOptions() as $code => $_label) {
            $sets[$code] = array_map(
                static fn (StorageConfigField $field): array => $field->toArray(),
                $this->validatedConfigurationFields($this->configurable($code)),
            );
        }
        return $sets;
    }

    /** @param array<string,mixed> $config */
    public function objectNamespaceFingerprint(string $providerCode, array $config): string
    {
        $provider = $this->get($providerCode);
        $fingerprint = $provider instanceof StorageDriverConfigurationProviderInterface
            ? strtolower(trim($provider->objectNamespaceFingerprint($config)))
            : hash('sha256', $providerCode . "\0" . $this->canonicalJson($config));
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new \RuntimeException((string)__('存储 Provider 对象命名空间指纹无效：%{1}', [$providerCode]));
        }
        return $fingerprint;
    }

    /** @return array<string,class-string> */
    private function providerImplementations(): array
    {
        if ($this->providerImplementations !== null) {
            return $this->providerImplementations;
        }
        $registry = $this->serviceProviders ?? ObjectManager::getInstance(ServiceProviderRegistry::class);
        $providers = [];
        $implementations = $registry->implementationsWithPrefix(self::CAPABILITY_PREFIX);
        if (count($implementations) > self::MAX_PROVIDERS) {
            throw new \RuntimeException((string)__('存储 Provider 注册数量超过上限。'));
        }
        foreach ($implementations as $implementation) {
            $provider = ObjectManager::getInstance($implementation);
            if (!$provider instanceof StorageDriverProviderInterface) {
                throw new \RuntimeException($implementation . ' must implement ' . StorageDriverProviderInterface::class);
            }
            $code = strtolower(trim($provider->providerCode()));
            try {
                $parsed = StorageDiskCode::fromProvider($code, 'registry_validation');
            } catch (\InvalidArgumentException $exception) {
                throw new \RuntimeException(
                    (string)__('存储 Provider 代码必须为 type::vendor：%{1}', [$code]),
                    0,
                    $exception,
                );
            }
            if ($parsed->providerCode() !== $code) {
                throw new \RuntimeException((string)__('存储 Provider 代码必须为 type::vendor：%{1}', [$code]));
            }
            if (isset($providers[$code])) {
                throw new \RuntimeException((string)__('存储 Provider 重复注册：%{1}', [$code]));
            }
            $providers[$code] = $implementation;
        }
        ksort($providers);
        StorageRuntimeDiagnostics::providerRegistryLoaded(count($providers));
        return $this->providerImplementations = $providers;
    }

    /** @return list<StorageConfigField> */
    private function validatedConfigurationFields(
        StorageDriverConfigurationProviderInterface $provider,
    ): array {
        $fields = $provider->configurationFields();
        if (count($fields) > 64) {
            throw new \RuntimeException((string)__('存储 Provider 配置字段超过上限：%{1}', [$provider->providerCode()]));
        }
        $byKey = [];
        foreach ($fields as $field) {
            if (!$field instanceof StorageConfigField || isset($byKey[$field->key])) {
                throw new \RuntimeException((string)__('存储 Provider 配置字段重复或无效：%{1}', [$provider->providerCode()]));
            }
            $byKey[$field->key] = $field;
        }
        $secrets = $provider->secretConfigurationKeys();
        if (count($secrets) > 32) {
            throw new \RuntimeException((string)__('存储 Provider 密钥字段超过上限：%{1}', [$provider->providerCode()]));
        }
        $secretMap = [];
        foreach ($secrets as $key) {
            if (
                !is_string($key)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1
                || (isset($byKey[$key]) && !$byKey[$key]->secret)
                || isset($secretMap[$key])
            ) {
                throw new \RuntimeException((string)__('存储 Provider 密钥字段定义无效：%{1}', [$provider->providerCode()]));
            }
            $secretMap[$key] = true;
        }
        foreach ($byKey as $key => $field) {
            if ($field->secret && !isset($secretMap[$key])) {
                throw new \RuntimeException((string)__('存储 Provider 密钥字段未声明加密：%{1}', [$provider->providerCode()]));
            }
        }
        return array_values($byKey);
    }

    /** @param array<string,mixed> $value */
    private function canonicalJson(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }
            return $item;
        };
        return json_encode(
            $normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
