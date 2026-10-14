<?php

declare(strict_types=1);

namespace Weline\I18n\Service\InlineEdit;

use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\I18n\Service\AiTranslationPublisher;
use Weline\I18n\Service\RuntimeCacheBroadcaster;
use Weline\Theme\Service\ThemeStorefrontSlugFpcPublisher;

/**
 * Storefront「更新当前页」：词典导出 → 进程缓存失效 → 路径 FPC 清理。
 * 点译保存已 publishLocale，但页缓存 / worker 词袋可能仍旧；本流显式收口。
 */
final class InlineEditUpdatePageService
{
    /**
     * @return array{
     *   ok:bool,
     *   message:string,
     *   locale:string,
     *   slug:string,
     *   published:bool,
     *   purge_ok:bool,
     *   skipped:?string
     * }
     */
    public function run(string $path, string $localeCode = ''): array
    {
        $localeCode = $this->normalizeLocale($localeCode);
        $published = false;
        try {
            /** @var AiTranslationPublisher $publisher */
            $publisher = ObjectManager::getInstance(AiTranslationPublisher::class);
            $published = (bool)$publisher->publishLocale($localeCode);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => InlinePhraseMarker::plain('词典导出失败') . '：' . $e->getMessage(),
                'locale' => $localeCode,
                'slug' => '',
                'published' => false,
                'purge_ok' => false,
                'skipped' => 'dictionary_export_failed',
            ];
        }

        try {
            ObjectManager::getInstance(RuntimeCacheBroadcaster::class)->broadcast();
        } catch (\Throwable) {
            // Revision bump from publishLocale still covers next-request authority.
        }

        /** @var ThemeStorefrontSlugFpcPublisher $fpc */
        $fpc = ObjectManager::getInstance(ThemeStorefrontSlugFpcPublisher::class);
        $purge = $fpc->purgeByStorefrontPath($path);
        // Theme slug emit is best-effort (needs request Theme context).
        // Coordinator purge is the authority for this page's disk/Shared FPC.
        $this->purgeCoordinatorUrls($path);
        $themeOk = (bool)($purge['ok'] ?? false);
        $skipped = (string)($purge['skipped'] ?? '');
        // Soft skips: CLI / missing ThemeApplicationContext must not fail
        // after dictionary export + path FPC purge already ran.
        $softThemeSkip = \in_array($skipped, [
            'no_theme_context',
            'no_theme',
            'theme_context_invalid',
        ], true);
        if (!$themeOk && !$softThemeSkip) {
            return [
                'ok' => false,
                'message' => InlinePhraseMarker::plain('词典已导出，但无法识别当前页路径，未清页缓存'),
                'locale' => $localeCode,
                'slug' => (string)($purge['slug'] ?? ''),
                'published' => $published,
                'purge_ok' => false,
                'skipped' => $skipped !== '' ? $skipped : 'path_not_recognized',
            ];
        }

        return [
            'ok' => true,
            'message' => InlinePhraseMarker::plain('已导出词典并更新当前页缓存'),
            'locale' => $localeCode,
            'slug' => (string)($purge['slug'] ?? ''),
            'published' => $published,
            'purge_ok' => true,
            'skipped' => $softThemeSkip ? $skipped : null,
        ];
    }

    public function stream(SseWriter $sse, string $path, string $localeCode = ''): void
    {
        $localeCode = $this->normalizeLocale($localeCode);
        $path = \trim($path) !== '' ? \trim($path) : '/';
        $sse->start();
        $sse->sendEvent('start', [
            'message' => InlinePhraseMarker::plain('开始更新当前页'),
            'path' => $path,
            'locale' => $localeCode,
        ]);

        $sse->sendEvent('progress', [
            'step' => 'dictionary_export',
            'message' => InlinePhraseMarker::plain('正在导出词典'),
            'locale' => $localeCode,
            'percent' => 25,
        ]);

        $published = false;
        try {
            /** @var AiTranslationPublisher $publisher */
            $publisher = ObjectManager::getInstance(AiTranslationPublisher::class);
            $published = (bool)$publisher->publishLocale($localeCode);
        } catch (\Throwable $e) {
            $sse->sendEvent('failed', [
                'success' => false,
                'step' => 'dictionary_export',
                'message' => InlinePhraseMarker::plain('词典导出失败') . '：' . $e->getMessage(),
            ]);
            $sse->complete(['success' => false]);

            return;
        }

        $sse->sendEvent('progress', [
            'step' => 'cache_invalidate',
            'message' => InlinePhraseMarker::plain('正在失效运行时词典缓存'),
            'percent' => 55,
        ]);
        try {
            ObjectManager::getInstance(RuntimeCacheBroadcaster::class)->broadcast();
        } catch (\Throwable) {
            // non-fatal
        }

        $sse->sendEvent('progress', [
            'step' => 'fpc_purge',
            'message' => InlinePhraseMarker::plain('正在清理当前页缓存'),
            'path' => $path,
            'percent' => 80,
        ]);

        /** @var ThemeStorefrontSlugFpcPublisher $fpc */
        $fpc = ObjectManager::getInstance(ThemeStorefrontSlugFpcPublisher::class);
        $purge = $fpc->purgeByStorefrontPath($path);
        $this->purgeCoordinatorUrls($path);
        $themeOk = (bool)($purge['ok'] ?? false);
        $skipped = (string)($purge['skipped'] ?? '');
        $softThemeSkip = \in_array($skipped, [
            'no_theme_context',
            'no_theme',
            'theme_context_invalid',
        ], true);
        if (!$themeOk && !$softThemeSkip) {
            $sse->sendEvent('failed', [
                'success' => false,
                'step' => 'fpc_purge',
                'message' => InlinePhraseMarker::plain('词典已导出，但无法识别当前页路径，未清页缓存'),
                'skipped' => $skipped !== '' ? $skipped : 'path_not_recognized',
                'locale' => $localeCode,
                'published' => $published,
            ]);
            $sse->complete(['success' => false]);

            return;
        }

        $sse->sendEvent('complete', [
            'success' => true,
            'message' => InlinePhraseMarker::plain('已导出词典并更新当前页缓存'),
            'locale' => $localeCode,
            'slug' => $purge['slug'] ?? '',
            'published' => $published,
            'purge_ok' => true,
            'skipped' => $softThemeSkip ? $skipped : null,
            'percent' => 100,
        ]);
        $sse->complete(['success' => true]);
    }

    private function normalizeLocale(string $localeCode): string
    {
        $localeCode = \trim(\str_replace('-', '_', $localeCode));
        if ($localeCode !== '') {
            return $localeCode;
        }
        try {
            $localeCode = \trim((string)State::getLangLocal());
        } catch (\Throwable) {
            $localeCode = '';
        }

        return $localeCode !== '' ? $localeCode : 'zh_Hans_CN';
    }

    /**
     * Drop Shared/process/disk FPC for the path (homepage `/` included).
     */
    private function purgeCoordinatorUrls(string $path): void
    {
        $path = \trim($path);
        if ($path === '') {
            $path = '/';
        }
        $parsed = \parse_url($path);
        if (\is_array($parsed) && isset($parsed['path'])) {
            $path = (string)$parsed['path'];
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . \ltrim($path, '/');
        }
        $urls = [$path];
        foreach ($this->storefrontOrigins() as $origin) {
            $urls[] = $origin . ($path === '/' ? '/' : $path);
        }
        $urls = \array_values(\array_unique($urls));
        try {
            FullPageCacheCoordinator::purgeUrls($urls);
            FullPageCacheCoordinator::clearProcessCache();
        } catch (\Throwable) {
            // Theme emit already attempted; fail-open on coordinator.
        }
    }

    /**
     * @return list<string> scheme://host origins (http+https when host known)
     */
    private function storefrontOrigins(): array
    {
        $hosts = [];
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $host = \trim((string)$request->getServer('HTTP_HOST'));
            if ($host !== '') {
                // Strip :port for origin rebuild; keep as-is if already bare.
                $hosts[] = \preg_replace('/:\d+$/', '', $host) ?: $host;
            }
        } catch (\Throwable) {
        }
        try {
            $base = \trim((string)(Env::get('domain') ?? ''));
            if ($base !== '') {
                if (\preg_match('#^https?://([^/]+)#i', $base, $m) === 1) {
                    $hosts[] = (string)$m[1];
                } else {
                    $hosts[] = \ltrim($base, '/');
                }
            }
        } catch (\Throwable) {
        }
        $hosts = \array_values(\array_unique(\array_filter(\array_map(
            static fn(string $h): string => \strtolower(\trim($h)),
            $hosts
        ))));
        $origins = [];
        foreach ($hosts as $host) {
            $origins[] = 'https://' . $host;
            $origins[] = 'http://' . $host;
        }

        return $origins;
    }
}
