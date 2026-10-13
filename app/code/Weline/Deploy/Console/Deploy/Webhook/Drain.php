<?php

declare(strict_types=1);

namespace Weline\Deploy\Console\Deploy\Webhook;

use Weline\Deploy\Service\DeployWebhookJobDrainService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Output\Cli\Printing;

class Drain extends CommandAbstract
{
    public function __construct(
        Printing $printer,
        private readonly DeployWebhookJobDrainService $drainService
    ) {
        $this->printer = $printer;
    }

    public function tip(): string
    {
        return (string)__('消费 Deploy Webhook 发布队列（CLI/cron；不依赖 sudo）');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'deploy:webhook:drain',
            $this->tip(),
            [
                '--limit=<n>' => '最多处理几条 pending（默认 3）',
                '-h, --help' => '显示帮助',
            ],
            [
                '默认' => 'php bin/w deploy:webhook:drain',
            ],
            'php bin/w deploy:webhook:drain [--limit=3]'
        );
    }

    public function execute(array $args = [], array $data = [])
    {
        $limit = (int)($args['limit'] ?? 3);
        if ($limit < 1) {
            $limit = 1;
        }
        $result = $this->drainService->drain($limit);
        $this->printer->note(sprintf(
            'processed=%d ok=%d failed=%d skipped=%d',
            $result['processed'],
            $result['ok'],
            $result['failed'],
            $result['skipped']
        ));
        foreach ($result['messages'] as $message) {
            $this->printer->note((string)$message);
        }
    }
}
