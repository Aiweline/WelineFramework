<?php
declare(strict_types=1);

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\Runtime\RuntimeCapabilityDetector;
use Weline\Server\Service\Runtime\RuntimeDiagnosticsFormatter;
use Weline\Server\Service\Runtime\RuntimeStrategyResolver;
use Weline\Server\Service\ServerInstanceManager;

/**
 * server:doctor - read-only WLS runtime diagnostics.
 */
class Doctor extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        $json = isset($args['json']);
        $instanceName = $this->parseInstanceName($args);
        $diagnostics = $this->buildDiagnostics($instanceName);

        if ($json) {
            echo \json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            return;
        }

        $this->printer->setup('WLS Doctor');
        $this->printer->note('Instance: ' . $instanceName);
        $this->printer->note('Status: ' . (string)$diagnostics['status']);
        $strategy = \is_array($diagnostics['strategy'] ?? null) ? $diagnostics['strategy'] : [];
        foreach ((new RuntimeDiagnosticsFormatter())->formatStartupSummary(
            (new RuntimeCapabilityDetector())->detect(),
            $strategy
        ) as $line) {
            if (\str_starts_with($line, 'WARNING:') || \str_starts_with($line, 'Warning:')) {
                $this->printer->warning($line);
            } elseif (\str_starts_with($line, 'INFO:')) {
                $this->printer->note($line);
            } else {
                $this->printer->note($line);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDiagnostics(string $instanceName = 'default'): array
    {
        $profile = (new RuntimeCapabilityDetector())->detect();
        $config = $this->resolveConfigForInstance($instanceName);
        try {
            $strategy = (new RuntimeStrategyResolver())->resolve($config, [], $profile);
        } catch (\RuntimeException $exception) {
            $strategy = [
                'status' => 'unsafe',
                'runtime_strategy' => $config['runtime_strategy'] ?? 'auto',
                'warnings' => [$exception->getMessage()],
            ];
        }
        $diagnostics = (new RuntimeDiagnosticsFormatter())->toDiagnosticArray($profile, $strategy);
        $diagnostics['instance'] = $instanceName;
        $diagnostics['config_source'] = $config['source'] ?? 'runtime/default';

        return $diagnostics;
    }

    private function parseInstanceName(array $args): string
    {
        if (isset($args['instance']) && (string)$args['instance'] !== '') {
            return (string)$args['instance'];
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
     * @return array<string, mixed>
     */
    private function resolveConfigForInstance(string $instanceName): array
    {
        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        $raw = $manager->getRawInstanceData($instanceName);
        $env = \Weline\Framework\App\Env::getInstance()->getConfig() ?: [];
        $wls = \is_array($env['wls'] ?? null) ? $env['wls'] : [];
        $runtime = \is_array($wls['runtime'] ?? null) ? $wls['runtime'] : [];
        $loop = \is_array($wls['loop'] ?? null) ? $wls['loop'] : [];
        $supervisor = \is_array($wls['supervisor'] ?? null) ? $wls['supervisor'] : [];
        $serverConfig = \is_array($wls['servers'][$instanceName] ?? null) ? $wls['servers'][$instanceName] : [];
        $config = \array_merge([
            'worker_count' => 'auto',
            'mode' => 'io',
            'runtime_strategy' => $runtime['strategy'] ?? 'auto',
            'topology' => $runtime['topology'] ?? 'auto',
            'event_loop' => $loop['driver'] ?? 'auto',
            'supervisor' => ['enabled' => $supervisor['enabled'] ?? 'auto'],
            'source' => 'runtime/default',
        ], $wls, $serverConfig);

        if (\is_array($raw)) {
            foreach (['count', 'worker_count', 'mode', 'topology', 'runtime_strategy', 'event_loop'] as $key) {
                if (isset($raw[$key])) {
                    $config[$key === 'count' ? 'worker_count' : $key] = $raw[$key];
                }
            }
            $config['source'] = 'instance record';
        }

        return $config;
    }

    public function tip(): string
    {
        return 'Read-only WLS runtime diagnostics and optimization advice';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:doctor [instance]',
            'Read-only WLS runtime diagnostics',
            [
                '[instance]' => 'Instance name, default: default',
                '--json' => 'Output machine-readable JSON',
            ],
            [],
            [
                'Show diagnostics' => 'php bin/w server:doctor',
                'Show JSON' => 'php bin/w server:doctor --json',
            ]
        );
    }
}
