<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;

/**
 * Meta翻译辅助类
 * 
 * 用于获取meta值的翻译
 */
class MetaTranslation
{
    /**
     * 获取meta值的翻译
     * 
     * @param string $metaKey meta键，格式：theme.frontend.layouts.default.name
     * @param string|null $locale 语言代码，如果为null则使用当前语言
     * @param string|null $defaultValue 默认值
     * @return string 翻译后的值
     */
    public static function getTranslatedValue(string $metaKey, ?string $locale = null, ?string $defaultValue = null): string
    {
        // 构建完整的翻译键
        $translationKey = '@meta::' . $metaKey;
        
        // 获取当前语言
        if ($locale === null) {
            $locale = \Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN';
        }
        
        $translation = self::loadTranslation($translationKey, $locale);
        if (!empty($translation)) {
            return $translation;
        }
        
        // 如果没有翻译，返回默认值
        return $defaultValue ?? '';
    }
    
    /**
     * 获取meta值的翻译（支持scope）
     * 
     * @param string $metaKey meta键
     * @param string $scope scope值，如 'default'
     * @param string|null $locale 语言代码
     * @param string|null $defaultValue 默认值
     * @return string 翻译后的值
     */
    public static function getTranslatedValueWithScope(string $metaKey, string $scope = 'default', ?string $locale = null, ?string $defaultValue = null): string
    {
        // 构建带scope的翻译键
        $translationKey = '@meta::' . $metaKey;
        if ($scope !== 'default') {
            $translationKey .= '|scope:' . $scope;
        }
        
        // 获取当前语言
        if ($locale === null) {
            $locale = \Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN';
        }
        
        $translation = self::loadTranslation($translationKey, $locale);
        
        // 如果没有找到带scope的翻译，尝试不带scope的
        if (empty($translation) && $scope !== 'default') {
            $translation = self::loadTranslation('@meta::' . $metaKey, $locale);
        }
        
        // 如果没有翻译，返回默认值
        return $translation ?: ($defaultValue ?? '');
    }

    private static function loadTranslation(string $translationKey, string $locale): string
    {
        return self::dictionaryRepository()->getEntry($translationKey, $locale)?->translation ?? '';
    }

    private static function dictionaryRepository(): DictionaryRepositoryInterface
    {
        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(DictionaryRepositoryInterface::class);
        if (!$provider instanceof DictionaryRepositoryInterface) {
            throw new \RuntimeException('Weline_I18n dictionary repository provider is unavailable.');
        }
        return $provider;
    }

    /**
     * 设置meta值的翻译（支持scope）
     * 
     * @param string $metaKey meta键
     * @param string $value 翻译值
     * @param string $scope scope值，如 'default'
     * @param string|null $locale 语言代码
     * @return bool 是否保存成功
     */
    public static function setTranslatedValueWithScope(string $metaKey, string $value, string $scope = 'default', ?string $locale = null): bool
    {
        // 构建带scope的翻译键
        $translationKey = '@meta::' . $metaKey;
        if ($scope !== 'default') {
            $translationKey .= '|scope:' . $scope;
        }

        // 获取当前语言
        if ($locale === null) {
            $locale = \Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN';
        }

        return self::dictionaryRepository()->upsert($translationKey, $locale, $value);
    }
}
