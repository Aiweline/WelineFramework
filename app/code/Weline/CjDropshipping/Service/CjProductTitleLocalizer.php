<?php

declare(strict_types=1);

namespace Weline\CjDropshipping\Service;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * Localize CJ product titles for admin locale when upstream has no Chinese name.
 *
 * CJ US-warehouse rows often put English into productName; TranslationService may be
 * unavailable — fail soft and keep English.
 */
final class CjProductTitleLocalizer
{
    public const CACHE_POOL = 'cj_product_title_i18n';
    public const TTL_SECONDS = 604800; // 7d

    public static function containsCjk(string $text): bool
    {
        return preg_match('/\p{Han}/u', $text) === 1;
    }

    public static function localize(string $title, string $locale): string
    {
        $map = self::localizeMany([$title], $locale);

        return $map[trim($title)] ?? trim($title);
    }

    /**
     * @param list<string> $titles
     * @return array<string, string> map original => localized
     */
    public static function localizeMany(array $titles, string $locale): array
    {
        $out = [];
        $need = [];
        foreach ($titles as $title) {
            $title = trim((string)$title);
            if ($title === '') {
                continue;
            }
            if (!CjCategoryLocalizer::localePrefersZh($locale) || self::containsCjk($title)) {
                $out[$title] = $title;
                continue;
            }
            $cached = self::cacheGet($title);
            if ($cached !== null) {
                $out[$title] = $cached;
                continue;
            }
            $need[$title] = true;
        }
        if ($need === []) {
            return $out;
        }

        // Prefer TranslationService once (channel may batch poorly); then parallel MT.
        $pending = array_keys($need);
        $viaSvc = [];
        foreach ($pending as $title) {
            $t = self::tryTranslationService($title);
            if ($t !== '' && self::containsCjk($t)) {
                self::cacheSet($title, $t);
                $out[$title] = $t;
                $viaSvc[$title] = true;
            }
        }
        $rest = [];
        foreach ($pending as $title) {
            if (!isset($viaSvc[$title])) {
                $rest[] = $title;
            }
        }
        if ($rest !== []) {
            foreach (self::mymemoryParallel($rest) as $en => $zh) {
                $final = ($zh !== '' && self::containsCjk($zh)) ? $zh : $en;
                self::cacheSet($en, $final);
                $out[$en] = $final;
            }
        }

        return $out;
    }

    private static function tryTranslationService(string $title): string
    {
        try {
            if (!class_exists(\Weline\TranslationService\Service\TranslationService::class)) {
                return '';
            }
            /** @var \Weline\TranslationService\Service\TranslationService $svc */
            $svc = ObjectManager::getInstance(\Weline\TranslationService\Service\TranslationService::class);
            $t = trim((string)$svc->translate($title, 'zh_Hans_CN', 'en'));

            return $t;
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param list<string> $titles
     * @return array<string, string>
     */
    private static function mymemoryParallel(array $titles): array
    {
        $out = [];
        $chunkSize = 6;
        foreach (array_chunk($titles, $chunkSize) as $chunk) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($chunk as $text) {
                $url = 'https://api.mymemory.translated.net/get?q=' . rawurlencode(mb_substr($text, 0, 450)) . '&langpair=en|zh-CN';
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_CONNECTTIMEOUT => 3,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$text] = $ch;
            }
            $running = null;
            do {
                curl_multi_exec($mh, $running);
                if ($running > 0) {
                    curl_multi_select($mh, 0.5);
                }
            } while ($running > 0);
            foreach ($handles as $text => $ch) {
                $raw = (string)curl_multi_getcontent($ch);
                $j = json_decode($raw, true);
                $t = trim((string)($j['responseData']['translatedText'] ?? ''));
                if ($t !== '' && stripos($t, 'MYMEMORY WARNING') === false) {
                    $out[$text] = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                } else {
                    $out[$text] = '';
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }

        return $out;
    }

    private static function cacheGet(string $title): ?string
    {
        try {
            /** @var CacheManager $cm */
            $cm = ObjectManager::getInstance(CacheManager::class);
            $v = $cm->pool(self::CACHE_POOL)->get(self::key($title));
            if (is_string($v) && $v !== '') {
                return $v;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private static function cacheSet(string $title, string $value): void
    {
        try {
            /** @var CacheManager $cm */
            $cm = ObjectManager::getInstance(CacheManager::class);
            $cm->pool(self::CACHE_POOL)->set(self::key($title), $value, self::TTL_SECONDS);
        } catch (\Throwable) {
        }
    }

    private static function key(string $title): string
    {
        return 't:' . md5(mb_strtolower($title, 'UTF-8'));
    }
}
