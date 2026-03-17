<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 页面助手类 - 用于获取多语言内容
 */

namespace GuoLaiRen\PageBuilder\Helper;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\Page\LocalDescription;
use Weline\Framework\Http\Cookie;

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
        $defaultLocale = $page->getData(Page::schema_fields_DEFAULT_LOCALE);
        
        // 第一层：主表数据（最后的回退选项）
        $result = [
            'name' => $page->getData(Page::schema_fields_NAME),
            'title' => $page->getData(Page::schema_fields_TITLE),
            'content' => $page->getData(Page::schema_fields_CONTENT),
            'meta_title' => $page->getData(Page::schema_fields_META_TITLE),
            'meta_description' => $page->getData(Page::schema_fields_META_DESCRIPTION),
            'meta_keywords' => $page->getData(Page::schema_fields_META_KEYWORDS),
        ];

        // 第二层：如果页面指定了默认语言，且当前语言不是默认语言，先尝试获取默认语言的翻译
        if ($defaultLocale && $locale !== $defaultLocale) {
            $defaultTranslation = clone $this->localDescription;
            $defaultTranslation->clear()
                ->where(LocalDescription::schema_fields_ID, $page->getId())
                ->where('local_code', $defaultLocale)
                ->find()
                ->fetch();

            if ($defaultTranslation->getId()) {
                // 用默认语言的翻译覆盖主表数据
                $result['name'] = $defaultTranslation->getData(LocalDescription::schema_fields_NAME) ?: $result['name'];
                $result['title'] = $defaultTranslation->getData(LocalDescription::schema_fields_TITLE) ?: $result['title'];
                $result['content'] = $defaultTranslation->getData(LocalDescription::schema_fields_CONTENT) ?: $result['content'];
                $result['meta_title'] = $defaultTranslation->getData(LocalDescription::schema_fields_META_TITLE) ?: $result['meta_title'];
                $result['meta_description'] = $defaultTranslation->getData(LocalDescription::schema_fields_META_DESCRIPTION) ?: $result['meta_description'];
                $result['meta_keywords'] = $defaultTranslation->getData(LocalDescription::schema_fields_META_KEYWORDS) ?: $result['meta_keywords'];
                
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
            ->where(LocalDescription::schema_fields_ID, $page->getId())
            ->where('local_code', $locale)
            ->find()
            ->fetch();

        // 如果有当前语言的翻译，使用它覆盖之前的数据
        if ($translation->getId()) {
            $result['name'] = $translation->getData(LocalDescription::schema_fields_NAME) ?: $result['name'];
            $result['title'] = $translation->getData(LocalDescription::schema_fields_TITLE) ?: $result['title'];
            $result['content'] = $translation->getData(LocalDescription::schema_fields_CONTENT) ?: $result['content'];
            $result['meta_title'] = $translation->getData(LocalDescription::schema_fields_META_TITLE) ?: $result['meta_title'];
            $result['meta_description'] = $translation->getData(LocalDescription::schema_fields_META_DESCRIPTION) ?: $result['meta_description'];
            $result['meta_keywords'] = $translation->getData(LocalDescription::schema_fields_META_KEYWORDS) ?: $result['meta_keywords'];
            
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
            ->where(LocalDescription::schema_fields_ID, $page->getId())
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
            ->where(LocalDescription::schema_fields_ID, $page->getId())
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
        
        // 检查多语言功能是否开启（通过查询器，避免跨模块直接调用）
        $i18nEnabled = w_query('system_config', 'getConfig', [
            'key' => 'i18n_enabled',
            'module' => 'GuoLaiRen_PageBuilder',
            'area' => 'backend',
        ]);
        $i18nEnabled = $i18nEnabled === null ? '0' : $i18nEnabled; // 默认不开启
        
        // 获取所有已翻译的语言（始终生成，但通过CSS控制显示）
        $translatedLocales = $this->getTranslatedLocales($page);
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
            'i18n_enabled' => $i18nEnabled, // 传递多语言配置状态
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
        $handle = $page->getData(Page::schema_fields_HANDLE);
        $url = '/pagebuilder/frontend/page/view?handle=' . $handle;
        
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

        foreach ($selectedLocales as $locale) {
            $availableLocales[] = [
                'code' => $locale,
                'name' => (string)w_query('i18n', 'getLocaleName', ['code' => $locale]),
                'has_translation' => in_array($locale, $translatedLocales),
                'url' => $this->getPageUrl($page, $locale)
            ];
        }
        
        return $availableLocales;
    }

    /**
     * 规范化 URL：端口为 80（http）或 443（https）时不带端口，其它端口保留
     * 生成链接时调用，使输出与当前访问习惯一致
     *
     * @param string $url 原始 URL（如 https://example.com:443/about 或 https://example.com:9981/）
     * @return string 规范化后的 URL
     */
    public static function normalizeUrlDefaultPort(string $url): string
    {
        if ($url === '') {
            return $url;
        }
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['port'])) {
            return $url;
        }
        $port = (int)$p['port'];
        $scheme = $p['scheme'] ?? 'http';
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $host = $p['host'] ?? '';
            $path = $p['path'] ?? '/';
            $query = isset($p['query']) && $p['query'] !== '' ? '?' . $p['query'] : '';
            $fragment = isset($p['fragment']) && $p['fragment'] !== '' ? '#' . $p['fragment'] : '';
            return $scheme . '://' . $host . $path . $query . $fragment;
        }
        return $url;
    }

    /**
     * 将纯锚点链接转为根路径，避免相对路径导致跨页跳错。例如 #games -> /#games
     * 仅对以 # 开头且长度>1 的链接加前缀 /，其它（/、http 等）原样返回
     *
     * @param string $url 原始链接（如 #games、#download、/contact）
     * @return string 规范化后的链接
     */
    public static function normalizeAnchorUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || $url === '#') {
            return $url !== '' ? $url : '#';
        }
        if ($url[0] === '#') {
            return '/' . $url;
        }
        return $url;
    }
}

