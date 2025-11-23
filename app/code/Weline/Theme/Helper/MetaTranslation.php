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
use Weline\I18n\Model\Locale\Dictionary;

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
        
        // 从I18n Dictionary获取翻译
        /** @var Dictionary $localeDict */
        $localeDict = ObjectManager::getInstance(Dictionary::class);
        $md5 = Dictionary::generateMd5($translationKey, $locale);
        $localeDict->load($md5, Dictionary::fields_MD5);
        
        if ($localeDict->getId()) {
            $translation = $localeDict->getData(Dictionary::fields_TRANSLATE);
            if (!empty($translation)) {
                return $translation;
            }
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
        
        // 从I18n Dictionary获取翻译
        /** @var Dictionary $localeDict */
        $localeDict = ObjectManager::getInstance(Dictionary::class);
        $md5 = Dictionary::generateMd5($translationKey, $locale);
        $localeDict->load($md5, Dictionary::fields_MD5);
        
        $translation = '';
        if ($localeDict->getId()) {
            $translation = $localeDict->getData(Dictionary::fields_TRANSLATE);
        }
        
        // 如果没有找到带scope的翻译，尝试不带scope的
        if (empty($translation) && $scope !== 'default') {
            $md5Default = Dictionary::generateMd5('@meta::' . $metaKey, $locale);
            $localeDict->load($md5Default, Dictionary::fields_MD5);
            if ($localeDict->getId()) {
                $translation = $localeDict->getData(Dictionary::fields_TRANSLATE);
            }
        }
        
        // 如果没有翻译，返回默认值
        return $translation ?: ($defaultValue ?? '');
    }
}

