<?php
declare(strict_types=1);

namespace Weline\SystemConfig\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\SystemConfig\Model\SystemConfig;

class SystemConfigQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly SystemConfig $systemConfig
    ) {
    }

    public function getProviderName(): string
    {
        return 'system_config';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getConfig' => $this->getConfig($params),
            'getConfigs' => $this->getConfigs($params),
            'setConfig' => $this->setConfig($params),
            default => throw new \InvalidArgumentException(
                (string) __('SystemConfig query provider does not support: %{1}', $operation)
            ),
        };
    }

    private function getConfig(array $params): mixed
    {
        $key = (string) ($params['key'] ?? '');
        $module = (string) ($params['module'] ?? '');
        $area = (string) ($params['area'] ?? SystemConfig::area_BACKEND);

        if ($key === '' || $module === '') {
            return null;
        }

        return $this->systemConfig->getConfig($key, $module, $area);
    }

    /**
     * @return array<string, mixed>
     */
    private function getConfigs(array $params): array
    {
        $module = (string) ($params['module'] ?? '');
        $area = (string) ($params['area'] ?? SystemConfig::area_BACKEND);

        if ($module === '') {
            return [];
        }

        return $this->systemConfig->getConfigMapByModule($module, $area);
    }

    private function setConfig(array $params): bool
    {
        $key = (string) ($params['key'] ?? '');
        $value = (string) ($params['value'] ?? '');
        $module = (string) ($params['module'] ?? '');
        $area = (string) ($params['area'] ?? SystemConfig::area_BACKEND);

        if ($key === '' || $module === '') {
            return false;
        }

        return $this->systemConfig->setConfig($key, $value, $module, $area);
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'system_config',
            'name' => __('System config query'),
            'description' => __('Provides system config read and write operations.'),
            'module' => 'Weline_SystemConfig',
            'operations' => [
                [
                    'name' => 'getConfig',
                    'description' => __('Get a config value.'),
                    'params' => [
                        ['name' => 'key', 'type' => 'string', 'required' => true],
                        ['name' => 'module', 'type' => 'string', 'required' => true],
                        ['name' => 'area', 'type' => 'string', 'required' => false, 'description' => __('backend|frontend')],
                    ],
                ],
                [
                    'name' => 'getConfigs',
                    'description' => __('Get all config values for a module.'),
                    'params' => [
                        ['name' => 'module', 'type' => 'string', 'required' => true],
                        ['name' => 'area', 'type' => 'string', 'required' => false, 'description' => __('backend|frontend')],
                    ],
                ],
                [
                    'name' => 'setConfig',
                    'description' => __('Set a config value.'),
                    'params' => [
                        ['name' => 'key', 'type' => 'string', 'required' => true],
                        ['name' => 'value', 'type' => 'string', 'required' => true],
                        ['name' => 'module', 'type' => 'string', 'required' => true],
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                    ],
                ],
            ],
        ];
    }
}
