<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Temporary worker memory diagnostics (NDJSON → var/log/memdiag.log).
 *
 * Arm with query/cookie `__memdiag=1` or touch `var/memdiag.on`.
 * Disarm: remove the file and omit the flag.
 */
final class MemDiag
{
    private const FLAG_FILE = 'memdiag.on';
    private const LOG_FILE = 'memdiag.log';
    private const QUERY_KEY = '__memdiag';

    private static ?bool $armed = null;
    private static int $seq = 0;
    /** @var array{uri?:string,pid?:int,worker_id?:string,request_count?:int,t0?:float,used0?:int,real0?:int}|null */
    private static ?array $request = null;

    public static function armFromRequest(?string $requestUri = null): void
    {
        $uri = $requestUri ?? (string)($_SERVER['REQUEST_URI'] ?? '');
        $on = false;
        if (isset($_GET[self::QUERY_KEY]) && (string)$_GET[self::QUERY_KEY] !== '0') {
            $on = true;
        } elseif (isset($_COOKIE[self::QUERY_KEY]) && (string)$_COOKIE[self::QUERY_KEY] !== '0') {
            $on = true;
        } elseif ($uri !== '' && \str_contains($uri, self::QUERY_KEY . '=')) {
            $on = !\str_contains($uri, self::QUERY_KEY . '=0');
        } elseif (\is_file(self::flagPath())) {
            $on = true;
        }
        self::$armed = $on;
    }

    public static function isArmed(): bool
    {
        if (self::$armed === null) {
            self::$armed = \is_file(self::flagPath());
        }
        return self::$armed;
    }

    public static function requestBegin(array $meta = []): void
    {
        if (!self::isArmed()) {
            return;
        }
        self::$request = [
            'uri' => (string)($meta['uri'] ?? ($_SERVER['REQUEST_URI'] ?? '')),
            'pid' => (int)($meta['pid'] ?? (\function_exists('getmypid') ? \getmypid() : 0)),
            'worker_id' => (string)($meta['worker_id'] ?? ($_SERVER['WLS_WORKER_ID'] ?? '')),
            'request_count' => (int)($meta['request_count'] ?? 0),
            't0' => \microtime(true),
            'used0' => \memory_get_usage(false),
            'real0' => \memory_get_usage(true),
        ];
        self::event('request_begin', [
            'uri' => self::$request['uri'],
            'request_count' => self::$request['request_count'],
            'caches' => self::cacheSnapshot(),
        ]);
    }

    public static function requestEnd(): void
    {
        if (!self::isArmed() || self::$request === null) {
            return;
        }
        $used = \memory_get_usage(false);
        $real = \memory_get_usage(true);
        self::event('request_end', [
            'uri' => self::$request['uri'] ?? '',
            'request_count' => self::$request['request_count'] ?? 0,
            'elapsed_ms' => \round(((\microtime(true) - (float)(self::$request['t0'] ?? \microtime(true))) * 1000), 2),
            'delta_used' => $used - (int)(self::$request['used0'] ?? 0),
            'delta_real' => $real - (int)(self::$request['real0'] ?? 0),
            'peak' => \memory_get_peak_usage(true),
            'caches' => self::cacheSnapshot(),
        ]);
        // Keep uri/request_count for after_reset; clear t0 markers.
        self::$request = [
            'uri' => self::$request['uri'] ?? '',
            'request_count' => self::$request['request_count'] ?? 0,
            'used_at_end' => $used,
            'real_at_end' => $real,
        ];
    }

    /** Called after WlsRuntime::reset() — true retained baseline between requests. */
    public static function afterReset(): void
    {
        if (!self::isArmed() || self::$request === null) {
            return;
        }
        $beforeGc = \memory_get_usage(false);
        $cycles = 0;
        if (\function_exists('gc_collect_cycles')) {
            $cycles = (int)\gc_collect_cycles();
        }
        $afterGc = \memory_get_usage(false);
        self::event('after_reset', [
            'uri' => self::$request['uri'] ?? '',
            'request_count' => self::$request['request_count'] ?? 0,
            'used_at_end' => (int)(self::$request['used_at_end'] ?? 0),
            'delta_end_to_reset' => $beforeGc - (int)(self::$request['used_at_end'] ?? $beforeGc),
            'gc_cycles' => $cycles,
            'gc_freed' => $beforeGc - $afterGc,
            'caches' => self::cacheSnapshot(),
        ]);
        self::$request = null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function event(string $name, array $data = []): void
    {
        if (!self::isArmed()) {
            return;
        }
        $payload = [
            'ts' => \gmdate('Y-m-d\TH:i:s.v\Z'),
            'seq' => ++self::$seq,
            'event' => $name,
            'pid' => \function_exists('getmypid') ? (int)\getmypid() : 0,
            'worker_id' => (string)($_SERVER['WLS_WORKER_ID'] ?? ''),
            'used' => \memory_get_usage(false),
            'real' => \memory_get_usage(true),
            'peak' => \memory_get_peak_usage(true),
            'data' => $data,
        ];
        $line = \json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            return;
        }
        $path = self::logPath();
        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }
        @\file_put_contents($path, $line . "\n", \FILE_APPEND | \LOCK_EX);
    }

    /** @return array<string, mixed> */
    public static function cacheSnapshot(): array
    {
        $out = [
            'hotcache_entries' => 0,
            'hotcache_payload_bytes' => 0,
            'hotcache_by_pool' => [],
            'hotcache_keys_sample' => [],
            'phrase_locale_words_keys' => 0,
            'phrase_global_locale_keys' => 0,
            'phrase_word_cache_keys' => 0,
            'phrase_words_cache_keys' => 0,
            'projection_snapshot_keys' => 0,
            'tpl_view_file_cache' => 0,
            'tpl_static_hook_cache' => 0,
            'om_main_instances' => 0,
            'om_origin_instances' => 0,
            'om_reflections' => 0,
            'tracked_bytes' => 0,
        ];
        $tracked = 0;
        try {
            $rp = new \ReflectionProperty(\Weline\Framework\Cache\Service\StorefrontScopeHotCache::class, 'processCache');
            $rp->setAccessible(true);
            /** @var array<string, mixed> $cache */
            $cache = $rp->getValue();
            if (\is_array($cache)) {
                $out['hotcache_entries'] = \count($cache);
                $out['hotcache_keys_sample'] = \array_slice(\array_keys($cache), 0, 8);
                $byPool = [];
                $payloadBytes = 0;
                foreach ($cache as $key => $entry) {
                    $payload = \is_array($entry) ? ($entry['payload'] ?? null) : null;
                    $size = self::estimateBytes($payload);
                    $payloadBytes += $size;
                    $pool = \is_string($key) ? (\explode('|', $key, 2)[0] ?? 'unknown') : 'unknown';
                    $byPool[$pool] = (int)($byPool[$pool] ?? 0) + $size;
                }
                \arsort($byPool);
                $out['hotcache_payload_bytes'] = $payloadBytes;
                $out['hotcache_by_pool'] = \array_slice($byPool, 0, 12, true);
                $tracked += $payloadBytes;
            }
        } catch (\Throwable) {
        }
        try {
            foreach ([
                'workerLocaleWordsCache' => 'phrase_locale_words_keys',
                'workerGlobalDictionaryLocaleWords' => 'phrase_global_locale_keys',
                'workerGlobalDictionaryWordCache' => 'phrase_word_cache_keys',
                'workerGlobalDictionaryWordsCache' => 'phrase_words_cache_keys',
            ] as $prop => $key) {
                $rp = new \ReflectionProperty(\Weline\Framework\Phrase\Parser::class, $prop);
                $rp->setAccessible(true);
                $val = $rp->getValue();
                $out[$key] = \is_array($val) ? \count($val) : 0;
                if (\is_array($val)) {
                    $bytes = self::estimateBytes($val);
                    $out[$key . '_bytes'] = $bytes;
                    $tracked += $bytes;
                }
            }
        } catch (\Throwable) {
        }
        try {
            $rp = new \ReflectionProperty(\Weline\Product\Service\ProductSearchProjectionService::class, 'snapshotProcessCache');
            $rp->setAccessible(true);
            $val = $rp->getValue();
            $out['projection_snapshot_keys'] = \is_array($val) ? \count($val) : 0;
            if (\is_array($val)) {
                $bytes = self::estimateBytes($val);
                $out['projection_snapshot_bytes'] = $bytes;
                $tracked += $bytes;
            }
        } catch (\Throwable) {
        }
        try {
            $projector = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Product\Service\StorefrontProductDetailProjector::class,
            );
            $rp = new \ReflectionProperty($projector, 'labelsByProductId');
            $rp->setAccessible(true);
            $labels = $rp->getValue($projector);
            $rp2 = new \ReflectionProperty($projector, 'axesByProductId');
            $rp2->setAccessible(true);
            $axes = $rp2->getValue($projector);
            $out['pdp_labels_by_product'] = \is_array($labels) ? \count($labels) : 0;
            $out['pdp_axes_by_product'] = \is_array($axes) ? \count($axes) : 0;
        } catch (\Throwable) {
        }
        try {
            $media = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Product\Service\StorefrontProductMediaUrlResolver::class,
            );
            $rp = new \ReflectionProperty($media, 'resolvedReferenceCache');
            $rp->setAccessible(true);
            $cache = $rp->getValue($media);
            $out['media_ref_cache'] = \is_array($cache) ? \count($cache) : 0;
        } catch (\Throwable) {
        }
        try {
            $eav = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Product\Service\StorefrontEavLabelResolver::class,
            );
            foreach (['attributeMetadataByCode' => 'eav_attr_meta', 'privateOptionCache' => 'eav_private_opts'] as $prop => $key) {
                $rp = new \ReflectionProperty($eav, $prop);
                $rp->setAccessible(true);
                $val = $rp->getValue($eav);
                $out[$key] = \is_array($val) ? \count($val) : 0;
                if (\is_array($val)) {
                    $bytes = self::estimateBytes($val);
                    $out[$key . '_bytes'] = $bytes;
                    $tracked += $bytes;
                }
            }
        } catch (\Throwable) {
        }
        try {
            if (\class_exists(\Weline\Theme\Api\Runtime\ProcessCacheResetter::class)) {
                $out['theme_process_cache_items'] = (int)\Weline\Theme\Api\Runtime\ProcessCacheResetter::processCacheItemCount();
            }
        } catch (\Throwable) {
        }
        try {
            if (\class_exists(\Weline\Framework\View\Template::class)
                && \method_exists(\Weline\Framework\View\Template::class, 'processViewFileCacheItemCount')
            ) {
                $out['tpl_view_file_cache'] = (int)\Weline\Framework\View\Template::processViewFileCacheItemCount();
            }
            if (\class_exists(\Weline\Framework\View\Template::class)
                && \method_exists(\Weline\Framework\View\Template::class, 'processStaticHookOutputCacheItemCount')
            ) {
                $out['tpl_static_hook_cache'] = (int)\Weline\Framework\View\Template::processStaticHookOutputCacheItemCount();
            }
        } catch (\Throwable) {
        }
        try {
            $rp = new \ReflectionProperty(\Weline\Framework\Database\AbstractModel::class, 'loadIdentityMap');
            $rp->setAccessible(true);
            $map = $rp->getValue();
            if (\is_array($map)) {
                $reqBuckets = \count($map);
                $entries = 0;
                foreach ($map as $bucket) {
                    if (\is_array($bucket)) {
                        $entries += \count($bucket);
                    }
                }
                $bytes = self::estimateBytes($map);
                $out['model_identity_req_buckets'] = $reqBuckets;
                $out['model_identity_entries'] = $entries;
                $out['model_identity_bytes'] = $bytes;
                $tracked += $bytes;
            }
        } catch (\Throwable) {
        }
        try {
            $cm = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Framework\Cache\CacheManager::class,
            );
            $rp = new \ReflectionProperty($cm, 'pools');
            $rp->setAccessible(true);
            $pools = $rp->getValue($cm);
            $adapterItems = 0;
            $adapterBytes = 0;
            $adapterPools = 0;
            if (\is_array($pools)) {
                foreach ($pools as $pool) {
                    if (!\is_object($pool) || !\method_exists($pool, 'getAdapter')) {
                        continue;
                    }
                    try {
                        $adapter = $pool->getAdapter();
                    } catch (\Throwable) {
                        continue;
                    }
                    if (!$adapter instanceof \Weline\Framework\Cache\Adapter\WlsMemoryAdapter) {
                        continue;
                    }
                    $adapterPools++;
                    $adapterItems += (int)$adapter->getMemoryItemCount();
                    $adapterBytes += (int)$adapter->getMemoryUsage();
                }
            }
            $out['wls_adapter_pools'] = $adapterPools;
            $out['wls_adapter_local_items'] = $adapterItems;
            $out['wls_adapter_local_bytes'] = $adapterBytes;
            $tracked += $adapterBytes;
        } catch (\Throwable) {
        }
        try {
            if (\class_exists(\Weline\Framework\Router\FullPageCacheCoordinator::class)) {
                $fpc = \Weline\Framework\Router\FullPageCacheCoordinator::class;
                foreach ([
                    'processFpcPayloadCache' => 'fpc_process_items',
                    'processFpcPayloadTotalBytes' => 'fpc_process_bytes',
                    'processFormattedFpcCache' => 'fpc_formatted_items',
                    'processFormattedFpcTotalBytes' => 'fpc_formatted_bytes',
                ] as $prop => $key) {
                    $rp = new \ReflectionProperty($fpc, $prop);
                    $rp->setAccessible(true);
                    $val = $rp->getValue();
                    if (\is_array($val)) {
                        $out[$key] = \count($val);
                    } elseif (\is_int($val)) {
                        $out[$key] = $val;
                        if (\str_ends_with($key, '_bytes')) {
                            $tracked += $val;
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }
        try {
            $om = \Weline\Framework\Manager\ObjectManager::getRuntimeMemoryDiagnostics(0, false);
            $out['om_main_instances'] = (int)($om['main_instances']['count'] ?? 0);
            $out['om_origin_instances'] = (int)($om['origin_instances']['count'] ?? 0);
            $out['om_reflections'] = (int)($om['metadata_entries']['reflections'] ?? 0);
            $out['om_method_params'] = (int)($om['metadata_entries']['method_params'] ?? 0);
            $out['om_fiber_instances'] = (int)($om['fiber_instances']['instance_count'] ?? 0);
        } catch (\Throwable) {
        }
        foreach ([
            [\Weline\Theme\Service\SlotRendererService::class, 'widgetOutputCache', 'theme_widget_output'],
            [\Weline\Theme\Service\SlotRendererService::class, 'publishedLayoutDataCache', 'theme_layout_data'],
            [\Weline\Theme\Block\Partials::class, 'partialOutputCache', 'theme_partial_output'],
            [\Weline\Theme\Service\RuntimeTemplateMaterializer::class, 'workerCompiledCache', 'theme_compiled'],
            [\Weline\Theme\Helper\ThemeData::class, 'runtimeCache', 'theme_data_runtime'],
            [\Weline\Framework\Phrase\Parser::class, 'workerLayeredWordsCache', 'phrase_layered'],
            [\Weline\Framework\Phrase\Parser::class, 'workerMaterializedWordsCache', 'phrase_materialized'],
            [\Weline\Framework\Phrase\Parser::class, 'workerTranslatedWordsCache', 'phrase_translated'],
            [\Weline\Framework\Phrase\Parser::class, 'workerModuleWordsCache', 'phrase_module'],
        ] as [$class, $prop, $key]) {
            try {
                if (!\class_exists($class)) {
                    continue;
                }
                $rp = new \ReflectionProperty($class, $prop);
                $rp->setAccessible(true);
                if (!$rp->isStatic()) {
                    continue;
                }
                $val = $rp->getValue();
                $out[$key] = \is_array($val) ? \count($val) : 0;
                if (\is_array($val)) {
                    $bytes = self::estimateBytes($val);
                    $out[$key . '_bytes'] = $bytes;
                    $tracked += $bytes;
                }
            } catch (\Throwable) {
            }
        }
        $out['tracked_bytes'] = $tracked;
        // Always scan: accumulation is often many mid-size statics, not only >256KB.
        $heavy = self::scanHeavyStaticArrays(20, 64 * 1024);
        $out['static_heavy'] = $heavy;
        $out['static_heavy_bytes'] = 0;
        foreach ($heavy as $h) {
            $out['static_heavy_bytes'] += (int)($h['bytes'] ?? 0);
        }
        return $out;
    }

    /**
     * @return list<array{class:string,prop:string,entries:int,bytes:int}>
     */
    private static function scanHeavyStaticArrays(int $limit = 8, int $minBytes = 262144): array
    {
        $hits = [];
        foreach (\get_declared_classes() as $class) {
            if (!\str_starts_with($class, 'Weline\\')) {
                continue;
            }
            try {
                $rc = new \ReflectionClass($class);
            } catch (\Throwable) {
                continue;
            }
            foreach ($rc->getProperties(\ReflectionProperty::IS_STATIC) as $prop) {
                try {
                    $prop->setAccessible(true);
                    $val = $prop->getValue();
                } catch (\Throwable) {
                    continue;
                }
                if (!\is_array($val) || $val === []) {
                    continue;
                }
                $nodes = 0;
                $bytes = self::estimateBytes($val, 0, $nodes);
                if ($bytes < $minBytes) {
                    continue;
                }
                $hits[] = [
                    'class' => $class,
                    'prop' => $prop->getName(),
                    'entries' => \count($val),
                    'bytes' => $bytes,
                ];
            }
        }
        \usort($hits, static fn(array $a, array $b): int => $b['bytes'] <=> $a['bytes']);
        return \array_slice($hits, 0, $limit);
    }

    /** Best-effort deep size estimate (caps nodes to keep snapshot cheap). */
    private static function estimateBytes(mixed $value, int $depth = 0, int &$nodes = 0): int
    {
        if ($nodes > 20000 || $depth > 12) {
            return 0;
        }
        $nodes++;
        if ($value === null || \is_bool($value)) {
            return 1;
        }
        if (\is_int($value) || \is_float($value)) {
            return 8;
        }
        if (\is_string($value)) {
            return \strlen($value);
        }
        if (\is_array($value)) {
            $sum = 0;
            foreach ($value as $k => $v) {
                if (\is_string($k) || \is_int($k)) {
                    $sum += \is_string($k) ? \strlen($k) : 8;
                }
                $sum += self::estimateBytes($v, $depth + 1, $nodes);
                if ($nodes > 20000) {
                    break;
                }
            }
            return $sum;
        }
        if (\is_object($value)) {
            return 64;
        }

        return 0;
    }

    private static function flagPath(): string
    {
        return (\defined('BP') ? BP : (\dirname(__DIR__, 5) . \DIRECTORY_SEPARATOR)) . 'var' . \DIRECTORY_SEPARATOR . self::FLAG_FILE;
    }

    private static function logPath(): string
    {
        return (\defined('BP') ? BP : (\dirname(__DIR__, 5) . \DIRECTORY_SEPARATOR))
            . 'var' . \DIRECTORY_SEPARATOR . 'log' . \DIRECTORY_SEPARATOR . self::LOG_FILE;
    }
}
