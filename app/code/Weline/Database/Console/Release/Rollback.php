<?php

declare(strict_types=1);

namespace Weline\Database\Console\Release;

use Throwable;
use Weline\Database\Service\Release\DatabaseReleaseCoordinator;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Console\CommandInterface;

/** Roll back a release to a target release using the release snapshot (L2). */
final class Rollback implements CommandInterface
{
    public function __construct(private readonly DatabaseReleaseCoordinator $coordinator)
    {
    }

    public function execute(array $args = [], array $data = []): string
    {
        $releaseId = trim((string)($args['release'] ?? ''));
        $target = trim((string)($args['target'] ?? ''));
        if ($releaseId === '' || $target === '') {
            echo 'Error: --release and --target are required' . PHP_EOL;

            return '';
        }

        try {
            $result = $this->coordinator->rollback($releaseId, $target, $this->options($args));
        } catch (Throwable $exception) {
            echo 'Error: ' . $exception->getMessage() . PHP_EOL;

            return '';
        }

        $output = strtolower(trim((string)($args['format'] ?? 'text'))) === 'json'
            ? (string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            : $this->renderText($result);
        echo $output . PHP_EOL;

        return $output;
    }

    public function tip(): string
    {
        return __('回滚 RSSE release 到目标版本（L2 快照恢复）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp('release:rollback', $this->tip(), [
            '--release' => __('当前 release_id（必填）'),
            '--target' => __('目标 release_id（必填）'),
            '--format' => __('输出格式：text（默认）或 json'),
        ], [], [
            'php bin/w release:rollback --release=rel-20261023-abcdef --target=baseline-20261020',
        ]);
    }

    /** @return array<string, mixed> */
    private function options(array $args): array
    {
        $options = [];
        $format = strtolower(trim((string)($args['format'] ?? '')));
        if ($format !== '') {
            $options['format'] = $format;
        }

        return $options;
    }

    /** @param array<string, mixed> $result */
    private function renderText(array $result): string
    {
        return sprintf(
            'Rollback: %s -> %s level=%s snapshot=%s',
            (string)($result['release_id'] ?? ''),
            (string)($result['target_release_id'] ?? ''),
            (string)($result['rollback_level'] ?? ''),
            (string)($result['snapshot_key'] ?? ''),
        );
    }
}
