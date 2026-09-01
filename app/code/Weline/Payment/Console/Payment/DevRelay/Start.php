<?php

declare(strict_types=1);

namespace Weline\Payment\Console\Payment\DevRelay;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Payment\Service\DevRelayLocalConnectService;

class Start extends CommandAbstract
{
    public function tip(): string
    {
        return 'Start local silent Payment DevRelay worker (online base URL + user API token)';
    }

    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);
        $online = trim((string) ($this->optionValue($args, 'online-base-url') ?? $this->optionValue($args, 'online') ?? ''));
        $token = trim((string) ($this->optionValue($args, 'user-token') ?? $this->optionValue($args, 'token') ?? ''));
        /** @var DevRelayLocalConnectService $service */
        $service = ObjectManager::getInstance(DevRelayLocalConnectService::class);
        try {
            $status = $service->start($online, $token);
            $printing->success('DevRelay worker started pid=' . ($status['pid'] ?? 0));

            return json_encode(['success' => true, 'status' => $status], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        } catch (\Throwable $throwable) {
            $printing->error($throwable->getMessage());

            return json_encode(['success' => false, 'message' => $throwable->getMessage()], JSON_UNESCAPED_UNICODE) ?: '{}';
        }
    }

    private function optionValue(array $args, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        foreach ($args as $arg) {
            if (!\is_string($arg)) {
                continue;
            }
            if (str_starts_with($arg, $prefix)) {
                return substr($arg, strlen($prefix));
            }
        }

        return null;
    }
}
