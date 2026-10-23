<?php

declare(strict_types=1);

namespace Weline\Database\Console\Release;

use Throwable;
use Weline\Database\Service\Release\DatabaseReleaseCoordinator;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Console\CommandInterface;

/** Apply a planned RSSE release (snapshot + expand/transform/contract + commit). */
final class Apply implements CommandInterface
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
            $result = $this->coordinator->apply($releaseId, $this->options($args));
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
        return __('执行 RSSE release：快照 → expand → transform → contract → commit');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp('release:apply', $this->tip(), [
            '--release' => __('release_id（必填）'),
            '--module' => __('只处理指定模块，逗号分隔'),
            '--manifest' => __('release manifest JSON 文件路径'),
            '--signature' => __('manifest 签名（非 baseline 必填）'),
            '--format' => __('输出格式：text（默认）或 json'),
        ], [], [
            'php bin/w release:apply --release=rel-20261023-abcdef',
            'php bin/w release:apply --release=rel-20261023-abcdef --manifest=/tmp/release.json --signature=...',
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
        $signature = trim((string)($args['signature'] ?? ''));
        if ($signature !== '') {
            $options['signature'] = $signature;
        }

        return $options;
    }

    /** @param array<string, mixed> $result */
    private function renderText(array $result): string
    {
        return sprintf(
            'Release: %s expanded=%d transformed=%d contracted=%d snapshot=%s',
            (string)($result['release_id'] ?? ''),
            (int)($result['expanded'] ?? 0),
            (int)($result['transformed'] ?? 0),
            (int)($result['contracted'] ?? 0),
            (string)($result['snapshot_key'] ?? ''),
        );
    }
}
