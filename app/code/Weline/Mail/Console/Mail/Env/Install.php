<?php
declare(strict_types=1);

namespace Weline\Mail\Console\Mail\Env;

use Weline\Mail\Service\StalwartEngineAdapter;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;

class Install extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): void
    {
        /** @var StalwartEngineAdapter $engine */
        $engine = ObjectManager::getInstance(StalwartEngineAdapter::class);
        $yes = isset($args['y']) || isset($args['yes']) || isset($args['-y']);
        $result = $engine->install($yes);

        $this->printer->note(__('========== 企业邮箱安装计划 =========='));
        foreach (($result['plan']['steps'] ?? []) as $index => $step) {
            $this->printer->printing(($index + 1) . '. ' . $step);
        }
        $this->printer->note('');

        if ($result['ok'] ?? false) {
            $this->printer->success($result['message'] ?? __('安装完成'));
            return;
        }

        if ($result['dry_run'] ?? false) {
            $this->printer->warning($result['message']);
            $this->printer->note(__('框架依赖入口：php bin/w env:install stalwart-mail-server -y'));
            return;
        }

        $this->printer->warning($result['message'] ?? __('安装未执行'));
        if (!empty($result['script'])) {
            $this->printer->note(__('脚本：%{1}', [$result['script']]));
        }
    }

    public function tip(): string
    {
        return __('安装或展示企业邮箱服务依赖');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'mail:env:install',
            __('展示或执行企业邮箱服务原生安装计划'),
            ['-y, --yes' => __('确认执行安装准备动作')],
            [],
            [
                '查看安装计划' => 'php bin/w mail:env:install',
                '确认安装' => 'php bin/w mail:env:install -y',
            ]
        );
    }
}
