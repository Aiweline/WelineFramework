<?php
declare(strict_types=1);

namespace Weline\Mail\Console\Mail\Env;

use Weline\Mail\Service\StalwartEngineAdapter;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;

class Check extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): void
    {
        /** @var StalwartEngineAdapter $engine */
        $engine = ObjectManager::getInstance(StalwartEngineAdapter::class);
        $result = $engine->checkEnvironment();

        $this->printer->note(__('========== 企业邮箱环境检测 =========='));
        $this->printer->printing(__('平台：%{1}', [$result['platform']]));
        $this->printer->printing(__('引擎：%{1}', [$result['engine']]));
        $this->printer->note('');

        foreach ($result['checks'] as $check) {
            if ($check['ok']) {
                $this->printer->success(__('✔ %{1}', [$check['name']]));
            } else {
                $this->printer->warning(__('✖ %{1}：%{2}', [$check['name'], $check['detail'] ?: __('未满足')]));
            }
        }

        $this->printer->note('');
        if ($result['ok']) {
            $this->printer->success(__('企业邮箱运行环境已满足 ✔'));
            return;
        }

        $this->printer->warning(__('企业邮箱运行环境未完全满足。可运行 php bin/w mail:env:install 查看安装计划。'));
    }

    public function tip(): string
    {
        return __('检测企业邮箱服务环境');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'mail:env:check',
            __('检测 Stalwart、系统服务、关键端口等企业邮箱环境状态'),
            [],
            [],
            ['检测环境' => 'php bin/w mail:env:check']
        );
    }
}
