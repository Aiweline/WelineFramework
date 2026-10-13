<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Console\Fpc\Bake;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Http\Fpc\OfflineBake\FpcOfflineBakeImporter;
use Weline\Framework\Http\Fpc\OfflineBake\FpcOfflineBakeOrigin;

/**
 * php bin/w fpc:bake:import --website=CODE --origin=https://host --dir=/path/to/pkg
 *
 * Writes host-scoped cold-disk + path-index (Always Online / durable). Does not
 * reuse source Shared @ns keys.
 */
final class Import extends CommandAbstract implements CommandInterface
{
    public const ALIASES = [
        'fpc:bake-import',
    ];

    public function tip(): string
    {
        return __('Import an offline FPC bake package into cold disk (fail-closed anti cross-site)');
    }

    public function help(): array|string
    {
        return [
            __('Usage: php bin/w fpc:bake:import --website=CODE --origin=https://host --dir=PKG_DIR'),
            __('  --seed-shared   Also seed router Shared pointers under target-native offline-bake keys'),
        ];
    }

    public function execute(array $args = [], array $data = [])
    {
        $website = \trim((string)($data['website'] ?? ''));
        $origin = FpcOfflineBakeOrigin::normalize((string)($data['origin'] ?? ''));
        $dir = \trim((string)($data['dir'] ?? ''));
        $seedShared = isset($data['seed-shared'])
            || isset($data['seed_shared'])
            || \in_array('--seed-shared', $args, true);

        if ($website === '' || $origin === '' || $dir === '') {
            $this->printer->error(__('Required: --website --origin --dir'));
            return;
        }

        $importer = new FpcOfflineBakeImporter();
        $result = $importer->import($dir, $website, $origin, $seedShared);
        if (!($result['ok'] ?? false)) {
            $this->printer->error(__('Import rejected: %{1}', [$result['reason'] ?? 'unknown']));
            return;
        }
        $this->printer->success(__(
            'Imported %{1} cold-disk entries (shared_seeded=%{2})',
            [
                (string)($result['imported'] ?? 0),
                (string)($result['shared_seeded'] ?? 0),
            ]
        ));
        foreach ((array)($result['failed'] ?? []) as $row) {
            $this->printer->warning((string)$row);
        }
    }
}
