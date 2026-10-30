<?php
declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\RequestContext;

/**
 * 按请求区加载 shared ∪ area 两袋（互斥分区后的运行时合并）。
 */
final class LocaleAreaDictionaryLoader
{
    /**
     * Process-local bag fingerprint: path => "mtime:size:inode".
     *
     * WLS runs with opcache.validate_timestamps=0, so a compiled bag in SHM
     * never auto-revalidates; a regenerated bag would stay stale until restart.
     * The invalidation therefore has to be driven by an explicit change check
     * rather than unconditionally on every include.
     */
    private static array $bagFingerprints = [];

    /**
     * Upper bound for the fingerprint map (long-running Workers load one bag per
     * locale/area; 40 locales x 3 areas is well under this, the cap only guards
     * pathological locale churn).
     */
    private const BAG_FINGERPRINT_BUDGET = 512;

    /**
     * @return array<string, string>
     */
    public static function loadMerged(
        string $localeCode,
        ?string $requestArea = null,
        bool $allowLegacyUnion = true,
    ): array {
        $localeCode = trim(str_replace('-', '_', $localeCode));
        if ($localeCode === '') {
            return [];
        }
        $area = DictionaryAreaPolicy::normalize(
            $requestArea ?? self::detectRequestArea()
        );
        if ($area === DictionaryAreaPolicy::AREA_SHARED) {
            $area = DictionaryAreaPolicy::AREA_FRONTEND;
        }

        $dir = Env::path_TRANSLATE_FILES_PATH;
        $sharedPath = $dir . DictionaryAreaPolicy::localeBagBasename($localeCode, DictionaryAreaPolicy::AREA_SHARED);
        $areaPath = $dir . DictionaryAreaPolicy::localeBagBasename($localeCode, $area);
        $merged = self::includeBag($sharedPath) + self::includeBag($areaPath);

        // 兼容：区袋尚未生成时回退旧整袋（Persistent Worker 禁止整袋，传 allowLegacyUnion=false）
        if ($merged === [] && $allowLegacyUnion) {
            $legacy = $dir . $localeCode . '.php';
            $merged = self::includeBag($legacy);
        }

        return $merged;
    }

    /**
     * Worker/HTTP：优先 RequestContext 区（Persistent Worker 可靠）；其次 Request::isBackend()。
     */
    public static function detectRequestArea(): string
    {
        try {
            if (RequestContext::isInitialized() && RequestContext::isBackendArea()) {
                return DictionaryAreaPolicy::AREA_BACKEND;
            }
        } catch (\Throwable) {
        }
        try {
            $request = \Weline\Framework\Manager\ObjectManager::getInstance(Request::class);
            if (method_exists($request, 'isBackend') && $request->isBackend()) {
                return DictionaryAreaPolicy::AREA_BACKEND;
            }
        } catch (\Throwable) {
        }

        return DictionaryAreaPolicy::AREA_FRONTEND;
    }

    /**
     * @return array<string, string>
     */
    private static function includeBag(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        try {
            self::revalidateBagIfChanged($path);
            $raw = (array)include $path;
        } catch (\Throwable) {
            return [];
        }
        $words = [];
        foreach ($raw as $word => $translate) {
            if (is_string($word) && is_string($translate) && $word !== '' && $translate !== '') {
                $words[$word] = $translate;
            }
        }

        return $words;
    }

    /**
     * Invalidate the SHM entry for $path only when the bag file changed on disk.
     *
     * Before: every include invalidated unconditionally. Under
     * opcache.validate_timestamps=0 that forced a full recompile of a 3.5MB bag
     * (28k strings) on each load, and the old SHM block became unreclaimable
     * `wasted_memory`. Saturating the segment dropped `free_memory` to <1MB and
     * compiled opcodes started landing on the PHP heap in ~7.9MB bursts.
     * After: stat() the file (one syscall, cached by the OS) and revalidate only
     * when mtime/size/inode actually moved, which is when a regenerated bag must
     * not be served stale.
     */
    private static function revalidateBagIfChanged(string $path): void
    {
        if (!function_exists('opcache_invalidate')) {
            return;
        }
        // Long-running Workers never cross a request boundary, so PHP's stat
        // cache would keep serving the pre-regeneration mtime and the fingerprint
        // would look unchanged — that must not silence a real bag update.
        \clearstatcache(true, $path);
        $stat = @stat($path);
        if ($stat === false) {
            @opcache_invalidate($path, true);
            return;
        }
        $fingerprint = ($stat['mtime'] ?? 0) . ':' . ($stat['size'] ?? 0) . ':' . ($stat['ino'] ?? 0);
        $known = self::$bagFingerprints[$path] ?? null;
        if (count(self::$bagFingerprints) >= self::BAG_FINGERPRINT_BUDGET) {
            self::$bagFingerprints = [];
        }
        self::$bagFingerprints[$path] = $fingerprint;
        if ($known === $fingerprint) {
            return;
        }
        if ($known === null) {
            // FIRST sight of this bag in this process is normally a no-op: WLS Workers
            // are `proc_open` children with their OWN opcache segment (see
            // LongRunningPhpRuntime: "CLI OPcache is per-process"), so nothing this
            // Worker compiled before can be damaged here. Invalidating an UNcached
            // script is measurably free (wasted +0.00MB) — but that is only true while
            // the script really is uncached, so verify instead of assuming: a cleared
            // fingerprint budget would otherwise turn a genuine bag update into
            // "first sight" and serve stale opcode for the rest of the segment.
            if (function_exists('opcache_is_script_cached') && @\opcache_is_script_cached($path)) {
                @opcache_invalidate($path, true);
            }
            return;
        }
        @opcache_invalidate($path, true);
    }
}
