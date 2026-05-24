<?php
declare(strict_types=1);

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\ServerInstanceManager;

/**
 * server:scale - resize WLS workers for one explicitly resolved instance.
 */
class Scale extends CommandAbstract
{
    private const IPC_TIMEOUT = 30.0;

    public function execute(array $args = [], array $data = [])
    {
        $instanceName = $this->parseInstanceName($args);
        $workers = $args['workers'] ?? $args['worker'] ?? $args['count'] ?? $args['c'] ?? $data['workers'] ?? null;
        $status = isset($args['status']) || isset($data['status']);
        $auto = isset($args['auto']) || isset($data['auto']);
        $noAuto = isset($args['no-auto']) || isset($args['no_auto']) || isset($data['no-auto']);

        if ($auto || $noAuto) {
            $this->printer->warning('Persistent auto-scaling writes are disabled in this lifecycle-safe command path.');
            $this->printer->note('Use instance-scoped env config wls.servers.' . $instanceName . '.scaling and then run server:reload ' . $instanceName . '.');
            return;
        }

        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        $resolvedName = $manager->resolvePersistedInstanceName($instanceName) ?? $instanceName;
        if (!$manager->hasInstance($resolvedName)) {
            $this->printer->error('WLS instance not found: ' . $instanceName);
            $suggestions = $manager->suggestPersistedInstanceNames($instanceName);
            if ($suggestions !== []) {
                $this->printer->note('Candidates: ' . \implode(', ', $suggestions));
            }
            return;
        }
        if ($resolvedName !== $instanceName) {
            $this->printer->note("Instance {$instanceName} resolved to {$resolvedName}");
        }

        $gateway = new IpcControlGateway();
        if ($status || $workers === null) {
            $this->printStatus($gateway->scalingStatus($resolvedName, 6.0));
            return;
        }

        if (!\is_numeric($workers)) {
            $this->printer->error('--workers must be a positive integer');
            return;
        }

        $targetWorkers = (int)$workers;
        if ($targetWorkers < 1 || $targetWorkers > 128) {
            $this->printer->error('--workers must be between 1 and 128');
            return;
        }

        $this->printer->note("Scaling instance {$resolvedName} to {$targetWorkers} worker(s)...");
        $this->printScaleResult($gateway->scaleWorkers($resolvedName, $targetWorkers, self::IPC_TIMEOUT));
    }

    private function parseInstanceName(array $args): string
    {
        if (isset($args['instance']) && (string)$args['instance'] !== '') {
            return (string)$args['instance'];
        }
        if (isset($args['name']) && (string)$args['name'] !== '') {
            return (string)$args['name'];
        }

        $positional = [];
        foreach ($args as $key => $arg) {
            if (\is_int($key) && !\str_starts_with((string)$arg, '-')) {
                $positional[] = (string)$arg;
            }
        }
        \array_shift($positional);

        return $positional[0] ?? 'default';
    }

    /**
     * @param array<string, mixed> $result
     */
    private function printStatus(array $result): void
    {
        if (empty($result['success'])) {
            $this->printer->error((string)($result['message'] ?? 'Scaling status failed'));
            return;
        }

        $data = \is_array($result['data'] ?? null) ? $result['data'] : [];
        $this->printer->setup('WLS worker scaling status');
        $this->printer->note('Instance: ' . (string)($result['instance'] ?? ($data['instance'] ?? 'unknown')));
        $this->printer->note('Current workers: ' . (string)($data['current_workers'] ?? 0));
        $this->printer->note('Ready workers: ' . (string)($data['ready_workers'] ?? 0));
        $this->printer->note('Desired workers: ' . (string)($data['desired_workers'] ?? 0));
        $this->printer->note('Auto-scaling: ' . (!empty($data['enabled']) ? 'enabled' : 'disabled'));
        $this->printer->note('Locked: ' . (!empty($data['locked']) ? 'yes' : 'no'));
    }

    /**
     * @param array<string, mixed> $result
     */
    private function printScaleResult(array $result): void
    {
        $message = (string)($result['message'] ?? '');
        if (empty($result['success'])) {
            $this->printer->error($message !== '' ? $message : 'Scale command failed');
            return;
        }

        $data = \is_array($result['data'] ?? null) ? $result['data'] : [];
        $this->printer->success($message !== '' ? $message : 'Scale command completed');
        $this->printer->note('Status: ' . (string)($result['status'] ?? ($data['status'] ?? 'unknown')));
        $this->printer->note('Current workers: ' . (string)($data['current_workers'] ?? '?'));
        if (!empty($data['added_pids']) && \is_array($data['added_pids'])) {
            $this->printer->note('Added PIDs: ' . \implode(', ', $data['added_pids']));
        }
        if (!empty($data['removed_pids']) && \is_array($data['removed_pids'])) {
            $this->printer->note('Removed PIDs: ' . \implode(', ', $data['removed_pids']));
        }
    }

    public function tip(): string
    {
        return 'Resize WLS worker count for a selected instance';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:scale [instance] --workers=<n>',
            'Resize WLS worker count through the Master control plane',
            [
                '[instance]' => 'Instance name, default: default',
                '--workers <n>' => 'Target worker count',
                '--status' => 'Show scaling status',
                '--instance <name>' => 'Explicit instance name',
            ],
            [
                'Safety' => 'The command never selects the first running instance implicitly; it resolves the requested instance name.',
            ],
            [
                'Show status' => 'php bin/w server:scale api --status',
                'Scale to 8 workers' => 'php bin/w server:scale api --workers=8',
            ]
        );
    }
}
