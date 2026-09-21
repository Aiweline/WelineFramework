<?php

declare(strict_types=1);

namespace Weline\Deploy\Console\Deploy\Env\Map;

use Weline\Deploy\Service\DeployEnvMapService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Output\Cli\Printing;

class Apply extends CommandAbstract
{
    public const ALIASES = [
        'deploy:env-map:apply',
    ];

    public function __construct(
        Printing $printer,
        private readonly DeployEnvMapService $envMapService,
    ) {
        $this->printer = $printer;
    }

    public function tip(): string
    {
        return __('按项目根 deploy.env-map.php 扭转 SystemConfig（无文件则跳过）');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'deploy:env-map:apply',
            $this->tip(),
            [
                '--dry-run' => '只报告变更，不写配置',
                '--level=<档位>' => '覆盖档位探测：dev / staging / prod',
                '--deploy-root=<路径>' => '映射文件所在根（默认当前 BP）',
                '--json' => '输出 JSON 结果',
                '-h, --help' => '显示帮助信息',
            ],
            [
                '文件' => '仅当 {deploy_root}/deploy.env-map.php 存在时生效；该文件不入库',
                'Scope' => 'rules[].scopes 须显式列出 global 或三段 storage scope',
            ],
            [
                '探测' => 'php bin/w deploy:env-map:apply --dry-run',
                '指定生产档' => 'php bin/w deploy:env-map:apply --level=prod',
            ],
            'php bin/w deploy:env-map:apply [选项]'
        );
    }

    public function execute(array $args = [], array $data = [])
    {
        $dryRun = isset($args['dry-run']);
        $json = isset($args['json']);
        $level = isset($args['level']) ? trim((string)$args['level']) : '';
        $deployRoot = isset($args['deploy-root']) ? trim((string)$args['deploy-root']) : '';

        $options = [
            'dry_run' => $dryRun,
            'deploy_root' => $deployRoot,
        ];
        if ($level !== '') {
            $options['target_level'] = $level;
        }

        $result = $this->envMapService->apply($options);

        if ($json) {
            $this->printer->printing(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
        } else {
            $status = (string)($result['status'] ?? '');
            $this->printer->note((string)__('状态：%{1}（%{2}）', [$status, (string)($result['reason'] ?? '')]));
            $this->printer->note((string)__('档位：%{1}', [(string)($result['target_level'] ?? '')]));
            $this->printer->note((string)__('映射文件：%{1}', [(string)($result['map_path'] ?? '')]));
            foreach ((array)($result['changes'] ?? []) as $change) {
                if (!is_array($change)) {
                    continue;
                }
                $this->printer->success(sprintf(
                    '%s [%s] %s @ %s => %s%s',
                    (string)($change['module'] ?? ''),
                    (string)($change['area'] ?? ''),
                    (string)($change['key'] ?? ''),
                    (string)($change['scope'] ?? ''),
                    (string)($change['value'] ?? ''),
                    !empty($change['written']) ? '' : ' (dry-run)',
                ));
            }
            foreach ((array)($result['errors'] ?? []) as $error) {
                $this->printer->error((string)$error);
            }
            if ($status === 'skipped') {
                $this->printer->warning((string)__('未找到映射文件，已跳过。'));
            }
        }

        return ($result['status'] ?? '') === 'failed' ? 1 : 0;
    }
}
