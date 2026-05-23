<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\App\Env;
use Weline\Framework\App\State;

/**
 * 主题部件文案：布局配置里存的是中文源串，渲染时按当前语言解析（含 Weline_Theme 语言包回退）。
 */
final class WidgetI18n
{
    /** @var array<string, array<string, string>> */
    private static array $moduleWordsCache = [];

    public static function label(string $configuredTitle, string $defaultSource = ''): string
    {
        $key = trim($configuredTitle !== '' ? $configuredTitle : $defaultSource);
        if ($key === '') {
            return '';
        }

        $lang = self::resolveStorefrontLocale();
        foreach (['Weline_Theme', 'WeShop_Product'] as $moduleName) {
            $words = self::loadModuleWords($moduleName, $lang);
            if (isset($words[$key]) && $words[$key] !== '' && $words[$key] !== $key) {
                return $words[$key];
            }
        }

        $translated = (string) \__($key);
        if ($translated !== $key) {
            return $translated;
        }

        return $key;
    }

    /**
     * @return array<string, string>
     */
    private static function loadModuleWords(string $moduleName, string $lang): array
    {
        $cacheKey = $lang . '|' . $moduleName;
        if (isset(self::$moduleWordsCache[$cacheKey])) {
            return self::$moduleWordsCache[$cacheKey];
        }

        $words = [];
        try {
            $moduleInfo = Env::getInstance()->getModuleInfo($moduleName);
            $csvFile = ($moduleInfo['base_path'] ?? '') . '/i18n/' . $lang . '.csv';
            if (!is_file($csvFile)) {
                return self::$moduleWordsCache[$cacheKey] = $words;
            }

            $handle = @fopen($csvFile, 'r');
            if ($handle === false) {
                return self::$moduleWordsCache[$cacheKey] = $words;
            }

            while (($data = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
                if (!isset($data[0], $data[1])) {
                    continue;
                }
                $source = trim((string) $data[0]);
                $target = trim((string) $data[1]);
                if ($source === '' || $target === '' || $target === $source) {
                    continue;
                }
                $words[$source] = $target;
            }

            fclose($handle);
        } catch (\Throwable) {
        }

        return self::$moduleWordsCache[$cacheKey] = $words;
    }

    private static function resolveStorefrontLocale(): string
    {
        $lang = trim(State::getLangLocal());
        $requestUri = (string) (\Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '') ?: ($_SERVER['REQUEST_URI'] ?? ''));
        if ($requestUri !== '' && preg_match('#/(en_US|zh_Hans_CN|zh_CN)(?:/|$)#', $requestUri, $matches)) {
            return (string) $matches[1];
        }

        return $lang !== '' ? $lang : 'zh_Hans_CN';
    }
}
