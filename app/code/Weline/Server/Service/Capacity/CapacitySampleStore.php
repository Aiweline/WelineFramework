<?php
declare(strict_types=1);

namespace Weline\Server\Service\Capacity;

/**
 * Capacity history lives in a JSONL file written by cron.
 * The Master control loop never reads or writes this file.
 */
final class CapacitySampleStore
{
    public function __construct(
        private readonly ?string $directory = null,
    ) {
    }

    /**
     * @param array<string, mixed> $sample
     */
    public function appendIfNew(string $instance, array $sample): bool
    {
        $row = $this->normalize($instance, $sample);
        $path = $this->pathFor($row['instance']);
        $last = $this->lastRow($path);
        if ($last !== null && (string)($last['sampled_at'] ?? '') === $row['sampled_at']) {
            return false;
        }
        $dir = $this->directory();
        if (!\is_dir($dir) && !\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return false;
        }
        $line = \json_encode($row, \JSON_UNESCAPED_UNICODE);
        if (!\is_string($line)) {
            return false;
        }

        return \file_put_contents($path, $line . "\n", \FILE_APPEND | \LOCK_EX) !== false;
    }

    public function cleanup(int $retainDays): int
    {
        $retainDays = \max(1, $retainDays);
        $cutoff = \date('Y-m-d H:i:s', \time() - ($retainDays * 86400));
        $dir = $this->directory();
        if (!\is_dir($dir)) {
            return 0;
        }
        $removed = 0;
        foreach (\glob($dir . \DIRECTORY_SEPARATOR . '*.jsonl') ?: [] as $path) {
            if (!\is_string($path)) {
                continue;
            }
            $kept = [];
            foreach ($this->readRows($path) as $row) {
                if ((string)($row['sampled_at'] ?? '') >= $cutoff) {
                    $kept[] = $row;
                    continue;
                }
                $removed++;
            }
            $this->rewrite($path, $kept);
        }

        return $removed;
    }

    /**
     * @return array{
     *   labels:list<string>,
     *   host_pressure:list<float>,
     *   worker_pressure_avg:list<float>,
     *   worker_pressure_max:list<float>,
     *   connections_sum:list<int>,
     *   compact_delta:list<int>,
     *   soft_pressure:float,
     *   latest_advice:list<string>
     * }
     */
    public function series(string $instance, int $windowSec): array
    {
        $windowSec = \max(60, $windowSec);
        $since = \date('Y-m-d H:i:s', \time() - $windowSec);
        $empty = [
            'labels' => [],
            'host_pressure' => [],
            'worker_pressure_avg' => [],
            'worker_pressure_max' => [],
            'connections_sum' => [],
            'compact_delta' => [],
            'soft_pressure' => 0.70,
            'latest_advice' => [],
        ];
        $paths = $instance !== '' && $instance !== '*'
            ? [$this->pathFor($instance)]
            : (\glob($this->directory() . \DIRECTORY_SEPARATOR . '*.jsonl') ?: []);
        $rows = [];
        foreach ($paths as $path) {
            if (!\is_string($path)) {
                continue;
            }
            foreach ($this->readRows($path) as $row) {
                if ((string)($row['sampled_at'] ?? '') >= $since) {
                    $rows[] = $row;
                }
            }
        }
        \usort($rows, static function (array $left, array $right): int {
            return \strcmp((string)($left['sampled_at'] ?? ''), (string)($right['sampled_at'] ?? ''));
        });
        $out = $empty;
        $latestAdvice = [];
        foreach ($rows as $row) {
            $out['labels'][] = (string)($row['sampled_at'] ?? '');
            $out['host_pressure'][] = (float)($row['host_pressure'] ?? 0);
            $out['worker_pressure_avg'][] = (float)($row['worker_pressure_avg'] ?? 0);
            $out['worker_pressure_max'][] = (float)($row['worker_pressure_max'] ?? 0);
            $out['connections_sum'][] = (int)($row['connections_sum'] ?? 0);
            $out['compact_delta'][] = (int)($row['compact_delta'] ?? 0);
            $out['soft_pressure'] = (float)($row['config_soft_pressure'] ?? 0.7);
            $codes = $row['advice_codes'] ?? [];
            if (\is_array($codes) && $codes !== []) {
                $latestAdvice = \array_values(\array_map('strval', $codes));
            }
        }
        $out['latest_advice'] = $latestAdvice;

        return $out;
    }

    /** @param array<string, mixed> $sample @return array<string, mixed> */
    private function normalize(string $instance, array $sample): array
    {
        $codes = $sample['advice_codes'] ?? [];
        if (!\is_array($codes)) {
            $codes = [];
        }
        $name = \trim($instance) !== '' ? \trim($instance) : 'default';
        $sampledAt = \trim((string)($sample['sampled_at'] ?? ''));
        if ($sampledAt === '') {
            $sampledAt = \date('Y-m-d H:i:s');
        }

        return [
            'instance' => $name,
            'sampled_at' => $sampledAt,
            'host_pressure' => (float)($sample['host_pressure'] ?? 0),
            'worker_pressure_avg' => (float)($sample['worker_pressure_avg'] ?? 0),
            'worker_pressure_max' => (float)($sample['worker_pressure_max'] ?? 0),
            'connections_sum' => (int)($sample['connections_sum'] ?? 0),
            'active_requests_sum' => (int)($sample['active_requests_sum'] ?? 0),
            'compact_delta' => (int)($sample['compact_delta'] ?? 0),
            'advice_codes' => \array_values(\array_map('strval', $codes)),
            'config_soft_pressure' => (float)($sample['config_soft_pressure'] ?? 0.7),
        ];
    }

    private function directory(): string
    {
        if ($this->directory !== null && $this->directory !== '') {
            return $this->directory;
        }

        return (defined('BP') ? BP : '') . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'capacity-samples';
    }

    private function pathFor(string $instance): string
    {
        $name = \trim($instance) !== '' ? \trim($instance) : 'default';
        $safe = \preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        if (!\is_string($safe) || $safe === '' || $safe === '.' || $safe === '..') {
            $safe = 'default';
        }

        return $this->directory() . DIRECTORY_SEPARATOR . $safe . '.jsonl';
    }

    /** @return array<string,mixed>|null */
    private function lastRow(string $path): ?array
    {
        $rows = $this->readRows($path);
        if ($rows === []) {
            return null;
        }
        $last = $rows[\count($rows) - 1];

        return \is_array($last) ? $last : null;
    }

    /** @return list<array<string,mixed>> */
    private function readRows(string $path): array
    {
        if (!\is_file($path)) {
            return [];
        }
        $raw = \file_get_contents($path);
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        $rows = [];
        foreach (\preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            if (!\is_string($line) || \trim($line) === '') {
                continue;
            }
            $decoded = \json_decode($line, true);
            if (\is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /** @param list<array<string,mixed>> $rows */
    private function rewrite(string $path, array $rows): void
    {
        $lines = [];
        foreach ($rows as $row) {
            $encoded = \json_encode($row, \JSON_UNESCAPED_UNICODE);
            if (\is_string($encoded)) {
                $lines[] = $encoded;
            }
        }
        $body = $lines === [] ? '' : \implode("\n", $lines) . "\n";
        \file_put_contents($path, $body, \LOCK_EX);
    }
}
