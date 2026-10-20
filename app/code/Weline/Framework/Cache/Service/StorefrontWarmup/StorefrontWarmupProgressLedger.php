<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Service\StorefrontWarmup;

/**
 * Durable storefront warmup progress (phase + cursors + last outcome).
 *
 * Written by Framework cron / Clear; Workers never own this file.
 */
final class StorefrontWarmupProgressLedger
{
    public const PHASE_A_HOMEPAGE = 'A';
    public const PHASE_B_SITEMAP_DOCS = 'B';
    public const PHASE_C_EXTRAS = 'C';
    public const PHASE_D_LOCS = 'D';
    public const PHASE_DONE = 'done';

    private const RELATIVE_PATH = 'var/cache/storefront_warmup_progress.json';

    /**
     * @return array{
     *   version:int,
     *   phase:string,
     *   sitemap_doc_cursor:int,
     *   extras_cursor:int,
     *   loc_cursor:int,
     *   priority_homes:list<string>,
     *   priority_home_cursor:int,
     *   last_url:string,
     *   last_status:string,
     *   last_reason:string,
     *   ok:int,
     *   skip:int,
     *   fail:int,
     *   backoff_until:float,
     *   sitemap_docs:list<string>,
     *   extras:list<string>,
     *   locs:list<string>,
     *   updated_at:string
     * }
     */
    public function read(): array
    {
        $path = $this->path();
        if (!\is_file($path)) {
            return $this->emptyState();
        }
        try {
            $decoded = \json_decode((string)\file_get_contents($path), true);
        } catch (\Throwable) {
            return $this->emptyState();
        }
        if (!\is_array($decoded)) {
            return $this->emptyState();
        }

        return $this->normalize($decoded);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function write(array $state): void
    {
        $normalized = $this->normalize($state);
        $normalized['updated_at'] = \gmdate('c');
        $path = $this->path();
        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }
        $tmp = $path . '.' . \getmypid() . '.tmp';
        $json = \json_encode($normalized, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (!\is_string($json)) {
            return;
        }
        if (@\file_put_contents($tmp, $json) === false) {
            return;
        }
        @\rename($tmp, $path);
    }

    public function reset(): array
    {
        $state = $this->emptyState();
        $this->write($state);

        return $state;
    }

    public function path(): string
    {
        $root = \defined('BP') ? (string)BP : (\getcwd() ?: '.');

        return \rtrim($root, '/\\') . '/' . self::RELATIVE_PATH;
    }

    /**
     * @return array{
     *   version:int,
     *   phase:string,
     *   sitemap_doc_cursor:int,
     *   extras_cursor:int,
     *   loc_cursor:int,
     *   priority_homes:list<string>,
     *   priority_home_cursor:int,
     *   last_url:string,
     *   last_status:string,
     *   last_reason:string,
     *   ok:int,
     *   skip:int,
     *   fail:int,
     *   backoff_until:float,
     *   sitemap_docs:list<string>,
     *   extras:list<string>,
     *   locs:list<string>,
     *   updated_at:string
     * }
     */
    private function emptyState(): array
    {
        return [
            'version' => 1,
            'phase' => self::PHASE_A_HOMEPAGE,
            'sitemap_doc_cursor' => 0,
            'extras_cursor' => 0,
            'loc_cursor' => 0,
            'priority_homes' => [],
            'priority_home_cursor' => 0,
            'last_url' => '',
            'last_status' => '',
            'last_reason' => '',
            'ok' => 0,
            'skip' => 0,
            'fail' => 0,
            'backoff_until' => 0.0,
            'last_yield_at' => 0.0,
            'sitemap_docs' => [],
            'extras' => [],
            'locs' => [],
            'updated_at' => '',
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{
     *   version:int,
     *   phase:string,
     *   sitemap_doc_cursor:int,
     *   extras_cursor:int,
     *   loc_cursor:int,
     *   priority_homes:list<string>,
     *   priority_home_cursor:int,
     *   last_url:string,
     *   last_status:string,
     *   last_reason:string,
     *   ok:int,
     *   skip:int,
     *   fail:int,
     *   backoff_until:float,
     *   sitemap_docs:list<string>,
     *   extras:list<string>,
     *   locs:list<string>,
     *   updated_at:string
     * }
     */
    private function normalize(array $raw): array
    {
        $base = $this->emptyState();
        $phase = (string)($raw['phase'] ?? self::PHASE_A_HOMEPAGE);
        if (!\in_array($phase, [
            self::PHASE_A_HOMEPAGE,
            self::PHASE_B_SITEMAP_DOCS,
            self::PHASE_C_EXTRAS,
            self::PHASE_D_LOCS,
            self::PHASE_DONE,
        ], true)) {
            $phase = self::PHASE_A_HOMEPAGE;
        }

        return [
            'version' => \max(1, (int)($raw['version'] ?? 1)),
            'phase' => $phase,
            'sitemap_doc_cursor' => \max(0, (int)($raw['sitemap_doc_cursor'] ?? 0)),
            'extras_cursor' => \max(0, (int)($raw['extras_cursor'] ?? 0)),
            'loc_cursor' => \max(0, (int)($raw['loc_cursor'] ?? 0)),
            'priority_homes' => $this->stringList($raw['priority_homes'] ?? []),
            'priority_home_cursor' => \max(0, (int)($raw['priority_home_cursor'] ?? 0)),
            'last_url' => (string)($raw['last_url'] ?? ''),
            'last_status' => (string)($raw['last_status'] ?? ''),
            'last_reason' => (string)($raw['last_reason'] ?? ''),
            'ok' => \max(0, (int)($raw['ok'] ?? 0)),
            'skip' => \max(0, (int)($raw['skip'] ?? 0)),
            'fail' => \max(0, (int)($raw['fail'] ?? 0)),
            'backoff_until' => \max(0.0, (float)($raw['backoff_until'] ?? 0)),
            'last_yield_at' => \max(0.0, (float)($raw['last_yield_at'] ?? 0)),
            'sitemap_docs' => $this->stringList($raw['sitemap_docs'] ?? []),
            'extras' => $this->stringList($raw['extras'] ?? []),
            'locs' => $this->stringList($raw['locs'] ?? []),
            'updated_at' => (string)($raw['updated_at'] ?? $base['updated_at']),
        ];
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function stringList(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $value = \trim((string)$item);
            if ($value === '') {
                continue;
            }
            $out[] = $value;
        }

        return \array_values(\array_unique($out));
    }
}
