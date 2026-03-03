<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Meta\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Meta\Model\MetaLocal;
use Weline\Meta\Model\Meta;

/**
 * Meta翻译辅助类
 * 
 * 用于获取meta值的翻译（使用 m_w_meta_local 表存储）
 */
class MetaTranslation
{
    /**
     * 获取meta值的翻译
     * 
     * @param string $metaIdentify meta标识，格式：theme.frontend.layouts.default
     * @param string $configKey 配置键，如 name, description, param.title
     * @param string|null $locale 语言代码，如果为null则使用当前语言
     * @param string|null $defaultValue 默认值
     * @return string 翻译后的值
     */
    public static function getTranslatedValue(string $metaIdentify, string $configKey, ?string $locale = null, ?string $defaultValue = null): string
    {
        // 获取当前语言
        if ($locale === null) {
            $locale = \Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN';
        }
        
        // 获取 meta_id
        /** @var Meta $metaModel */
        $metaModel = ObjectManager::getInstance(Meta::class);
        $meta = $metaModel->reset()
            ->where(Meta::fields_META_IDENTIFY, $metaIdentify)
            ->find()
            ->fetch();
        
        if (!$meta->getId()) {
            return $defaultValue ?? '';
        }
        
        $metaId = (int)$meta->getId();
        
        // 从 w_meta_local 表获取翻译
        /** @var MetaLocal $localModel */
        $localModel = ObjectManager::getInstance(MetaLocal::class);
        $localModel->reset()
            ->where(MetaLocal::fields_META_ID, $metaId)
            ->where(MetaLocal::fields_LOCALE_CODE, $locale)
            ->where(MetaLocal::fields_CONFIG_KEY, $configKey)
            ->find()
            ->fetch();
        
        if ($localModel->getMetaId()) {
            $translation = $localModel->getConfigValue();
            if (!empty($translation)) {
                return $translation;
            }
        }
        
        // 如果没有翻译，返回默认值
        return $defaultValue ?? '';
    }
    
    /**
     * 获取meta参数的翻译
     * 
     * @param string $metaIdentify meta标识
     * @param string $paramName 参数名
     * @param string|null $locale 语言代码
     * @param string|null $defaultValue 默认值
     * @return string 翻译后的值
     */
    public static function getParamTranslation(string $metaIdentify, string $paramName, ?string $locale = null, ?string $defaultValue = null): string
    {
        return self::getTranslatedValue($metaIdentify, 'param.' . $paramName, $locale, $defaultValue);
    }
    
    /**
     * 获取meta名称的翻译
     * 
     * @param string $metaIdentify meta标识
     * @param string|null $locale 语言代码
     * @param string|null $defaultValue 默认值
     * @return string 翻译后的值
     */
    public static function getNameTranslation(string $metaIdentify, ?string $locale = null, ?string $defaultValue = null): string
    {
        return self::getTranslatedValue($metaIdentify, 'name', $locale, $defaultValue);
    }
    
    /**
     * 获取meta描述的翻译
     * 
     * @param string $metaIdentify meta标识
     * @param string|null $locale 语言代码
     * @param string|null $defaultValue 默认值
     * @return string 翻译后的值
     */
    public static function getDescriptionTranslation(string $metaIdentify, ?string $locale = null, ?string $defaultValue = null): string
    {
        return self::getTranslatedValue($metaIdentify, 'description', $locale, $defaultValue);
    }

    /**
     * 设置meta值的翻译
     * 
     * @param string $metaIdentify meta标识
     * @param string $configKey 配置键
     * @param string $locale 语言代码
     * @param string $value 翻译值
     * @return bool 是否保存成功
     */
    public static function setTranslatedValue(string $metaIdentify, string $configKey, string $locale, string $value): bool
    {
        try {
            // 获取 meta_id
            /** @var Meta $metaModel */
            $metaModel = ObjectManager::getInstance(Meta::class);
            $meta = $metaModel->reset()
                ->where(Meta::fields_META_IDENTIFY, $metaIdentify)
                ->find()
                ->fetch();
            
            if (!$meta->getId()) {
                return false;
            }
            
            $metaId = (int)$meta->getId();
            
            // 加载或创建翻译记录
            /** @var MetaLocal $localModel */
            $localModel = ObjectManager::getInstance(MetaLocal::class);
            $localModel->reset()
                ->where(MetaLocal::fields_META_ID, $metaId)
                ->where(MetaLocal::fields_LOCALE_CODE, $locale)
                ->where(MetaLocal::fields_CONFIG_KEY, $configKey)
                ->find()
                ->fetch();
            
            if (!$localModel->getMetaId()) {
                $localModel = ObjectManager::make(MetaLocal::class);
                $localModel->setMetaId($metaId);
                $localModel->setMetaIdentify($metaIdentify);
                $localModel->setLocaleCode($locale);
                $localModel->setConfigKey($configKey);
            }
            
            $localModel->setConfigValue($value);
            $localModel->forceCheck()->save();
            
            return true;
        } catch (\Throwable $e) {
            if (php_sapi_name() === 'cli') {
                echo "  [!] MetaTranslation 保存失败: {$metaIdentify}.{$configKey} ({$locale}) - " . $e->getMessage() . "\n";
            } else {
                w_log_error("MetaTranslation 保存失败: {$metaIdentify}.{$configKey} ({$locale}) - " . $e->getMessage());
            }
            return false;
        }
    }
    
    /**
     * 设置meta参数的翻译
     */
    public static function setParamTranslation(string $metaIdentify, string $paramName, string $locale, string $value): bool
    {
        return self::setTranslatedValue($metaIdentify, 'param.' . $paramName, $locale, $value);
    }
    
    /**
     * 设置meta名称的翻译
     */
    public static function setNameTranslation(string $metaIdentify, string $locale, string $value): bool
    {
        return self::setTranslatedValue($metaIdentify, 'name', $locale, $value);
    }
    
    /**
     * 设置meta描述的翻译
     */
    public static function setDescriptionTranslation(string $metaIdentify, string $locale, string $value): bool
    {
        return self::setTranslatedValue($metaIdentify, 'description', $locale, $value);
    }
    
    /**
     * 获取meta的所有翻译（按语言分组）
     * 
     * @param string $metaIdentify meta标识
     * @return array [locale_code => [config_key => config_value, ...], ...]
     */
    public static function getAllTranslations(string $metaIdentify): array
    {
        // 获取 meta_id
        /** @var Meta $metaModel */
        $metaModel = ObjectManager::getInstance(Meta::class);
        $meta = $metaModel->reset()
            ->where(Meta::fields_META_IDENTIFY, $metaIdentify)
            ->find()
            ->fetch();
        
        if (!$meta->getId()) {
            return [];
        }
        
        $metaId = (int)$meta->getId();
        
        // 获取所有翻译记录
        /** @var MetaLocal $localModel */
        $localModel = ObjectManager::getInstance(MetaLocal::class);
        $records = $localModel->reset()
            ->where(MetaLocal::fields_META_ID, $metaId)
            ->select()
            ->fetchArray();
        
        $translations = [];
        foreach ($records as $record) {
            $localeCode = $record[MetaLocal::fields_LOCALE_CODE];
            $configKey = $record[MetaLocal::fields_CONFIG_KEY];
            $configValue = $record[MetaLocal::fields_CONFIG_VALUE];
            
            if (!isset($translations[$localeCode])) {
                $translations[$localeCode] = [];
            }
            $translations[$localeCode][$configKey] = $configValue;
        }
        
        return $translations;
    }
}
