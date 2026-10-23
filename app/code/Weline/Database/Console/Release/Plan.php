<?php

declare(strict_types=1);

namespace Weline\Database\Console\Release;

use Throwable;
use Weline\Database\Service\Release\DatabaseReleaseCoordinator;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Console\CommandInterface;

/** Plan a release without executing DDL. */
final class Plan implements CommandInterface
{
    public function __construct(private readonly DatabaseReleaseCoordinator $coordinator)
    {
    }

    public function execute(array $args = [], array $data = []): string
    {
        $releaseId = trim((string)($args['release'] ?? ''));
        if ($releaseId === '') {
            echo 'Error: --release is required' . PHP_EOL;

            return '';
        }

        try {
            $result = $this->coordinator->plan($releaseId, $this->options($args));
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
        return __('只读规划 RSSE release 的 expand/transform/contract 变更');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp('release:plan', $this->tip(), [
            '--release' => __('release_id（必填）'),
            '--module' => __('只规划指定模块，逗号分隔'),
            '--manifest' => __('release manifest JSON 文件路径'),
            '--format' => __('输出格式：text（默认）或 json'),
        ], [], [
            'php bin/w release:plan --release=rel-20261023-abcdef',
            'php bin/w release:plan --release=rel-20261023-abcdef --module=Weline_Eav --format=json',
        ]);
    }

    /** @return array<string, mixed> */
    private function options(array $args): array
    {
        $options = [];
        $modules = trim((string)($args['module'] ?? ''));
        if ($modules !== '') {
            $options['modules'] = array_values(array_filter(array_map('trim', explode(',', $modules))));
        }
        $manifestPath = trim((string)($args['manifest'] ?? ''));
        if ($manifestPath !== '') {
            if (!is_file($manifestPath)) {
                throw new \InvalidArgumentException('manifest 文件不存在: ' . $manifestPath);
            }
            $manifest = json_decode((string)file_get_contents($manifestPath), true);
            if (!is_array($manifest)) {
                throw new \InvalidArgumentException('manifest 不是合法 JSON 对象: ' . $manifestPath);
            }
            $options['manifest'] = $manifest;
        }

        return $options;
    }

    /** @param array<string, mixed> $result */
    private function renderText(array $result): string
    {
        return sprintf(
            'Release: %s expand=%d transform=%d contract=%d blockers=%d tables=%d',
            (string)($result['release_id'] ?? ''),
            count((array)($result['expand'] ?? [])),
            count((array)($result['transform'] ?? [])),
            count((array)($result['contract'] ?? [])),
            count((array)($result['blockers'] ?? [])),
            (int)($result['table_count'] ?? 0),
        );
    }
}
