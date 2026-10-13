<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Console\Fpc\Bake;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Http\Fpc\OfflineBake\FpcOfflineBakeExporter;
use Weline\Framework\Http\Fpc\OfflineBake\FpcOfflineBakeOrigin;

/**
 * php bin/w fpc:bake:export --website=CODE --origin=https://host --dir=/path/to/pkg
 */
final class Export extends CommandAbstract implements CommandInterface
{
    public const ALIASES = [
        'fpc:bake-export',
    ];

    public function tip(): string
    {
        return __('Export host-scoped FPC cold-disk bodies into an offline bake package');
    }

    public function help(): array|string
    {
        return [
            __('Usage: php bin/w fpc:bake:export --website=CODE --origin=https://host --dir=PKG_DIR'),
            __('  --url=https://host/path   Extra URL to include when cold-disk present (repeatable)'),
            __('  --lang=zh_Hans_CN         Default lang hint when path has no locale prefix'),
            __('  --currency=CNY           Default currency hint'),
        ];
    }

    public function execute(array $args = [], array $data = [])
    {
        $website = \trim((string)($data['website'] ?? ''));
        $origin = FpcOfflineBakeOrigin::normalize((string)($data['origin'] ?? ''));
        $dir = \trim((string)($data['dir'] ?? ''));
        $defaultLang = \trim((string)($data['lang'] ?? 'zh_Hans_CN')) ?: 'zh_Hans_CN';
        $defaultCurrency = \trim((string)($data['currency'] ?? 'CNY')) ?: 'CNY';
        $extraUrls = [];
        if (isset($data['url'])) {
            if (\is_array($data['url'])) {
                foreach ($data['url'] as $u) {
                    $extraUrls[] = (string)$u;
                }
            } else {
                $extraUrls[] = (string)$data['url'];
            }
        }
        foreach ($args as $arg) {
            if (\is_string($arg) && \str_starts_with($arg, '--url=')) {
                $extraUrls[] = \substr($arg, 6);
            }
        }

        if ($website === '' || $origin === '' || $dir === '') {
            $this->printer->error(__('Required: --website --origin --dir'));
            return;
        }

        $exporter = new FpcOfflineBakeExporter();
        $result = $exporter->export($dir, $website, $origin, $extraUrls, $defaultLang, $defaultCurrency);
        if (!($result['ok'] ?? false)) {
            $this->printer->error(__('Export failed: %{1}', [$result['reason'] ?? 'unknown']));
            if (!empty($result['failed'])) {
                foreach ((array)$result['failed'] as $row) {
                    $this->printer->warning((string)$row);
                }
            }
            return;
        }
        $this->printer->success(__(
            'Exported %{1} entries to %{2} (skipped=%{3})',
            [
                (string)($result['entry_count'] ?? 0),
                (string)($result['package_dir'] ?? $dir),
                (string)($result['skipped'] ?? 0),
            ]
        ));
    }
}
