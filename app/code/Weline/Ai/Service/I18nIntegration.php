<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Service;

use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\I18n\Api\Localization\Data\LocaleNameRecord;
use Weline\I18n\Api\Localization\LocaleNameCatalogInterface;
use Weline\I18n\Api\Localization\LocaleRepositoryInterface;

/**
 * I18n模块集成服务
 * 
 * 功能：
 * - 与I18n模块集成
 * - 获取支持的语言列表
 * - 验证语言有效性
 * - 提供语言信息查询
 */
class I18nIntegration
{
    public function __construct(private readonly RuntimeProviderResolver $runtimeProviders)
    {
    }

    /**
     * 获取所有支持的语言
     * 
     * @return array
     */
    public function getSupportedLocales(): array
    {
        $supportedLocales = [];
        foreach ($this->localeNameCatalog()?->all() ?? [] as $locale) {
            $supportedLocales[] = [
                'locale_code' => $locale->localeCode,
                'display_locale_code' => $locale->displayLocaleCode,
                'display_name' => $locale->displayName,
            ];
        }
        
        return $supportedLocales;
    }

    /**
     * 获取默认语言
     * 
     * @return string
     */
    public function getDefaultLocale(): string
    {
        // 从I18n模块获取默认语言，如果没有则使用zh-CN
        try {
            return $this->localeRepository()->resolveCode('zh-CN');
        } catch (\Throwable) {
            return 'zh-CN';
        }
    }

    private function localeRepository(): LocaleRepositoryInterface
    {
        $repository = $this->runtimeProviders->resolve(LocaleRepositoryInterface::class);
        if (!$repository instanceof LocaleRepositoryInterface) {
            throw new \RuntimeException('Weline_I18n locale repository provider is unavailable.');
        }

        return $repository;
    }

    /**
     * 验证语言是否支持
     * 
     * @param string $localeCode
     * @return bool
     */
    public function isLocaleSupported(string $localeCode): bool
    {
        return $this->localeNameCatalog()?->containsLocaleCode($localeCode) ?? false;
    }

    /**
     * 获取语言信息
     * 
     * @param string $localeCode
     * @return array|null
     */
    public function getLocaleInfo(string $localeCode): ?array
    {
        $locale = $this->localeNameCatalog()?->firstByLocaleCode($localeCode);
        if (!$locale instanceof LocaleNameRecord) {
            return null;
        }
        
        return [
            'locale_code' => $locale->localeCode,
            'display_locale_code' => $locale->displayLocaleCode,
            'display_name' => $locale->displayName,
        ];
    }

    private function localeNameCatalog(): ?LocaleNameCatalogInterface
    {
        $catalog = $this->runtimeProviders->resolve(LocaleNameCatalogInterface::class);

        return $catalog instanceof LocaleNameCatalogInterface ? $catalog : null;
    }

    /**
     * 获取语言代码列表
     * 
     * @return array
     */
    public function getLocaleCodeList(): array
    {
        $locales = $this->getSupportedLocales();
        return array_column($locales, 'locale_code');
    }

    /**
     * 获取语言显示名称映射
     * 
     * @return array
     */
    public function getLocaleDisplayNames(): array
    {
        $locales = $this->getSupportedLocales();
        $displayNames = [];
        
        foreach ($locales as $locale) {
            $displayNames[$locale['locale_code']] = $locale['display_name'];
        }
        
        return $displayNames;
    }

    /**
     * 标准化语言代码
     * 
     * @param string $localeCode
     * @return string
     */
    public function normalizeLocaleCode(string $localeCode): string
    {
        $localeCode = \trim($localeCode);
        if ($localeCode === '') {
            return $localeCode;
        }

        // Catalog identity wins: Weline_I18n stores underscore codes such as
        // en_US / zh_Hans_CN / bn_IN. Do not rewrite a supported code into a
        // hyphen form the catalog rejects (that silently falls back to Chinese).
        if ($this->isLocaleSupported($localeCode)) {
            return $localeCode;
        }

        $underscore = \str_replace('-', '_', $localeCode);
        if ($underscore !== $localeCode && $this->isLocaleSupported($underscore)) {
            return $underscore;
        }

        $lookup = \strtolower($underscore);
        // Common aliases → catalog underscore codes.
        $mapping = [
            'zh' => 'zh_Hans_CN',
            'zh_cn' => 'zh_Hans_CN',
            'zh_hans_cn' => 'zh_Hans_CN',
            'zh_hans' => 'zh_Hans_CN',
            'en' => 'en_US',
            'en_us' => 'en_US',
            'ja' => 'ja_JP',
            'ja_jp' => 'ja_JP',
            'ko' => 'ko_KR',
            'ko_kr' => 'ko_KR',
            'bn' => 'bn_IN',
            'bn_in' => 'bn_IN',
            'bn_bd' => 'bn_BD',
        ];
        if (isset($mapping[$lookup]) && $this->isLocaleSupported($mapping[$lookup])) {
            return $mapping[$lookup];
        }
        if (isset($mapping[$lookup])) {
            return $mapping[$lookup];
        }

        // Case-insensitive match against the live catalog (bn_in → bn_IN).
        foreach ($this->getLocaleCodeList() as $code) {
            if (\strcasecmp((string)$code, $underscore) === 0) {
                return (string)$code;
            }
        }

        return $underscore;
    }

    /**
     * 验证并获取有效的语言代码
     * 
     * @param string $localeCode
     * @param string $fallback
     * @return string
     */
    public function validateAndGetLocale(string $localeCode, string $fallback = 'zh_Hans_CN'): string
    {
        $normalizedLocale = $this->normalizeLocaleCode($localeCode);
        if ($this->isLocaleSupported($normalizedLocale)) {
            return $normalizedLocale;
        }

        // Prefer keeping the caller's language family over a silent Chinese
        // fallback — otherwise en_US/bn_IN machine-translate into 中文.
        $fallbackNormalized = $this->normalizeLocaleCode($fallback);
        if ($this->isLocaleSupported($fallbackNormalized)) {
            return $fallbackNormalized;
        }

        return $this->getDefaultLocale();
    }
}
