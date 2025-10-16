<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 前端页面展示控制器
 */

namespace GuoLaiRen\PageBuilder\Controller\Frontend;

use GuoLaiRen\PageBuilder\Helper\PageHelper;
use GuoLaiRen\PageBuilder\Model\Page as PageModel;
use GuoLaiRen\PageBuilder\Model\Style;
use Weline\Framework\App\Controller\FrontendController;

class Page extends FrontendController
{
    private PageModel $pageModel;
    private PageHelper $pageHelper;
    private Style $styleModel;

    public function __construct(
        PageModel $pageModel,
        PageHelper $pageHelper,
        Style $styleModel
    ) {
        $this->pageModel = $pageModel;
        $this->pageHelper = $pageHelper;
        $this->styleModel = $styleModel;
    }

    /**
     * 根据句柄显示页面
     */
    public function view()
    {
        $handle = $this->request->getGet('handle');
        
        if (!$handle) {
            $this->getMessageManager()->addError(__('页面不存在！'));
            $this->redirect(404);
            return;
        }

        // 检查是否为预览模式
        $isPreview = $this->request->getGet('preview') == '1';
        
        // 获取URL中的语言参数
        $requestedLocale = $this->request->getGet('lang', $this->request->getGet('locale'));
        
        // 如果URL中指定了语言，更新Cookie
        if ($requestedLocale) {
            \Weline\Framework\Http\Cookie::set('WELINE_USER_LANG', $requestedLocale, 3600 * 24 * 30);
        }
        
        // 获取当前使用的语言（从Cookie或URL参数）
        $currentLocale = $requestedLocale ?: \Weline\Framework\Http\Cookie::getLang();

        // 加载页面
        $page = clone $this->pageModel;
        $page->clear()
            ->where(PageModel::fields_HANDLE, $handle)
            ->where(PageModel::fields_STATUS, PageModel::STATUS_PUBLISHED)
            ->find()
            ->fetch();

        if (!$page->getId()) {
            $this->getMessageManager()->addError(__('页面不存在！'));
            $this->redirect(404);
            return;
        }

        // 检查页面是否选择了当前语言
        $selectedLocales = $page->getSelectedLocales();
        $isLocaleSupported = empty($selectedLocales) || in_array($currentLocale, $selectedLocales);
        
        // 获取指定语言的内容
        $localizedContent = $this->pageHelper->getLocalizedContent($page, $currentLocale);
        
        // 检查是否有该语言的翻译
        $hasTranslation = $this->pageHelper->hasTranslation($page, $currentLocale);
        
        // 获取所有可用的语言
        $availableLocales = $this->pageHelper->getAvailableLocales($page);
        
        // 获取SEO数据
        $seoData = $this->pageHelper->getSeoData($page);

        // 传递数据到视图
        $this->assign('page', $page);
        $this->assign('content', $localizedContent);
        $this->assign('seo', $seoData);
        $this->assign('title', $seoData['title']);
        $this->assign('current_locale', $currentLocale);
        $this->assign('available_locales', $availableLocales);
        $this->assign('has_translation', $hasTranslation);
        $this->assign('is_locale_supported', $isLocaleSupported);
        $this->assign('is_preview', $isPreview);

        // 获取页面的样式和样式配置
        $styleCode = $page->getData(PageModel::fields_STYLE);
        $allStyleSettings = $page->getStyleSetting(); // 获取页面的所有样式配置
        
        // 获取当前样式的配置值（从所有样式配置中提取）
        $pageStyleSettings = [];
        if ($styleCode && isset($allStyleSettings[$styleCode])) {
            $pageStyleSettings = $allStyleSettings[$styleCode];
        }
        
        if ($styleCode) {
            // 加载样式信息
            $style = clone $this->styleModel;
            $style->clear()
                ->where(Style::fields_CODE, $styleCode)
                ->where(Style::fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            if ($style->getId()) {
                // 获取样式的默认配置（最低优先级）
                $styleConfigs = $style->parseStyleConfig();
                $finalSettings = [];
                
                // 第一步：使用默认配置值
                foreach ($styleConfigs as $key => $config) {
                    if (isset($config['default'])) {
                        $finalSettings[$key] = $config['default'];
                    }
                }
                
                // 第二步：用页面保存的配置覆盖（中等优先级）
                foreach ($pageStyleSettings as $key => $value) {
                    if (isset($styleConfigs[$key])) {
                        $finalSettings[$key] = $value;
                    }
                }
                
                // 第三步：用翻译的配置覆盖（最高优先级）
                // 从本地化描述中获取翻译的样式配置
                if ($localizedContent && !empty($localizedContent['config'])) {
                    $translatedConfig = is_string($localizedContent['config']) 
                        ? json_decode($localizedContent['config'], true) 
                        : $localizedContent['config'];
                    
                    // 检查是否有 style_config 节点
                    if (isset($translatedConfig['style_config']) && is_array($translatedConfig['style_config'])) {
                        foreach ($translatedConfig['style_config'] as $key => $value) {
                            // 只覆盖样式定义中存在的配置项
                            if (isset($styleConfigs[$key])) {
                                $finalSettings[$key] = $value;
                            }
                        }
                    }
                }
                
                // 将最终配置传递给模板
                $this->assign('style', $finalSettings);
                
                // 使用样式模板
                $headerTemplate = $style->getHeaderPath();
                $footerTemplate = $style->getFooterPath();
                
                // 渲染header
                echo $this->render($headerTemplate);
                
                // 渲染主要内容
                echo $this->renderContent($localizedContent, $hasTranslation, $isLocaleSupported);
                
                // 渲染footer
                echo $this->render($footerTemplate);
                
                return;
            }
        }
        
        // 如果没有指定样式或样式不存在，设置空的样式配置
        $this->assign('style', []);
        
        // 使用默认模板
        return $this->fetch();
    }
    
    /**
     * 渲染内容部分
     */
    private function renderContent(array $content, bool $hasTranslation, bool $isLocaleSupported): string
    {
        $html = '';
        
        // 标题
        $html .= '<h1 class="page-title">' . htmlspecialchars($content['title']) . '</h1>';
        
        // 发布时间
        $page = $this->getData('page');
        if ($page && $page->getData('create_time')) {
            $html .= '<div class="page-meta">' . __('发布时间：') . $page->getData('create_time') . '</div>';
        }
        
        // 翻译提示
        if (!$hasTranslation) {
            $html .= '<div class="translation-notice">';
            $html .= '<strong>' . __('提示：') . '</strong> ';
            if (!$isLocaleSupported) {
                $html .= __('此页面不支持当前语言，以下内容显示为默认语言。');
            } else {
                $html .= __('此页面尚未翻译为当前语言，以下内容显示为默认语言。');
            }
            $html .= '</div>';
        }
        
        // 解析页面内容中的变量
        $parsedContent = $this->parseContentVariables($content['content']);
        
        // 页面内容
        $html .= '<div class="page-content">' . $parsedContent . '</div>';
        
        return $html;
    }
    
    /**
     * 解析内容中的变量
     * 支持 {{style.xxx}}, {{page.xxx}}, {{content.xxx}} 等变量
     */
    private function parseContentVariables(string $content): string
    {
        // 获取所有可用的数据
        $data = $this->getData();
        
        // 解析 {{variable.key}} 格式的变量
        $content = preg_replace_callback('/\{\{([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)\}\}/', function($matches) use ($data) {
            $varName = $matches[1];  // 如 style, page, content
            $key = $matches[2];       // 如 background_color
            
            // 检查数据是否存在
            if (isset($data[$varName])) {
                $varData = $data[$varName];
                
                // 如果是数组，返回对应的值
                if (is_array($varData) && isset($varData[$key])) {
                    return htmlspecialchars($varData[$key]);
                }
                
                // 如果是对象，尝试调用 getData 方法
                if (is_object($varData) && method_exists($varData, 'getData')) {
                    $value = $varData->getData($key);
                    return $value !== null ? htmlspecialchars($value) : '';
                }
            }
            
            // 如果变量不存在，保留原样
            return $matches[0];
        }, $content);
        
        return $content;
    }
    
    /**
     * 渲染模板
     */
    private function render(string $templatePath): string
    {
        ob_start();
        
        // 创建一个模板实例
        $template = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\View\Template::class);
        
        // 传递所有数据到模板
        foreach ($this->getData() as $key => $value) {
            $template->assign($key, $value);
        }
        
        // 渲染模板
        echo $template->fetchFile($templatePath);
        
        return ob_get_clean();
    }
}
