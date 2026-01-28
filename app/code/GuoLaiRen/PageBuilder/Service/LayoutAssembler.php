<?php

declare(strict_types=1);

/**
 * 布局组装服务
 * 
 * 负责根据页面的布局配置（layout_config）实时组装模板
 * 
 * 关键逻辑：
 * 1. Header/Footer 是网站全局配置，存储在首页（home_page）
 * 2. Content 区域配置存储在各个页面自身
 * 3. 渲染前先组装模板，然后再渲染
 */

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\Manager\ObjectManager;

class LayoutAssembler
{
    private Page $pageModel;
    
    /** @var array 组件文件映射缓存 */
    private static array $componentFilesCache = [];
    
    public function __construct()
    {
        $this->pageModel = ObjectManager::getInstance(Page::class);
    }
    
    /**
     * 获取页面的完整布局配置
     * 
     * Header/Footer 从首页获取（全局），Content 从当前页面获取
     * 
     * @param Page $page 当前页面
     * @return array ['header' => [...], 'content' => [...], 'footer' => [...]]
     */
    public function getFullLayoutConfig(Page $page): array
    {
        $pageId = $page->getId();
        
        // 使用 ObjectManager::make() 创建全新的 Page 实例
        // 这是解决 ORM 缓存/单例导致配置不生效的关键
        /** @var Page $freshPage */
        $freshPage = ObjectManager::make(Page::class);
        $freshPage->load($pageId);
        
        $websiteId = $freshPage->getData('website_id');
        $pageType = $freshPage->getData('type');
        $currentLayoutConfigJson = $freshPage->getData('layout_config');
        
        // 默认布局配置
        $fullConfig = [
            'header' => [],
            'content' => [],
            'footer' => [],
        ];
        
        // 获取首页（用于 header/footer 全局配置）
        $homePage = $this->getHomePage($websiteId);
        
        if ($homePage && $homePage->getId()) {
            $homeLayoutConfigJson = $homePage->getData('layout_config');
            error_log("[LayoutAssembler] HomePage ID: " . $homePage->getId() . ", layout_config: " . ($homeLayoutConfigJson ?: 'empty'));
            
            $homeLayoutConfig = $this->parseLayoutConfig($homeLayoutConfigJson);
            
            // Header 和 Footer 从首页获取（全局）
            $fullConfig['header'] = $homeLayoutConfig['header'] ?? [];
            $fullConfig['footer'] = $homeLayoutConfig['footer'] ?? [];
        }
        
        // Content 从当前页面获取（使用 freshPage 的数据）
        $currentLayoutConfig = $this->parseLayoutConfig($currentLayoutConfigJson);
        $fullConfig['content'] = $currentLayoutConfig['content'] ?? [];
        
        // 如果是首页，header/footer 也从自身获取
        if ($pageType === Page::TYPE_HOME) {
            if (!empty($currentLayoutConfig['header'])) {
                $fullConfig['header'] = $currentLayoutConfig['header'];
            }
            if (!empty($currentLayoutConfig['footer'])) {
                $fullConfig['footer'] = $currentLayoutConfig['footer'];
            }
        }
        
        return $fullConfig;
    }
    
    /**
     * 组装模板内容
     * 
     * 根据布局配置，将组件按顺序组装成完整的模板内容
     * 
     * @param Page $page 当前页面
     * @param string $styleCode 样式代码
     * @param array $styleSettings 样式配置
     * @param callable $renderCallback 渲染回调函数
     * @return array ['header' => string, 'content' => string, 'footer' => string]
     */
    public function assembleTemplate(
        Page $page, 
        string $styleCode, 
        array $styleSettings,
        callable $renderCallback
    ): array {
        $layoutConfig = $this->getFullLayoutConfig($page);
        
        $result = [
            'header' => '',
            'content' => '',
            'footer' => '',
            'debug' => [],
        ];
        
        // 获取组件文件映射
        $componentFiles = $this->getComponentFilesMap($styleCode);
        
        // 调试信息
        $result['debug']['layout_config'] = $layoutConfig;
        $result['debug']['component_files'] = array_keys($componentFiles);
        
        // 组装 Header（只有一个组件）
        if (!empty($layoutConfig['header'])) {
            $result['header'] = $this->renderComponents(
                $layoutConfig['header'],
                $styleCode,
                $componentFiles,
                $page,
                $styleSettings,
                $renderCallback
            );
            $result['debug']['header_rendered'] = true;
        }
        
        // 组装 Content（多个组件，按顺序）
        if (!empty($layoutConfig['content'])) {
            $result['content'] = $this->renderComponents(
                $layoutConfig['content'],
                $styleCode,
                $componentFiles,
                $page,
                $styleSettings,
                $renderCallback
            );
            $result['debug']['content_count'] = count($layoutConfig['content']);
        }
        
        // 组装 Footer（只有一个组件）
        if (!empty($layoutConfig['footer'])) {
            $result['footer'] = $this->renderComponents(
                $layoutConfig['footer'],
                $styleCode,
                $componentFiles,
                $page,
                $styleSettings,
                $renderCallback
            );
            $result['debug']['footer_rendered'] = true;
        }
        
        return $result;
    }
    
    /**
     * 渲染组件列表
     * 
     * @param array $components 组件配置列表
     * @param string $styleCode 样式代码
     * @param array $componentFiles 组件文件映射
     * @param Page $page 页面对象
     * @param array $styleSettings 样式配置
     * @param callable $renderCallback 渲染回调
     * @return string 渲染后的 HTML
     */
    private function renderComponents(
        array $components,
        string $styleCode,
        array $componentFiles,
        Page $page,
        array $styleSettings,
        callable $renderCallback
    ): string {
        $html = '';
        
        foreach ($components as $index => $componentConfig) {
            $code = $componentConfig['code'] ?? '';
            $enabled = $componentConfig['enabled'] ?? true;
            $config = $componentConfig['config'] ?? [];
            $componentTemplateCode = $componentConfig['template_code'] ?? '';
            
            if (!$enabled || empty($code)) {
                continue;
            }
            
            // 确定使用哪个模板的组件
            $useTemplateCode = $styleCode;
            $componentFile = $componentFiles[$code] ?? null;
            
            // 如果直接查找失败，尝试去掉模板前缀再查找
            // 例如：tpmst-slider -> slider, tpmst-advantages -> advantages
            if (!$componentFile && strpos($code, $styleCode . '-') === 0) {
                $codeWithoutPrefix = substr($code, strlen($styleCode) + 1);
                $componentFile = $componentFiles[$codeWithoutPrefix] ?? null;
            }
            
            // 如果当前模板没有这个组件，尝试从指定模板获取
            if (!$componentFile && !empty($componentTemplateCode) && $componentTemplateCode !== $styleCode) {
                $otherComponentFiles = $this->getComponentFilesMap($componentTemplateCode);
                $componentFile = $otherComponentFiles[$code] ?? null;
                
                // 同样尝试去掉前缀
                if (!$componentFile && strpos($code, $componentTemplateCode . '-') === 0) {
                    $codeWithoutPrefix = substr($code, strlen($componentTemplateCode) + 1);
                    $componentFile = $otherComponentFiles[$codeWithoutPrefix] ?? null;
                }
                
                if ($componentFile) {
                    $useTemplateCode = $componentTemplateCode;
                }
            }
            
            if (!$componentFile) {
                $html .= "<!-- Component not found: {$code} (tried with/without prefix) -->\n";
                continue;
            }
            
            // 构建组件路径
            $componentPath = "GuoLaiRen_PageBuilder::templates/style/{$useTemplateCode}/components/{$componentFile}";
            
            // 调用渲染回调
            try {
                $componentHtml = $renderCallback($componentPath, $page, $styleSettings, $config);
                $html .= $componentHtml;
                $html .= "<!-- Component {$code} rendered (index: {$index}) -->\n";
            } catch (\Throwable $e) {
                $html .= "<!-- Error rendering {$code}: " . htmlspecialchars($e->getMessage()) . " -->\n";
            }
        }
        
        return $html;
    }
    
    /**
     * 获取网站的首页
     * 注意：不过滤 status，确保在编辑模式下也能获取首页
     */
    private function getHomePage(?int $websiteId): ?Page
    {
        if (!$websiteId) {
            return null;
        }
        
        // 使用 ObjectManager::make() 创建全新实例，避免单例和缓存问题
        /** @var Page $homePage */
        $homePage = ObjectManager::make(Page::class);
        
        $homePage->where('website_id', $websiteId)
                 ->where('type', Page::TYPE_HOME)
                 ->find()
                 ->fetch();
        
        $homeId = $homePage->getId();
        
        return $homeId ? $homePage : null;
    }
    
    /**
     * 解析布局配置 JSON
     */
    private function parseLayoutConfig(?string $json): array
    {
        if (empty($json)) {
            return [];
        }
        
        $config = json_decode($json, true);
        return is_array($config) ? $config : [];
    }
    
    /**
     * 获取模板的组件文件映射
     */
    public function getComponentFilesMap(string $styleCode): array
    {
        if (isset(self::$componentFilesCache[$styleCode])) {
            return self::$componentFilesCache[$styleCode];
        }
        
        $componentJsonPath = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/{$styleCode}/components/component.json";
        
        if (!file_exists($componentJsonPath)) {
            self::$componentFilesCache[$styleCode] = [];
            return [];
        }
        
        $jsonContent = file_get_contents($componentJsonPath);
        $jsonConfig = json_decode($jsonContent, true);
        
        if (!$jsonConfig || !isset($jsonConfig['components'])) {
            self::$componentFilesCache[$styleCode] = [];
            return [];
        }
        
        $map = [];
        foreach ($jsonConfig['components'] as $code => $config) {
            $map[$code] = $config['file'] ?? ($code . '.phtml');
        }
        
        self::$componentFilesCache[$styleCode] = $map;
        return $map;
    }
    
    /**
     * 清除缓存（用于开发环境）
     */
    public static function clearCache(): void
    {
        self::$componentFilesCache = [];
    }
    
    /**
     * 获取布局中所有组件的配置字段（元数据）
     * 
     * 用于左侧配置面板根据当前组件显示对应的配置项
     * 
     * @param Page $page 当前页面
     * @param string $styleCode 样式代码
     * @return array 组件配置字段，按区域分组
     */
    public function getLayoutComponentFields(Page $page, string $styleCode): array
    {
        $layoutConfig = $this->getFullLayoutConfig($page);
        $componentFiles = $this->getComponentFilesMap($styleCode);
        
        $result = [
            'header' => [],
            'content' => [],
            'footer' => [],
        ];
        
        foreach (['header', 'content', 'footer'] as $region) {
            foreach ($layoutConfig[$region] ?? [] as $index => $componentConfig) {
                $code = $componentConfig['code'] ?? '';
                if (empty($code)) continue;
                
                $componentFile = $componentFiles[$code] ?? null;
                $actualCode = $code;
                
                // 特殊处理：tpmst-footer 映射到 footer-links（默认 footer 组件）
                if ($code === 'tpmst-footer' || $code === $styleCode . '-footer') {
                    $actualCode = 'footer-links';
                    $componentFile = $componentFiles[$actualCode] ?? null;
                }
                
                // 如果直接查找失败，尝试去掉模板前缀再查找
                if (!$componentFile && strpos($code, $styleCode . '-') === 0) {
                    $codeWithoutPrefix = substr($code, strlen($styleCode) + 1);
                    $componentFile = $componentFiles[$codeWithoutPrefix] ?? null;
                    if ($componentFile) {
                        $actualCode = $codeWithoutPrefix;
                    }
                }
                
                if (!$componentFile) continue;
                
                // 读取组件文件获取元数据
                $componentPath = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/{$styleCode}/components/{$componentFile}";
                
                if (file_exists($componentPath)) {
                    $fields = $this->parseComponentFields($componentPath);
                    $result[$region][] = [
                        'code' => $code,
                        'actual_code' => $actualCode,
                        'index' => $index,
                        'fields' => $fields,
                        'config' => $componentConfig['config'] ?? [],
                    ];
                }
            }
        }
        
        return $result;
    }
    
    /**
     * 解析组件文件中的字段定义（@fields_start ... @fields_end）
     * 
     * @param string $filePath 组件文件路径
     * @return array 字段定义
     */
    private function parseComponentFields(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $fields = [];
        
        // 匹配 @fields_start 和 @fields_end 之间的内容
        if (preg_match('/@fields_start\s*(.*?)\s*@fields_end/s', $content, $matches)) {
            $fieldDefs = $matches[1];
            $lines = explode("\n", $fieldDefs);
            
            $currentGroup = 'general';
            $currentGroupLabel = '通用设置';
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '*') === 0) {
                    $line = ltrim($line, '* ');
                }
                if (empty($line)) continue;
                
                // 解析分组: group:key => Label
                if (preg_match('/^group:(\w+)\s*=>\s*(.+)$/', $line, $groupMatch)) {
                    $currentGroup = $groupMatch[1];
                    $currentGroupLabel = trim($groupMatch[2]);
                    if (!isset($fields[$currentGroup])) {
                        $fields[$currentGroup] = [
                            'label' => $currentGroupLabel,
                            'fields' => [],
                        ];
                    }
                    continue;
                }
                
                // 解析字段: field.name => Label:type:default|options
                if (preg_match('/^([\w.]+)\s*=>\s*(.+)$/', $line, $fieldMatch)) {
                    $fieldKey = $fieldMatch[1];
                    $fieldDef = $fieldMatch[2];
                    
                    // 解析字段定义
                    $parts = explode(':', $fieldDef);
                    $label = trim($parts[0] ?? '');
                    $type = trim($parts[1] ?? 'text');
                    $defaultAndOptions = trim($parts[2] ?? '');
                    
                    // 分离默认值和选项
                    $defaultValue = '';
                    $options = [];
                    if (strpos($defaultAndOptions, '|') !== false) {
                        list($defaultValue, $optionsStr) = explode('|', $defaultAndOptions, 2);
                        if (!empty($optionsStr)) {
                            $options = array_map('trim', explode(',', $optionsStr));
                        }
                    } else {
                        $defaultValue = $defaultAndOptions;
                    }
                    
                    if (!isset($fields[$currentGroup])) {
                        $fields[$currentGroup] = [
                            'label' => $currentGroupLabel,
                            'fields' => [],
                        ];
                    }
                    
                    $fields[$currentGroup]['fields'][$fieldKey] = [
                        'label' => $label,
                        'type' => $type,
                        'default' => $defaultValue,
                        'options' => $options,
                    ];
                }
            }
        }
        
        return $fields;
    }
    
    /**
     * 获取组件的元数据信息
     * 
     * @param string $styleCode 样式代码
     * @param string $componentCode 组件代码
     * @return array|null 组件元数据
     */
    public function getComponentMetadata(string $styleCode, string $componentCode): ?array
    {
        $componentFiles = $this->getComponentFilesMap($styleCode);
        $componentFile = $componentFiles[$componentCode] ?? null;
        
        // 实际使用的组件代码（可能去掉前缀后的）
        $actualCode = $componentCode;
        
        // 特殊处理：tpmst-footer 映射到 footer-links（默认 footer 组件）
        if ($componentCode === 'tpmst-footer' || $componentCode === $styleCode . '-footer') {
            $actualCode = 'footer-links';
            $componentFile = $componentFiles[$actualCode] ?? null;
        }
        
        // 如果直接查找失败，尝试去掉模板前缀再查找
        // 例如：tpmst-slider -> slider, tpmst-advantages -> advantages
        if (!$componentFile && strpos($componentCode, $styleCode . '-') === 0) {
            $codeWithoutPrefix = substr($componentCode, strlen($styleCode) + 1);
            $componentFile = $componentFiles[$codeWithoutPrefix] ?? null;
            if ($componentFile) {
                $actualCode = $codeWithoutPrefix;
            }
        }
        
        if (!$componentFile) {
            return null;
        }
        
        $componentPath = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/{$styleCode}/components/{$componentFile}";
        
        if (!file_exists($componentPath)) {
            return null;
        }
        
        $content = file_get_contents($componentPath);
        $metadata = [
            'code' => $componentCode,          // 保留原始请求的代码（可能带前缀）
            'actual_code' => $actualCode,      // 实际在 component.json 中的代码
            'file' => $componentFile,
            'fields' => $this->parseComponentFields($componentPath),
        ];
        
        // 从 component.json 中读取组件元数据
        $componentJsonPath = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/{$styleCode}/components/component.json";
        if (file_exists($componentJsonPath)) {
            $jsonContent = file_get_contents($componentJsonPath);
            $jsonConfig = json_decode($jsonContent, true);
            if ($jsonConfig && isset($jsonConfig['components'][$actualCode])) {
                $jsonMeta = $jsonConfig['components'][$actualCode];
                // 合并 JSON 中的元数据
                $metadata['name'] = $jsonMeta['name'] ?? $actualCode;
                $metadata['name_en'] = $jsonMeta['name_en'] ?? '';
                $metadata['description'] = $jsonMeta['description'] ?? '';
                $metadata['region'] = $jsonMeta['region'] ?? 'content';
                $metadata['category'] = $jsonMeta['category'] ?? '';
                $metadata['type'] = $jsonMeta['type'] ?? 'section';
                $metadata['thumbnail'] = $jsonMeta['thumbnail'] ?? '';
                $metadata['config_groups'] = $jsonMeta['config_groups'] ?? [];
            }
        }
        
        // 解析 @component_start ... @component_end（文件内定义的元数据可覆盖 JSON）
        if (preg_match('/@component_start\s*(.*?)\s*@component_end/s', $content, $matches)) {
            $componentDefs = $matches[1];
            $lines = explode("\n", $componentDefs);
            
            foreach ($lines as $line) {
                $line = trim(ltrim(trim($line), '* '));
                if (empty($line)) continue;
                
                if (preg_match('/^(\w+):\s*(.+)$/', $line, $defMatch)) {
                    $metadata[$defMatch[1]] = trim($defMatch[2]);
                }
            }
        }
        
        return $metadata;
    }
    
    /**
     * 保存布局配置
     * 
     * @param Page $page 页面对象
     * @param string $region 区域 (header/content/footer)
     * @param array $components 组件配置
     * @param bool $isGlobal 是否为全局配置（header/footer）
     */
    public function saveLayoutConfig(Page $page, string $region, array $components, bool $isGlobal = false): void
    {
        // 如果是全局配置（header/footer），需要保存到首页
        if ($isGlobal && in_array($region, ['header', 'footer'])) {
            $websiteId = $page->getData('website_id');
            $homePage = $this->getHomePage($websiteId);
            
            if ($homePage && $homePage->getId()) {
                $page = $homePage;
            }
        }
        
        // 获取当前布局配置
        $layoutConfig = $this->parseLayoutConfig($page->getData('layout_config'));
        
        // 更新指定区域
        $layoutConfig[$region] = $components;
        
        // 保存
        $newLayoutConfigJson = json_encode($layoutConfig, JSON_UNESCAPED_UNICODE);
        
        $pageModel = clone $this->pageModel;
        $pageModel->where('page_id', $page->getId())
                  ->update(['layout_config' => $newLayoutConfigJson]);
    }
}
