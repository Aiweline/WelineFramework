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
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }
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
}
