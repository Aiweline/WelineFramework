<?php

declare(strict_types=1);

namespace Weline\Cron\Extends\Module\Weline_Framework\Query;

use Weline\Backend\Api\Config\BackendConfigStore;
use Weline\Cron\Schedule\Schedule;
use Weline\Framework\App\Env;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

final class CronQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly BackendConfigStore $config,
        private readonly Schedule $schedule,
    ) {
    }

    public function getProviderName(): string
    {
        return 'cron';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getInstallationStatus' => $this->getInstallationStatus($params),
            default => throw new \InvalidArgumentException(
                (string)__('Cron 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'cron',
            'name' => (string)__('计划任务查询'),
            'description' => (string)__('提供计划任务安装状态等公开查询能力'),
            'module' => 'Weline_Cron',
            'operations' => [[
                'name' => 'getInstallationStatus',
                'description' => (string)__('获取当前平台计划任务的安装状态'),
                'params' => [[
                    'name' => 'scope',
                    'type' => 'string|null',
                    'required' => false,
                    'description' => (string)__('计划任务配置作用域，默认 Weline_Cron'),
                ]],
            ]],
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array{installed:bool,cron_name:string,source:string}
     */
    private function getInstallationStatus(array $params): array
    {
        $scope = trim((string)($params['scope'] ?? 'Weline_Cron'));
        $scope = $scope !== '' ? $scope : 'Weline_Cron';
        $cronName = trim((string)($this->config->getConfig(Schedule::cron_config_key, $scope) ?? ''));
        if ($cronName === '') {
            $cronName = Schedule::cron_flag . '-' . md5($scope) . '-' . Schedule::cron_flag;
        }

        try {
            if ($this->schedule->exist($cronName)) {
                return ['installed' => true, 'cron_name' => $cronName, 'source' => 'scheduler'];
            }
        } catch (\Throwable) {
        }

        $suffix = (defined('IS_WIN') && IS_WIN) ? '-cron.vbs' : '-cron.sh';
        $installed = is_file(Env::path_framework_generated . $cronName . $suffix);
        return [
            'installed' => $installed,
            'cron_name' => $cronName,
            'source' => $installed ? 'generated_script' : 'none',
        ];
    }
}
