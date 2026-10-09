<?php

declare(strict_types=1);

namespace Weline\Framework\View;

/**
 * Locale-compile job payload (parent writes, worker reads).
 * Shared by TemplateCompileService process pool and the worker entry script.
 */
final class TemplateCompileLocaleJob
{
    public const PROGRESS_PREFIX = 'WELINE_TPL_COMPILE_PROGRESS';

    /**
     * @param array<string,mixed> $job
     * @param callable|null $onProgress fn(int $done, int $total, string $locale, string $label): void
     * @return list<string>
     */
    public static function run(array $job, ?callable $onProgress = null): array
    {
        $jobRoot = rtrim(str_replace('\\', '/', (string)($job['job_root'] ?? '')), '/');
        if ($jobRoot === '' || !is_dir($jobRoot)) {
            throw new \InvalidArgumentException('template_compile_job_root_invalid');
        }

        $currency = strtoupper(trim((string)($job['currency'] ?? '')));
        $locales = is_array($job['locales'] ?? null) ? $job['locales'] : [];
        $logicalFetches = is_array($job['logical_fetches'] ?? null) ? $job['logical_fetches'] : [];
        $pinnedMeta = is_array($job['pinned'] ?? null) ? $job['pinned'] : [];

        $pinned = [];
        foreach ($pinnedMeta as $logical => $meta) {
            if (!is_string($logical) || $logical === '' || !is_array($meta)) {
                throw new \InvalidArgumentException('template_compile_job_pinned_invalid');
            }
            $rel = trim(str_replace('\\', '/', (string)($meta['bytes_rel'] ?? '')), '/');
            if ($rel === '' || str_contains($rel, '..')) {
                throw new \InvalidArgumentException('template_compile_job_bytes_rel_invalid');
            }
            $bytesPath = $jobRoot . '/' . $rel;
            if (!is_file($bytesPath)) {
                throw new \RuntimeException('template_compile_job_bytes_missing:' . $rel);
            }
            $bytes = file_get_contents($bytesPath);
            if (!is_string($bytes)) {
                throw new \RuntimeException('template_compile_job_bytes_unreadable:' . $rel);
            }
            $pinned[$logical] = [
                'bytes' => $bytes,
                'origin' => (string)($meta['origin'] ?? $logical),
                'context_key' => (string)($meta['context_key'] ?? ''),
                'label' => (string)($meta['label'] ?? basename(str_replace('\\', '/', $logical))),
            ];
        }

        $service = new TemplateCompileService();

        return $service->compilePinnedSourcesInProcess(
            $pinned,
            array_values(array_map('strval', $locales)),
            $currency,
            array_values(array_map('strval', $logicalFetches)),
            $onProgress,
        );
    }

    public static function emitProgressLine(int $done, int $total, string $locale, string $label): void
    {
        $line = self::PROGRESS_PREFIX
            . "\t" . $done
            . "\t" . $total
            . "\t" . str_replace(["\t", "\n", "\r"], ' ', $locale)
            . "\t" . str_replace(["\t", "\n", "\r"], ' ', $label)
            . "\n";
        fwrite(STDERR, $line);
    }

    /**
     * @return array{done:int,total:int,locale:string,label:string}|null
     */
    public static function parseProgressLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || !str_starts_with($line, self::PROGRESS_PREFIX . "\t")) {
            return null;
        }
        $parts = explode("\t", $line, 5);
        if (count($parts) < 5) {
            return null;
        }

        return [
            'done' => (int)$parts[1],
            'total' => (int)$parts[2],
            'locale' => (string)$parts[3],
            'label' => (string)$parts[4],
        ];
    }
}
