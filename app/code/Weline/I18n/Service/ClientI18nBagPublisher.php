<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\App\Env;
use Weline\Framework\View\PublicStaticUrl;

/**
 * Builds per-locale install scripts under Weline_I18n view/statics/i18n-client/.
 */
final class ClientI18nBagPublisher
{
    public const REL_DIR = 'i18n-client';

    /**
     * @return list<string>
     */
    public static function areas(): array
    {
        return [
            DictionaryAreaPolicy::AREA_FRONTEND,
            DictionaryAreaPolicy::AREA_BACKEND,
        ];
    }

    public static function moduleStaticsRoot(): string
    {
        $module = Env::getInstance()->getModuleInfo('Weline_I18n');
        $base = is_array($module) ? (string)($module['base_path'] ?? '') : '';
        if ($base === '') {
            $base = BP . 'app/code/Weline/I18n';
        }

        return rtrim(str_replace('\\', '/', $base), '/') . '/view/statics/' . self::REL_DIR;
    }

    public static function bagRelativePath(string $area, string $locale): string
    {
        $area = DictionaryAreaPolicy::normalize($area);
        $locale = trim(str_replace('-', '_', $locale));

        return self::REL_DIR . '/' . $area . '/' . $locale . '.js';
    }

    public static function bagAbsolutePath(string $area, string $locale): string
    {
        $area = DictionaryAreaPolicy::normalize($area);
        $locale = trim(str_replace('-', '_', $locale));

        return self::moduleStaticsRoot() . '/' . $area . '/' . $locale . '.js';
    }

    public static function manifestAbsolutePath(string $area): string
    {
        $area = DictionaryAreaPolicy::normalize($area);

        return self::moduleStaticsRoot() . '/' . $area . '/manifest.json';
    }

    /**
     * Publish frontend + backend install scripts for one locale.
     *
     * @return array{frontend: bool, backend: bool}
     */
    public function publishForLocale(string $localeCode): array
    {
        $localeCode = trim(str_replace('-', '_', $localeCode));
        $out = ['frontend' => false, 'backend' => false];
        if ($localeCode === '') {
            return $out;
        }
        foreach (self::areas() as $area) {
            $out[$area] = $this->publishAreaLocale($area, $localeCode);
        }

        return $out;
    }

    public function publishAreaLocale(string $area, string $localeCode): bool
    {
        $area = DictionaryAreaPolicy::normalize($area);
        if ($area === DictionaryAreaPolicy::AREA_SHARED) {
            $area = DictionaryAreaPolicy::AREA_FRONTEND;
        }
        $localeCode = trim(str_replace('-', '_', $localeCode));
        if ($localeCode === '') {
            return false;
        }

        $words = ClientWordSetStore::load($area);
        $bag = LocaleAreaDictionaryLoader::loadMerged($localeCode, $area, true);
        $dict = [];
        foreach ($words as $source) {
            $source = trim((string)$source);
            if ($source === '') {
                continue;
            }
            $translated = trim((string)($bag[$source] ?? ''));
            $dict[$source] = $translated !== '' ? $translated : $source;
        }
        ksort($dict, SORT_STRING);

        $js = $this->renderInstallScript($dict);
        $path = self::bagAbsolutePath($area, $localeCode);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            w_log_error('ClientI18nBagPublisher: cannot create ' . $dir, [], 'i18n');

            return false;
        }
        $tmp = tempnam($dir, '.i18n-client-');
        if ($tmp === false) {
            return false;
        }
        try {
            if (file_put_contents($tmp, $js, LOCK_EX) !== strlen($js) || !rename($tmp, $path)) {
                return false;
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        $sha = hash('sha256', $js);
        $this->upsertManifest($area, $localeCode, $sha);

        return true;
    }

    /**
     * @return array{url: string, sha: string, locale: string, area: string}|null
     */
    public static function resolveFooterScript(string $area, string $locale): ?array
    {
        $area = DictionaryAreaPolicy::normalize($area);
        if ($area === DictionaryAreaPolicy::AREA_SHARED) {
            $area = DictionaryAreaPolicy::AREA_FRONTEND;
        }
        $locale = trim(str_replace('-', '_', $locale));
        if ($locale === '') {
            return null;
        }
        $path = self::bagAbsolutePath($area, $locale);
        if (!is_file($path)) {
            return null;
        }
        $sha = self::readManifestSha($area, $locale);
        if ($sha === '') {
            $sha = hash_file('sha256', $path) ?: (string)@filemtime($path);
        }
        $moduleSource = 'Weline_I18n::' . self::bagRelativePath($area, $locale);
        $url = PublicStaticUrl::fromModuleSource($moduleSource);
        if ($url === '') {
            return null;
        }
        $sep = str_contains($url, '?') ? '&' : '?';

        return [
            'url' => $url . $sep . 'v=' . rawurlencode($sha),
            'sha' => $sha,
            'locale' => $locale,
            'area' => $area,
        ];
    }

    /**
     * @param array<string, string> $dict
     */
    private function renderInstallScript(array $dict): string
    {
        $json = json_encode($dict, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            $json = '{}';
        }

        return '(function(w){var D=' . $json . ';function install(){var i=w.Weline&&w.Weline.i18n;'
            . 'if(i&&typeof i.setDictionary==="function"){i.setDictionary(D);'
            . 'if(typeof i.notifyBagReady==="function"){i.notifyBagReady();}else if(i.readyResolve){i.readyResolve();}}'
            . 'else{(w.__WelineI18nBagQueue=w.__WelineI18nBagQueue||[]).push(D);}'
            . 'if(w.site){w.site.i18n=D;}w.__WelineI18nDictionary=D;}'
            . 'install();if(!(w.Weline&&w.Weline.i18n&&w.Weline.i18n.setDictionary)){'
            . 'document.addEventListener("DOMContentLoaded",install,{once:true});'
            . 'var n=0,t=setInterval(function(){install();'
            . 'if((w.Weline&&w.Weline.i18n&&w.Weline.i18n.setDictionary)||++n>40)clearInterval(t);},50);}'
            . '})(window);';
    }

    private function upsertManifest(string $area, string $locale, string $sha): void
    {
        $path = self::manifestAbsolutePath($area);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $data = [];
        if (is_file($path)) {
            $raw = file_get_contents($path);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }
        $data[$locale] = $sha;
        ksort($data, SORT_STRING);
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return;
        }
        file_put_contents($path, $encoded, LOCK_EX);
    }

    private static function readManifestSha(string $area, string $locale): string
    {
        $path = self::manifestAbsolutePath($area);
        if (!is_file($path)) {
            return '';
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return '';
        }

        return trim((string)($decoded[$locale] ?? ''));
    }
}
