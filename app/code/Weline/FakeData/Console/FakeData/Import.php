<?php

declare(strict_types=1);

namespace Weline\FakeData\Console\FakeData;

use Weline\FakeData\Service\FakeDataImportService;
use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

class Import extends CommandAbstract
{
    public const ALIASES = ['fake-data:import'];

    public function __construct(
        private readonly FakeDataImportService $importService,
    ) {
    }

    public function execute(array $args = [], array $data = []): void
    {
        $deployMode = (string)Env::system('deploy', 'prod');
        if (!in_array($deployMode, ['dev', 'development'], true)) {
            $this->printer->error(__('fake-data:import can only run in dev/development mode. Current mode: %{1}', [$deployMode]));
            return;
        }

        if (isset($args['reset']) && !isset($args['force'])) {
            $this->printer->error(__('Use --force together with --reset to confirm fake data cleanup.'));
            return;
        }

        try {
            $report = $this->importService->execute($args);
        } catch (\Throwable $e) {
            $this->printer->error($e->getMessage());
            return;
        }

        $this->printReport($report);
    }

    public function tip(): string
    {
        return 'Import development fake data from extends providers';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'fake-data:import',
            $this->tip(),
            [
                '--provider=<code>' => 'Run one or more provider codes, comma separated.',
                '-m, --module=<Module_Name>' => 'Run providers from one or more modules, comma separated.',
                '--reset' => 'Cleanup selected fake data before seeding.',
                '--force' => 'Required with --reset.',
                '--dry-run' => 'Print execution plan without running providers.',
                '--seed=<value>' => 'Deterministic seed string exposed to providers.',
                '--limit=<n>' => 'Optional max items per provider.',
                '-h, --help' => 'Show help.',
            ],
            [],
            [
                'Dry run' => 'php bin/w fake-data:import --dry-run',
                'Reset WeShop product data' => 'php bin/w fake-data:import --module=WeShop_Product --reset --force',
                'Run one provider' => 'php bin/w fake-data:import --provider=weshop_product',
            ]
        );
    }

    private function printReport(array $report): void
    {
        $this->printer->setup(__('Fake data import plan'));
        $this->printer->note(__('Seed: %{1}', [(string)$report['seed']]));
        foreach ($report['providers'] as $provider) {
            $this->printer->note(sprintf(
                ' - %s (%s) [%s]',
                (string)$provider['code'],
                (string)$provider['module'],
                (string)$provider['label']
            ));
        }

        foreach ($report['warnings'] as $warning) {
            $this->printer->warning($warning);
        }

        if (!empty($report['dry_run'])) {
            $this->printer->success(__('Dry run complete. No fake data was changed.'));
            return;
        }

        $providerCodes = array_map(static fn(array $provider): string => (string)$provider['code'], $report['providers']);
        if (!empty($report['reset'])) {
            foreach (array_reverse($providerCodes) as $code) {
                $this->printProviderStep($code, 'cleanup', $report['results'][$code]['cleanup'] ?? null);
            }
        }
        foreach ($providerCodes as $code) {
            $this->printProviderStep($code, 'seed', $report['results'][$code]['seed'] ?? null);
        }

        $this->printer->success(__('Fake data import finished.'));
    }

    private function printProviderStep(string $code, string $step, ?array $result): void
    {
        if ($result === null) {
            return;
        }
        $this->printer->note(sprintf(
            '%s %s: created=%d updated=%d skipped=%d deleted=%d',
            $code,
            $step,
            (int)($result['created'] ?? 0),
            (int)($result['updated'] ?? 0),
            (int)($result['skipped'] ?? 0),
            (int)($result['deleted'] ?? 0)
        ));
        foreach (($result['warnings'] ?? []) as $warning) {
            $this->printer->warning((string)$warning);
        }
        foreach (($result['errors'] ?? []) as $error) {
            $this->printer->error((string)$error);
        }
    }
}
