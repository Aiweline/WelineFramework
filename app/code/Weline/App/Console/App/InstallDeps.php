<?php
declare(strict_types=1);

namespace Weline\App\Console\App;

use Weline\App\Service\Toolchain\DepInstaller;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandResult;

class InstallDeps extends CommandAbstract
{
    public const ALIASES = ['app:install-deps'];

    public function execute(array $args = [], array $data = [])
    {
        $os = (string)($args['os'] ?? 'auto');
        $yes = isset($args['yes']);
        $installer = new DepInstaller();
        $plan = $installer->plan($os);
        if (!$plan['steps']) {
            $this->printer->success(__('依赖均已安装'));
            return 0;
        }
        if (!$yes) {
            foreach ($plan['steps'] as $step) {
                $this->printer->note(__('[plan] %{1} → %{2}', [$step['target'], $step['command']]));
            }
            return 0;
        }
        $result = $installer->install($os, function (string $line): void {
            $this->printer->note($line);
        });
        foreach ($result['steps'] as $step) {
            if ($step['exit'] !== 0) {
                $this->printer->error(__('[fail] %{1}（exit %{2}）：%{3}', [$step['target'], $step['exit'], $step['output']]));
                return CommandResult::shortCircuit(1);
            }
            $this->printer->success(__('[done] %{1}', [$step['target']]));
        }
        if ($result['missing'] !== []) {
            $this->printer->error(__('安装后仍缺：%{1}', [implode(', ', $result['missing'])]));
            return CommandResult::shortCircuit(1);
        }
        return 0;
    }

    public function tip(): string
    {
        return __('按环境自动安装 App 编译依赖（Flutter/JDK/Android SDK）；默认 dry-run，--yes 实装');
    }
}
