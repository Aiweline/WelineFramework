<?php
declare(strict_types=1);

namespace Weline\SystemConfig\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * 系统配置查询器
 *
 * 提供 getConfig/setConfig 能力，供其他模块通过 w_query('system_config', ...) 调用。
 */
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
            'setConfig' => $this->setConfig($params),
            default => throw new \InvalidArgumentException(
                (string)__('SystemConfig 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    private function getConfig(array $params): mixed
    {
        $key = (string)($params['key'] ?? '');
        $module = (string)($params['module'] ?? '');
        $area = (string)($params['area'] ?? SystemConfig::area_BACKEND);

        if ($key === '' || $module === '') {
            return null;
        }

        return $this->systemConfig->getConfig($key, $module, $area);
    }

    private function setConfig(array $params): bool
    {
        $key = (string)($params['key'] ?? '');
        $value = (string)($params['value'] ?? '');
        $module = (string)($params['module'] ?? '');
        $area = (string)($params['area'] ?? SystemConfig::area_BACKEND);

        if ($key === '' || $module === '') {
            return false;
        }

        return $this->systemConfig->setConfig($key, $value, $module, $area);
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'system_config',
            'name' => __('系统配置查询'),
            'description' => __('提供系统配置的读写能力'),
            'module' => 'Weline_SystemConfig',
            'operations' => [
                [
                    'name' => 'getConfig',
                    'description' => __('获取配置值'),
                    'params' => [
                        ['name' => 'key', 'type' => 'string', 'required' => true],
                        ['name' => 'module', 'type' => 'string', 'required' => true],
                        ['name' => 'area', 'type' => 'string', 'required' => false, 'description' => __('backend|frontend')],
                    ],
                ],
                [
                    'name' => 'setConfig',
                    'description' => __('设置配置值'),
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
