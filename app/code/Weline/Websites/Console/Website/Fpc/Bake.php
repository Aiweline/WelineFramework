<?php

declare(strict_types=1);

namespace Weline\Websites\Console\Website\Fpc;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\WebsiteFpcBakeOrchestrator;

/**
 * php bin/w website:fpc:bake --website=CODE --origin=https://host [--dir=PKG]
 */
final class Bake extends CommandAbstract implements CommandInterface
{
    public const ALIASES = [
        'website:sitemap-fpc-bake',
    ];

    public function tip(): string
    {
        return (string)__('Refresh site sitemap URLs then load-aware connect-only FPC bake + optional offline export');
    }

    public function help(): array|string
    {
        return [
            'php bin/w website:fpc:bake --website=CODE --origin=https://host --dir=PKG_DIR' => (string)__('Main entry'),
            '-f / --full' => (string)__('Full power (no load-target throttling)'),
            '--load-target=70' => (string)__('Target CPU load percent (default 70)'),
            '--max-concurrency=N' => (string)__('Max in-flight URLs'),
            '--connect=https://127.0.0.1:9555' => (string)__('Local connect face (never the bake origin host)'),
            '--skip-sitemap-refresh' => (string)__('Skip Seo refresh'),
            '--skip-export' => (string)__('Warm only, no fpc:bake:export'),
            '--dry-run' => (string)__('List planned bake URLs only'),
            '--fail-fast' => (string)__('Stop on first URL failure'),
        ];
    }

    public function execute(array $args = [], array $data = [])
    {
        $options = [
            'website' => \trim((string)($data['website'] ?? '')),
            'origin' => \trim((string)($data['origin'] ?? '')),
            'dir' => \trim((string)($data['dir'] ?? '')),
            'connect' => \trim((string)($data['connect'] ?? '')),
            'module' => \trim((string)($data['module'] ?? '')),
            'load_target' => isset($data['load-target']) ? (float)$data['load-target'] : (isset($data['load_target']) ? (float)$data['load_target'] : 70.0),
            'max_concurrency' => isset($data['max-concurrency']) ? (int)$data['max-concurrency'] : (isset($data['max_concurrency']) ? (int)$data['max_concurrency'] : null),
            'full' => $this->flagPresent($args, $data, ['f', 'full']),
            'dry_run' => $this->flagPresent($args, $data, ['dry-run', 'dry_run']),
            'fail_fast' => $this->flagPresent($args, $data, ['fail-fast', 'fail_fast']),
            'skip_sitemap_refresh' => $this->flagPresent($args, $data, ['skip-sitemap-refresh', 'skip_sitemap_refresh']),
            'skip_export' => $this->flagPresent($args, $data, ['skip-export', 'skip_export']),
        ];
        foreach ($args as $arg) {
            if (!\is_string($arg)) {
                continue;
            }
            if (\str_starts_with($arg, '--website=')) {
                $options['website'] = \trim(\substr($arg, 10));
            } elseif (\str_starts_with($arg, '--origin=')) {
                $options['origin'] = \trim(\substr($arg, 9));
            } elseif (\str_starts_with($arg, '--dir=')) {
                $options['dir'] = \trim(\substr($arg, 6));
            } elseif (\str_starts_with($arg, '--connect=')) {
                $options['connect'] = \trim(\substr($arg, 10));
            }
        }

        /** @var WebsiteFpcBakeOrchestrator $orch */
        $orch = ObjectManager::getInstance(WebsiteFpcBakeOrchestrator::class);
        $result = $orch->run($options);
        if (empty($result['ok'])) {
            $this->printer->error((string)__('website:fpc:bake failed: %{1}', [(string)($result['reason'] ?? 'unknown')]));
            foreach ((array)($result['warnings'] ?? []) as $w) {
                $this->printer->warning((string)$w);
            }

            return 1;
        }
        foreach ((array)($result['warnings'] ?? []) as $w) {
            $this->printer->warning((string)$w);
        }
        $bake = (array)($result['bake'] ?? []);
        if (!empty($options['dry_run'])) {
            $planned = \array_values((array)($bake['planned'] ?? []));
            $total = \count($planned);
            $preview = \array_slice($planned, 0, 20);
            $this->printer->note((string)__('Dry-run planned URLs (%{1}; showing first %{2}):', [
                (string)$total,
                (string)\count($preview),
            ]));
            foreach ($preview as $url) {
                $this->printer->printing('  ' . (string)$url);
            }
            if ($total > \count($preview)) {
                $this->printer->printing('  …');
            }

            return 0;
        }
        $this->printer->success((string)__(
            'Bake done website=%{1} origin=%{2} locs=%{3} warmed=%{4} failed=%{5}',
            [
                (string)($result['website_code'] ?? ''),
                (string)($result['origin'] ?? ''),
                (string)($result['loc_count'] ?? 0),
                (string)($bake['warmed'] ?? 0),
                (string)($bake['failed'] ?? 0),
            ]
        ));
        if (!empty($result['import_hint'])) {
            $this->printer->note((string)__('Production import hint (manual): %{1}', [(string)$result['import_hint']]));
        }

        return (int)($bake['failed'] ?? 0) > 0 ? 2 : 0;
    }

    /**
     * @param list<string> $names
     * @param array<string,mixed> $data
     * @param list<mixed> $args
     */
    private function flagPresent(array $args, array $data, array $names): bool
    {
        foreach ($names as $name) {
            if (\array_key_exists($name, $data)) {
                $v = $data[$name];
                if ($v === true || $v === 1 || $v === '1' || $v === '' || $v === null) {
                    return true;
                }
            }
            $long = '--' . $name;
            foreach ($args as $arg) {
                if (!\is_string($arg)) {
                    continue;
                }
                if ($arg === $long || $arg === '-' . $name || \str_starts_with($arg, $long . '=')) {
                    return true;
                }
            }
        }

        return false;
    }
}
