<?php

declare(strict_types=1);

/*
 * Weline Cms Module
 * CMS内容管理系统页面助手类 - 用于获取多语言内容
 */

namespace Weline\Cms\Helper;

use Weline\Cms\Model\Page;
use Weline\Cms\Model\Page\LocalDescription;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;

class PageHelper
{
    private LocalDescription $localDescription;

    public function __construct(LocalDescription $localDescription)
    {
        $this->localDescription = $localDescription;
    }

    /**
     * 获取当前语言下的页面内容
     * 
     * 回退逻辑：当前语言翻译 → 页面默认语言翻译 → 主表默认数据
     * 
     * @param Page $page 页面对象
     * @param string|null $locale 语言代码（不传则使用当前语言）
     * @return array 包含翻译后的所有字段
     */
    public function getLocalizedContent(Page $page, ?string $locale = null): array
    {
        if (!$locale) {
            $locale = Cookie::getLang();
        }

        // 获取页面指定的默认语言
        $defaultLocale = $page->getData(Page::fields_DEFAULT_LOCALE);
        
        // 第一层：主表数据（最后的回退选项）
        $result = [
            'name' => $page->getData(Page::fields_NAME),
            'title' => $page->getData(Page::fields_TITLE),
            'content' => $page->getData(Page::fields_CONTENT),
            'meta_title' => $page->getData(Page::fields_META_TITLE),
            'meta_description' => $page->getData(Page::fields_META_DESCRIPTION),
            'meta_keywords' => $page->getData(Page::fields_META_KEYWORDS),
        ];

        // 第二层：如果页面指定了默认语言，且当前语言不是默认语言，先尝试获取默认语言的翻译
        if ($defaultLocale && $locale !== $defaultLocale) {
            $defaultTranslation = clone $this->localDescription;
            $defaultTranslation->clear()
                ->where(LocalDescription::fields_ID, $page->getId())
                ->where('local_code', $defaultLocale)
                ->find()
                ->fetch();

            if ($defaultTranslation->getId()) {
                // 用默认语言的翻译覆盖主表数据
                $result['name'] = $defaultTranslation->getData(LocalDescription::fields_NAME) ?: $result['name'];
                $result['title'] = $defaultTranslation->getData(LocalDescription::fields_TITLE) ?: $result['title'];
                $result['content'] = $defaultTranslation->getData(LocalDescription::fields_CONTENT) ?: $result['content'];
                $result['meta_title'] = $defaultTranslation->getData(LocalDescription::fields_META_TITLE) ?: $result['meta_title'];
                $result['meta_description'] = $defaultTranslation->getData(LocalDescription::fields_META_DESCRIPTION) ?: $result['meta_description'];
                $result['meta_keywords'] = $defaultTranslation->getData(LocalDescription::fields_META_KEYWORDS) ?: $result['meta_keywords'];
                
                // 默认语言的样式配置
                $config = $defaultTranslation->getData('config');
                if ($config) {
                    $result['config'] = $config;
                }
            }
        }

        // 第三层：尝试获取当前语言的翻译数据（最高优先级）
        $translation = clone $this->localDescription;
        $translation->clear()
            ->where(LocalDescription::fields_ID, $page->getId())
            ->where('local_code', $locale)
            ->find()
            ->fetch();

        // 如果有当前语言的翻译，使用它覆盖之前的数据
        if ($translation->getId()) {
            $result['name'] = $translation->getData(LocalDescription::fields_NAME) ?: $result['name'];
            $result['title'] = $translation->getData(LocalDescription::fields_TITLE) ?: $result['title'];
            $result['content'] = $translation->getData(LocalDescription::fields_CONTENT) ?: $result['content'];
            $result['meta_title'] = $translation->getData(LocalDescription::fields_META_TITLE) ?: $result['meta_title'];
            $result['meta_description'] = $translation->getData(LocalDescription::fields_META_DESCRIPTION) ?: $result['meta_description'];
            $result['meta_keywords'] = $translation->getData(LocalDescription::fields_META_KEYWORDS) ?: $result['meta_keywords'];
            
            // 当前语言的样式配置（最高优先级）
            $config = $translation->getData('config');
            if ($config) {
                $result['config'] = $config;
            }
        }

        return $result;
    }

    /**
     * 获取特定字段的翻译内容
     * 
     * @param Page $page 页面对象
     * @param string $field 字段名
     * @param string|null $locale 语言代码
     * @return string
     */
    public function getLocalizedField(Page $page, string $field, ?string $locale = null): string
    {
        $content = $this->getLocalizedContent($page, $locale);
        return $content[$field] ?? '';
    }

    /**
     * 获取页面内容（自动根据当前语言）
     * 
     * @param Page $page 页面对象
     * @return string
     */
    public function getContent(Page $page): string
    {
        return $this->getLocalizedField($page, 'content');
    }

    /**
     * 获取页面标题（自动根据当前语言）
     * 
     * @param Page $page 页面对象
     * @return string
     */
    public function getTitle(Page $page): string
    {
        return $this->getLocalizedField($page, 'title');
    }

    /**
     * 获取页面名称（自动根据当前语言）
     * 
     * @param Page $page 页面对象
     * @return string
     */
    public function getName(Page $page): string
    {
        return $this->getLocalizedField($page, 'name');
    }

    /**
     * 检查页面是否有指定语言的翻译
     * 
     * @param Page $page 页面对象
     * @param string $locale 语言代码
     * @return bool
     */
    public function hasTranslation(Page $page, string $locale): bool
    {
        $translation = clone $this->localDescription;
        $translation->clear()
            ->where(LocalDescription::fields_ID, $page->getId())
            ->where('local_code', $locale)
            ->find()
            ->fetch();

        return (bool)$translation->getId();
    }

    /**
     * 获取页面所有已翻译的语言列表
     * 
     * @param Page $page 页面对象
     * @return array 语言代码数组
     */
    public function getTranslatedLocales(Page $page): array
    {
        $translations = clone $this->localDescription;
        $translations = $translations->clear()
            ->where(LocalDescription::fields_ID, $page->getId())
            ->select()
            ->fetch()
            ->getItems();

        $locales = [];
        foreach ($translations as $translation) {
            $locales[] = $translation->getData('local_code');
        }

        return $locales;
    }

    /**
     * 获取完整的SEO数据（包含hreflang）
     * 
     * @param Page $page 页面对象
     * @return array
     */
    public function getSeoData(Page $page): array
    {
        $currentLocale = Cookie::getLang();
        $content = $this->getLocalizedContent($page, $currentLocale);
        
        // 获取所有已翻译的语言
        $translatedLocales = $this->getTranslatedLocales($page);
        
        // 构建hreflang数据
        $hreflangs = [];
        foreach ($translatedLocales as $locale) {
            $hreflangs[$locale] = [
                'locale' => $locale,
                'url' => $this->getPageUrl($page, $locale)
            ];
        }

        return [
            'title' => $content['meta_title'] ?: $content['title'],
            'description' => $content['meta_description'],
            'keywords' => $content['meta_keywords'],
            'hreflangs' => $hreflangs,
            'og_title' => $content['meta_title'] ?: $content['title'],
            'og_description' => $content['meta_description'],
        ];
    }

    /**
     * 获取页面URL（带语言参数）
     * 
     * @param Page $page 页面对象
     * @param string|null $locale 语言代码
     * @return string
     */
    public function getPageUrl(Page $page, ?string $locale = null): string
    {
        // 这里需要根据您的路由规则来实现
        // 示例实现：
        $handle = $page->getData(Page::fields_HANDLE);
        $url = '/cms/frontend/page/view?handle=' . $handle;
        
        if ($locale) {
            $url .= '&lang=' . $locale;
        }
        
        return $url;
    }

    /**
     * 获取页面所有可用的语言（包括默认语言和已翻译的语言）
     * 
     * @param Page $page 页面对象
     * @return array 包含语言信息的数组 [['code' => 'zh_CN', 'name' => '简体中文', 'has_translation' => true], ...]
     */
    public function getAvailableLocales(Page $page): array
    {
        $selectedLocales = $page->getSelectedLocales();
        $translatedLocales = $this->getTranslatedLocales($page);
        
        // 如果没有选择语言，返回空数组
        if (empty($selectedLocales)) {
            return [];
        }
        
        $availableLocales = [];
        
        // 获取I18n模型来获取语言名称
        $i18nModel = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\I18n\Model\I18n::class);
        
        foreach ($selectedLocales as $locale) {
            $availableLocales[] = [
                'code' => $locale,
                'name' => $i18nModel->getLocaleName($locale),
                'has_translation' => in_array($locale, $translatedLocales),
                'url' => $this->getPageUrl($page, $locale)
            ];
        }
        
        return $availableLocales;
    }
}

