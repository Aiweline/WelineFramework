<?php
declare(strict_types=1);

namespace Weline\Mail\Console\Mail\Service;

use Weline\Mail\Service\StalwartEngineAdapter;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;

class Status extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): void
    {
        /** @var StalwartEngineAdapter $engine */
        $engine = ObjectManager::getInstance(StalwartEngineAdapter::class);
        $result = $engine->service('status');

        $this->printer->note(__('========== 企业邮箱服务状态 =========='));
        if ($result['ok']) {
            $this->printer->success(__('Stalwart 服务状态正常'));
        } else {
            $this->printer->warning(__('Stalwart 服务状态异常或未安装'));
        }
        $this->printer->printing($result['output'] ?: $result['error'] ?: __('无输出'));
    }

    public function tip(): string
    {
        return __('查看企业邮箱服务状态');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'mail:service:status',
            __('查看 Stalwart 服务状态'),
            [],
            [],
            ['查看状态' => 'php bin/w mail:service:status']
        );
    }
}
