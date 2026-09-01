<?php

declare(strict_types=1);

namespace Weline\Payment\Console\Payment\DevRelay;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\DevRelayLocalConnectService;

/**
 * Blocking worker loop (spawned by start).
 */
class Run extends CommandAbstract
{
    public function tip(): string
    {
        return 'Run Payment DevRelay SSE worker in foreground (internal)';
    }

    public function execute(array $args = [], array $data = []): string
    {
        /** @var DevRelayLocalConnectService $service */
        $service = ObjectManager::getInstance(DevRelayLocalConnectService::class);
        $cred = '';
        foreach ($args as $arg) {
            if (\is_string($arg) && str_starts_with($arg, '--cred-file=')) {
                $cred = substr($arg, strlen('--cred-file='));
            }
        }
        if ($cred === '') {
            return 'missing --cred-file';
        }
        $code = $service->runFromCredFile($cred);
        exit($code);
    }
}
