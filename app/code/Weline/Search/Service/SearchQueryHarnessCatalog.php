<?php

declare(strict_types=1);

namespace Weline\Search\Service;

/**
 * E2E / DEV Search 查询状态叠加（var 文件，避 CLI/Worker 缓存隔离）。
 *
 * @phpstan-type HarnessState array{
 *   direct_rows: list<array<string, mixed>>,
 *   index_docs: array<string, list<array<string, mixed>>>,
 *   rollout_mode: string,
 *   rollout_allowlist: list<string>,
 *   index_forced_down: bool,
 *   direct_down: bool
 * }
 */
final class SearchQueryHarnessCatalog
{
    private const FILE = 'state.json';

    /**
     * @param HarnessState $state
     */
    public static function put(array $state): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('unable to create search_query_harness dir');
        }
        $payload = [
            'direct_rows' => array_values(is_array($state['direct_rows'] ?? null) ? $state['direct_rows'] : []),
            'index_docs' => is_array($state['index_docs'] ?? null) ? $state['index_docs'] : [],
            'rollout_mode' => (string)($state['rollout_mode'] ?? 'off'),
            'rollout_allowlist' => array_values(is_array($state['rollout_allowlist'] ?? null) ? $state['rollout_allowlist'] : []),
            'index_forced_down' => (bool)($state['index_forced_down'] ?? false),
            'direct_down' => (bool)($state['direct_down'] ?? false),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents(self::path(), $json) === false) {
            throw new \RuntimeException('unable to write search_query_harness');
        }
    }

    /**
     * @return HarnessState|null
     */
    public static function load(): ?array
    {
        $path = self::path();
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return [
            'direct_rows' => array_values(is_array($decoded['direct_rows'] ?? null) ? $decoded['direct_rows'] : []),
            'index_docs' => is_array($decoded['index_docs'] ?? null) ? $decoded['index_docs'] : [],
            'rollout_mode' => (string)($decoded['rollout_mode'] ?? 'off'),
            'rollout_allowlist' => array_values(is_array($decoded['rollout_allowlist'] ?? null) ? $decoded['rollout_allowlist'] : []),
            'index_forced_down' => (bool)($decoded['index_forced_down'] ?? false),
            'direct_down' => (bool)($decoded['direct_down'] ?? false),
        ];
    }

    public static function isActive(): bool
    {
        return is_file(self::path());
    }

    public static function clear(): void
    {
        $path = self::path();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Build a SearchQueryService from harness (or empty forTesting defaults).
     */
    public static function buildService(): SearchQueryService
    {
        $state = self::load() ?? [
            'direct_rows' => [],
            'index_docs' => [],
            'rollout_mode' => 'off',
            'rollout_allowlist' => [],
            'index_forced_down' => false,
            'direct_down' => false,
        ];

        $builder = SearchIndexBuilder::forTesting();
        foreach ($state['index_docs'] as $websiteKey => $docs) {
            $websiteId = (int)$websiteKey;
            if ($websiteId < 0 || !is_array($docs)) {
                continue;
            }
            $builder->registry()->ensureWebsite($websiteId);
            $builder->rebuildWebsite($websiteId, array_values($docs));
        }

        $direct = ArrayProductDirectCatalogReader::forTesting($state['direct_rows']);
        if ($state['direct_down']) {
            $direct->markDown(true);
        }

        $svc = SearchQueryService::forTesting($builder, $direct);
        $mode = trim((string)$state['rollout_mode']);
        if ($mode !== '') {
            $svc->rollout()->setMode(
                SearchQueryService::CAPABILITY,
                $mode,
                $state['rollout_allowlist'],
            );
        }
        $svc->forceIndexDown((bool)$state['index_forced_down']);

        return $svc;
    }

    private static function dir(): string
    {
        return rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'search_query_harness';
    }

    private static function path(): string
    {
        return self::dir() . DIRECTORY_SEPARATOR . self::FILE;
    }
}
