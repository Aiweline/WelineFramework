<?php

declare(strict_types=1);

namespace Weline\Payment\Console\Payment\DevRelay;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Payment\Service\DevRelayLocalConnectService;

class Stop extends CommandAbstract
{
    public function tip(): string
    {
        return 'Stop local silent Payment DevRelay worker';
    }

    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);
        /** @var DevRelayLocalConnectService $service */
        $service = ObjectManager::getInstance(DevRelayLocalConnectService::class);
        $status = $service->stop();
        $printing->success('DevRelay worker stopped');

        return json_encode(['success' => true, 'status' => $status], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
