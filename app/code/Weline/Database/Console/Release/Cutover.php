<?php

declare(strict_types=1);

namespace Weline\Database\Console\Release;

use Throwable;
use Weline\Database\Service\Release\ReleaseCutoverService;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Console\CommandInterface;

/** One-way RSSE cutover: baseline + snapshot + optional legacy table archive. */
final class Cutover implements CommandInterface
{
    public function __construct(private readonly ReleaseCutoverService $cutoverService)
    {
    }

    public function execute(array $args = [], array $data = []): string
    {
        $releaseId = trim((string)($args['release'] ?? ''));
        if ($releaseId === '') {
            echo 'Error: --release is required' . PHP_EOL;

            return '';
        }

        $legacyTables = trim((string)($args['legacy-tables'] ?? ''));
        $manifestPath = trim((string)($args['manifest'] ?? ''));
        $manifest = null;
        if ($manifestPath !== '') {
            if (!is_file($manifestPath)) {
                echo 'Error: manifest file not found: ' . $manifestPath . PHP_EOL;
                return '';
            }
            $manifest = json_decode((string)file_get_contents($manifestPath), true);
            if (!is_array($manifest)) {
                echo 'Error: manifest is not a JSON object: ' . $manifestPath . PHP_EOL;
                return '';
            }
        }
        $options = [
            'manifest' => $manifest,
            'legacy_tables' => $legacyTables === ''
                ? []
                : array_values(array_filter(array_map('trim', explode(',', $legacyTables)))),
            'archive_legacy' => !isset($args['no-archive']),
            'archive_suffix' => trim((string)($args['suffix'] ?? '')),
            'verify_actual' => !isset($args['skip-verify']),
        ];

        try {
            $result = $this->cutoverService->run($releaseId, $options);
        } catch (Throwable $exception) {
            echo 'Error: ' . $exception->getMessage() . PHP_EOL;

            return '';
        }

        $output = (string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        echo $output . PHP_EOL;

        return $output;
    }

    public function tip(): string
    {
        return __('一次性 cutover：baseline + 快照 + 旧表归档');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp('release:cutover', $this->tip(), [
            '--release' => __('baseline release_id（必填）'),
            '--manifest' => __('baseline manifest JSON（--actual 生成）'),
            '--legacy-tables' => __('旧表名，逗号分隔'),
            '--suffix' => __('归档后缀，缺省 Ymd'),
            '--no-archive' => __('只建 baseline，不归档旧表'),
            '--skip-verify' => __('跳过实际 schema 指纹校验（仅测试）'),
        ], [], [
            'php bin/w release:cutover --release=baseline-20261023 --legacy-tables=weline_database_migrations,weline_database_backups',
        ]);
    }
}
