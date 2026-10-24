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
        $plan = (new DepInstaller())->plan($os);
        if (!$plan['steps']) {
            $this->printer->success(__('依赖均已安装'));
            return 0;
        }
        foreach ($plan['steps'] as $step) {
            $this->printer->note(__('[plan] %{1}', [$step['command']]));
            if (!$yes) {
                continue;
            }
            exec($step['command'], $out, $code);
            if ($code !== 0) {
                $this->printer->error(__('[fail] %{1}', [$step['target']]));
                return CommandResult::shortCircuit(1);
            }
            $this->printer->success(__('[done] %{1}', [$step['target']]));
        }
        return 0;
    }

    public function tip(): string
    {
        return __('按环境自动安装 App 编译依赖（Flutter/JDK/Android SDK）；默认 dry-run，--yes 实装');
    }
}
