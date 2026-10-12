<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Service\StorefrontWarmup;

/**
 * Ad-hoc storefront FPC warm URL queue (Changed purge refill + module offers).
 *
 * Durable JSON under var/cache; Cron StorefrontWarmup drains before phases A–D.
 * Not CDN Warmup — origin FPC only.
 */
final class StorefrontWarmupOfferQueue
{
    public const RELATIVE_PATH = 'var/cache/storefront_warmup_offers.json';
    public const MAX_OFFER_BATCH = 256;
    public const MAX_PENDING = 512;

    /**
     * @param list<string> $urls Absolute http(s) URLs or storefront paths
     * @param array<string, mixed> $meta
     * @return int Accepted new URL count (after dedupe / caps)
     */
    public function offer(array $urls, string $source, array $meta = []): int
    {
        $source = trim($source);
        if ($source === '') {
            $source = 'unknown';
        }
        $normalized = [];
        foreach ($urls as $url) {
            $canon = $this->canonicalize((string)$url);
            if ($canon === '') {
                continue;
            }
            $normalized[$canon] = $canon;
            if (count($normalized) >= self::MAX_OFFER_BATCH) {
                break;
            }
        }
        if ($normalized === []) {
            return 0;
        }

        $fh = $this->openLocked();
        if ($fh === null) {
            return 0;
        }
        try {
            $state = $this->readLocked($fh);
            $pending = is_array($state['pending'] ?? null) ? $state['pending'] : [];
            $seen = [];
            foreach ($pending as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $u = trim((string)($row['url'] ?? ''));
                if ($u !== '') {
                    $seen[$u] = true;
                }
            }
            $accepted = 0;
            $now = gmdate('c');
            foreach ($normalized as $url) {
                if (isset($seen[$url])) {
                    continue;
                }
                $pending[] = [
                    'url' => $url,
                    'source' => $source,
                    'offered_at' => $now,
                    'meta' => $meta,
                ];
                $seen[$url] = true;
                $accepted++;
            }
            $degraded = false;
            while (count($pending) > self::MAX_PENDING) {
                array_shift($pending);
                $degraded = true;
            }
            $state['version'] = 1;
            $state['pending'] = array_values($pending);
            $state['degraded'] = $degraded || !empty($state['degraded']);
            $state['updated_at'] = $now;
            $this->writeLocked($fh, $state);

            return $accepted;
        } finally {
            $this->closeLocked($fh);
        }
    }

    /**
     * @return list<array{url:string,source:string,offered_at:string,meta:array<string,mixed>}>
     */
    public function take(int $limit = 3): array
    {
        $limit = max(1, min(24, $limit));
        $fh = $this->openLocked();
        if ($fh === null) {
            return [];
        }
        try {
            $state = $this->readLocked($fh);
            $pending = is_array($state['pending'] ?? null) ? $state['pending'] : [];
            $taken = [];
            $rest = [];
            foreach ($pending as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $url = trim((string)($row['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                if (count($taken) < $limit) {
                    $taken[] = [
                        'url' => $url,
                        'source' => trim((string)($row['source'] ?? '')),
                        'offered_at' => (string)($row['offered_at'] ?? ''),
                        'meta' => is_array($row['meta'] ?? null) ? $row['meta'] : [],
                    ];
                } else {
                    $rest[] = $row;
                }
            }
            $state['pending'] = array_values($rest);
            $state['updated_at'] = gmdate('c');
            $this->writeLocked($fh, $state);

            return $taken;
        } finally {
            $this->closeLocked($fh);
        }
    }

    public function pendingCount(): int
    {
        $path = $this->path();
        if (!is_file($path)) {
            return 0;
        }
        try {
            $decoded = json_decode((string)file_get_contents($path), true);
        } catch (\Throwable) {
            return 0;
        }
        if (!is_array($decoded) || !is_array($decoded['pending'] ?? null)) {
            return 0;
        }

        return count($decoded['pending']);
    }

    public function path(): string
    {
        $root = defined('BP') ? (string)BP : (getcwd() ?: '.');

        return rtrim($root, '/\\') . '/' . self::RELATIVE_PATH;
    }

    private function canonicalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            $parts = parse_url($url);
            if ($parts === false || empty($parts['host'])) {
                return '';
            }
            $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
            $host = strtolower((string)$parts['host']);
            $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
            $path = (string)($parts['path'] ?? '/');
            if ($path === '') {
                $path = '/';
            }
            $query = '';
            if (!empty($parts['query'])) {
                parse_str((string)$parts['query'], $params);
                if (is_array($params)) {
                    unset($params['_wl'], $params['_wc'], $params['_wb_nc'], $params['utm_source'], $params['utm_medium'], $params['utm_campaign']);
                    if ($params !== []) {
                        ksort($params);
                        $query = '?' . http_build_query($params);
                    }
                }
            }

            return $scheme . '://' . $host . $port . $path . $query;
        }
        if ($url[0] !== '/') {
            $url = '/' . $url;
        }

        return $url;
    }

    /** @return resource|null */
    private function openLocked()
    {
        $path = $this->path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            return null;
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);

            return null;
        }

        return $fh;
    }

    /** @param resource $fh */
    private function closeLocked($fh): void
    {
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    /**
     * @param resource $fh
     * @return array{version:int,pending:list<array<string,mixed>>,degraded:bool,updated_at:string}
     */
    private function readLocked($fh): array
    {
        rewind($fh);
        $raw = stream_get_contents($fh);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return ['version' => 1, 'pending' => [], 'degraded' => false, 'updated_at' => ''];
        }

        return [
            'version' => (int)($decoded['version'] ?? 1),
            'pending' => is_array($decoded['pending'] ?? null) ? array_values($decoded['pending']) : [],
            'degraded' => !empty($decoded['degraded']),
            'updated_at' => (string)($decoded['updated_at'] ?? ''),
        ];
    }

    /**
     * @param resource $fh
     * @param array{version:int,pending:list<array<string,mixed>>,degraded:bool,updated_at:string} $state
     */
    private function writeLocked($fh, array $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return;
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $json);
        fflush($fh);
    }
}
