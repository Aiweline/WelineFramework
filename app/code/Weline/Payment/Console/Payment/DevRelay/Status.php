<?php

declare(strict_types=1);

namespace Weline\Payment\Console\Payment\DevRelay;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Payment\Service\DevRelayLocalConnectService;

class Status extends CommandAbstract
{
    public function tip(): string
    {
        return 'Show local silent Payment DevRelay worker status';
    }

    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);
        /** @var DevRelayLocalConnectService $service */
        $service = ObjectManager::getInstance(DevRelayLocalConnectService::class);
        $status = $service->status();
        $printing->printing(json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');

        return json_encode(['success' => true, 'status' => $status], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
