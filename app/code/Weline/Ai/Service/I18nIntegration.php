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
        // 将语言代码标准化为I18n模块支持的格式
        $localeCode = strtolower($localeCode);
        
        // 常见的语言代码映射
        $mapping = [
            'zh' => 'zh-CN',
            'zh_cn' => 'zh-CN',
            'zh-cn' => 'zh-CN',
            'en' => 'en-US',
            'en_us' => 'en-US',
            'en-us' => 'en-US',
            'ja' => 'ja-JP',
            'ja_jp' => 'ja-JP',
            'ja-jp' => 'ja-JP',
            'ko' => 'ko-KR',
            'ko_kr' => 'ko-KR',
            'ko-kr' => 'ko-KR'
        ];
        
        return $mapping[$localeCode] ?? $localeCode;
    }

    /**
     * 验证并获取有效的语言代码
     * 
     * @param string $localeCode
     * @param string $fallback
     * @return string
     */
    public function validateAndGetLocale(string $localeCode, string $fallback = 'zh-CN'): string
    {
        // 标准化语言代码
        $normalizedLocale = $this->normalizeLocaleCode($localeCode);
        
        // 验证是否支持
        if ($this->isLocaleSupported($normalizedLocale)) {
            return $normalizedLocale;
        }
        
        // 尝试回退语言
        if ($this->isLocaleSupported($fallback)) {
            return $fallback;
        }
        
        // 最后回退到默认语言
        return $this->getDefaultLocale();
    }
}
