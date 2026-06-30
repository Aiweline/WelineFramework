<?php
declare(strict_types=1);

namespace Weline\Mail\Console\Mail\Service;

use Weline\Mail\Service\StalwartEngineAdapter;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;

class Stop extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): void
    {
        /** @var StalwartEngineAdapter $engine */
        $engine = ObjectManager::getInstance(StalwartEngineAdapter::class);
        $result = $engine->service('stop');
        $message = $result['output'] ?: $result['error'] ?: __('服务停止命令已执行');
        if ($result['ok']) {
            $this->printer->success($message);
            return;
        }
        $this->printer->warning($message);
    }

    public function tip(): string
    {
        return __('停止企业邮箱服务');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp('mail:service:stop', __('停止 Stalwart 服务'));
    }
}
