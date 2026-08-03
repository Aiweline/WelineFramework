<?php

declare(strict_types=1);

namespace Weline\Cron\Extends\Module\Weline_Framework\Query;

use Weline\Backend\Api\Config\BackendConfigStore;
use Weline\Cron\Schedule\Schedule;
use Weline\Cron\Service\CronAdminReadService;
use Weline\Framework\App\Env;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

final class CronQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly BackendConfigStore $config,
        private readonly Schedule $schedule,
        private readonly CronAdminReadService $adminReadService,
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
            'getRunHelp' => $this->adminReadService->getRunHelp((string)($params['execute_name'] ?? ''))['data'],
            'runLogList' => $this->adminReadService->runLogList((string)($params['execute_name'] ?? ''))['data'],
            'runLogContent' => $this->adminReadService->runLogContent(
                (string)($params['execute_name'] ?? ''),
                (string)($params['file'] ?? '')
            )['data'],
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
            'operations' => [
                [
                    'name' => 'getInstallationStatus',
                    'description' => (string)__('获取当前平台计划任务的安装状态'),
                    'params' => [[
                        'name' => 'scope',
                        'type' => 'string|null',
                        'required' => false,
                        'description' => (string)__('计划任务配置作用域，默认 Weline_Cron'),
                    ]],
                ],
                $this->backendReadOperation(
                    'getRunHelp',
                    (string)__('获取计划任务手动运行帮助'),
                    'Weline_Cron::cron_run_help',
                    2,
                    [[
                        'name' => 'execute_name',
                        'type' => 'string',
                        'required' => true,
                        'max_length' => 255,
                    ]]
                ),
                $this->backendReadOperation(
                    'runLogList',
                    (string)__('获取计划任务运行日志列表'),
                    'Weline_Cron::cron_run_log',
                    2,
                    [[
                        'name' => 'execute_name',
                        'type' => 'string',
                        'required' => true,
                        'max_length' => 255,
                    ]]
                ),
                $this->backendReadOperation(
                    'runLogContent',
                    (string)__('读取计划任务历史日志内容'),
                    'Weline_Cron::cron_run_log',
                    5,
                    [
                        [
                            'name' => 'execute_name',
                            'type' => 'string',
                            'required' => true,
                            'max_length' => 255,
                        ],
                        [
                            'name' => 'file',
                            'type' => 'string',
                            'required' => true,
                            'max_length' => 255,
                        ],
                    ]
                ),
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $params
     * @return array<string,mixed>
     */
    private function backendReadOperation(
        string $name,
        string $description,
        string $aclSource,
        int $cost,
        array $params
    ): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'frontend' => true,
            'auth' => 'backend',
            'mode' => 'read',
            'graph' => false,
            'cost' => $cost,
            'backend_acl' => [
                'kind' => 'source',
                'source_id' => $aclSource,
            ],
            'params' => $params,
            'returns' => ['type' => 'array'],
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
