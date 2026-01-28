<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 组件模型 - 用于管理可视化页面构建器的组件
 * 
 * 组件系统设计：
 * 1. 每个模板(style)下有 components/ 目录存放可复用组件
 * 2. 组件通过 @component_start / @component_end 定义元数据
 * 3. 组件可以跨模板使用，但优先推荐同模板组件
 * 4. 支持 header、footer、content-section 三种类型
 */

namespace GuoLaiRen\PageBuilder\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Component extends Model
{
    public const table = 'guolairen_page_builder_component';
    
    // 字段定义
    public const fields_ID = 'component_id';
    public const fields_CODE = 'code';                    // 组件代码（唯一标识）
    public const fields_NAME = 'name';                    // 组件名称
    public const fields_DESCRIPTION = 'description';      // 组件描述
    public const fields_STYLE_CODE = 'style_code';        // 所属模板代码
    public const fields_CATEGORY = 'category';            // 组件分类：header, footer, content
    public const fields_TYPE = 'type';                    // 组件类型：section, widget, layout
    public const fields_PATH = 'path';                    // 组件文件路径
    public const fields_THUMBNAIL = 'thumbnail';          // 组件缩略图
    public const fields_CONFIG_SCHEMA = 'config_schema';  // 配置项定义（JSON）
    public const fields_DEFAULT_CONFIG = 'default_config';// 默认配置（JSON）
    public const fields_COMPATIBLE_STYLES = 'compatible_styles'; // 兼容的模板列表（JSON）
    public const fields_DEPENDENCIES = 'dependencies';    // 依赖的其他组件（JSON）
    public const fields_SORT_ORDER = 'sort_order';        // 排序
    public const fields_IS_ACTIVE = 'is_active';          // 是否启用
    public const fields_IS_SYSTEM = 'is_system';          // 是否系统组件（header/footer）
    public const fields_CREATE_TIME = 'create_time';
    public const fields_UPDATE_TIME = 'update_time';
    
    // 组件分类常量
    public const CATEGORY_HEADER = 'header';
    public const CATEGORY_FOOTER = 'footer';
    public const CATEGORY_CONTENT = 'content';
    public const CATEGORY_WIDGET = 'widget';
    
    // 组件类型常量
    public const TYPE_SECTION = 'section';      // 区块组件（如 Banner、Feature Cards）
    public const TYPE_WIDGET = 'widget';        // 小部件（如 Button、Form）
    public const TYPE_LAYOUT = 'layout';        // 布局组件（如 Grid、Flex）
    public const TYPE_SYSTEM = 'system';        // 系统组件（header、footer）
    
    /**
     * 获取所有组件分类
     */
    public static function getCategories(): array
    {
        return [
            self::CATEGORY_HEADER => __('头部组件'),
            self::CATEGORY_FOOTER => __('底部组件'),
            self::CATEGORY_CONTENT => __('内容组件'),
            self::CATEGORY_WIDGET => __('小部件'),
        ];
    }
    
    /**
     * 获取所有组件类型
     */
    public static function getTypes(): array
    {
        return [
            self::TYPE_SECTION => __('区块组件'),
            self::TYPE_WIDGET => __('小部件'),
            self::TYPE_LAYOUT => __('布局组件'),
            self::TYPE_SYSTEM => __('系统组件'),
        ];
    }
    
    /**
     * 生成组件代码（格式：模板名_类型_名字）
     * 
     * @param string $styleCode 模板代码
     * @param string $category 组件分类（header, footer, content）
     * @param string $name 组件名称
     * @return string 生成的组件代码
     */
    public static function generateComponentCode(string $styleCode, string $category, string $name): string
    {
        // 清理组件名称：移除路径分隔符，转换为下划线格式
        $cleanName = str_replace(['/', '\\', '-'], '_', $name);
        // 移除多余的下划线
        $cleanName = preg_replace('/_+/', '_', $cleanName);
        $cleanName = trim($cleanName, '_');
        
        // 如果名称以 category 开头，移除重复的前缀
        // 例如：header_nav -> nav (当 category=header 时)
        // 例如：footer_links -> links (当 category=footer 时)
        // 例如：content_slider -> slider (当 category=content 时)
        $categoryPrefix = strtolower($category) . '_';
        if (stripos($cleanName, $categoryPrefix) === 0) {
            $cleanName = substr($cleanName, strlen($categoryPrefix));
        }
        // 也处理没有下划线的情况：header_header -> header（如果名称等于category）
        if (strtolower($cleanName) === strtolower($category)) {
            $cleanName = $category; // 保持原名
        }
        
        // 如果名称为空，使用 category 作为名称
        if (empty($cleanName)) {
            $cleanName = $category;
        }
        
        // 生成组件代码：模板名_类型_名字
        return strtolower($styleCode . '_' . $category . '_' . $cleanName);
    }
    
    /**
     * 获取组件的配置定义
     */
    public function getConfigSchema(): array
    {
        $schema = $this->getData(self::fields_CONFIG_SCHEMA);
        if (empty($schema)) {
            return [];
        }
        return json_decode($schema, true) ?: [];
    }
    
    /**
     * 设置配置定义
     */
    public function setConfigSchema(array $schema): self
    {
        return $this->setData(self::fields_CONFIG_SCHEMA, json_encode($schema, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 获取默认配置
     */
    public function getDefaultConfig(): array
    {
        $config = $this->getData(self::fields_DEFAULT_CONFIG);
        if (empty($config)) {
            return [];
        }
        return json_decode($config, true) ?: [];
    }
    
    /**
     * 设置默认配置
     */
    public function setDefaultConfig(array $config): self
    {
        return $this->setData(self::fields_DEFAULT_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 获取兼容的模板列表
     */
    public function getCompatibleStyles(): array
    {
        $styles = $this->getData(self::fields_COMPATIBLE_STYLES);
        if (empty($styles)) {
            // 默认兼容所有模板
            return ['*'];
        }
        return json_decode($styles, true) ?: ['*'];
    }
    
    /**
     * 检查是否兼容指定模板
     */
    public function isCompatibleWith(string $styleCode): bool
    {
        $compatibleStyles = $this->getCompatibleStyles();
        // * 表示兼容所有模板
        if (in_array('*', $compatibleStyles)) {
            return true;
        }
        // 检查是否在兼容列表中
        return in_array($styleCode, $compatibleStyles);
    }
    
    /**
     * 获取组件的完整文件路径
     */
    public function getFullPath(): string
    {
        $path = $this->getData(self::fields_PATH);
        if (empty($path)) {
            return '';
        }
        return BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/' . $path;
    }
    
    /**
     * 检查组件文件是否存在
     */
    public function fileExists(): bool
    {
        $fullPath = $this->getFullPath();
        return !empty($fullPath) && file_exists($fullPath);
    }
    
    /**
     * 获取组件渲染内容
     * 
     * @param array $config 自定义配置
     * @param mixed $page 页面对象
     * @return string 渲染后的 HTML
     */
    public function render(array $config = [], $page = null): string
    {
        if (!$this->fileExists()) {
            return '<!-- Component file not found: ' . htmlspecialchars($this->getData(self::fields_CODE)) . ' -->';
        }
        
        // 合并默认配置和自定义配置
        $mergedConfig = array_merge($this->getDefaultConfig(), $config);
        
        // TODO: 使用框架的模板引擎渲染组件
        // 这里需要调用 Weline 框架的模板渲染方法
        
        return '';
    }
    
    /**
     * 根据模板代码获取组件列表
     * 
     * @param string $styleCode 模板代码
     * @param bool $includeCompatible 是否包含兼容的组件
     * @param bool $activeOnly 是否只返回启用的组件
     * @return array
     */
    public static function getByStyleCode(string $styleCode, bool $includeCompatible = true, bool $activeOnly = true): array
    {
        // #region agent log
        $debugLog = function($msg, $data, $hyp) { @file_put_contents(BP . '.cursor/debug.log', json_encode(['location' => 'Component.php:getByStyleCode', 'message' => $msg, 'data' => $data, 'hypothesisId' => $hyp, 'timestamp' => microtime(true)]) . "\n", FILE_APPEND); };
        $debugLog('Entry', ['styleCode' => $styleCode, 'includeCompatible' => $includeCompatible, 'activeOnly' => $activeOnly], 'B');
        // #endregion
        
        $componentModel = \Weline\Framework\Manager\ObjectManager::getInstance(self::class);
        
        // 获取属于该模板的组件
        $ownComponents = clone $componentModel;
        $query = $ownComponents->clear()
            ->where(self::fields_STYLE_CODE, $styleCode);
        
        if ($activeOnly) {
            $query->where(self::fields_IS_ACTIVE, 1);
        }
        
        // #region agent log
        $debugLog('Before own query', ['styleCode' => $styleCode], 'B');
        // #endregion
        
        $result = [
            'own' => $query->order(self::fields_SORT_ORDER, 'ASC')
                ->select()
                ->fetch()
                ->getItems(),
            'compatible' => [],
        ];
        
        // #region agent log
        $debugLog('Own query done', ['ownCount' => count($result['own'])], 'B');
        // #endregion
        
        // 如果需要包含兼容的组件
        if ($includeCompatible) {
            $allComponents = clone $componentModel;
            $allQuery = $allComponents->clear()
                ->where(self::fields_STYLE_CODE, $styleCode, '!=');
            
            if ($activeOnly) {
                $allQuery->where(self::fields_IS_ACTIVE, 1);
            }
            
            // #region agent log
            $debugLog('Before compatible query', ['excludeStyleCode' => $styleCode], 'E');
            // #endregion
            
            $allItems = $allQuery->order(self::fields_STYLE_CODE, 'ASC')
                ->order(self::fields_SORT_ORDER, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            
            // #region agent log
            $debugLog('Compatible query done', ['allItemsCount' => count($allItems)], 'E');
            // #endregion
            
            // 过滤出兼容的组件
            foreach ($allItems as $component) {
                if ($component->isCompatibleWith($styleCode)) {
                    $componentStyleCode = $component->getData(self::fields_STYLE_CODE);
                    if (!isset($result['compatible'][$componentStyleCode])) {
                        $result['compatible'][$componentStyleCode] = [];
                    }
                    $result['compatible'][$componentStyleCode][] = $component;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * 扫描并注册指定模板的组件
     * 
     * @param string $styleCode 模板代码
     * @return array 扫描结果
     */
    public static function scanAndRegister(string $styleCode): array
    {
        $basePath = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/style/' . $styleCode . '/';
        $result = [
            'scanned' => 0,
            'registered' => 0,
            'updated' => 0,
            'cleaned' => 0,
            'errors' => [],
        ];
        
        // 0. 清理旧格式的组件
        // 删除所有该模板下的组件，然后重新注册（确保格式一致）
        $componentsDir = $basePath . 'components/';
        $componentJsonFile = $componentsDir . 'component.json';
        
        // 删除该模板下所有现有组件（使用新格式重新注册）
        // 注意：必须使用 ->delete()->fetch() 来执行删除，而不是 $item->delete()
        $componentModel = \Weline\Framework\Manager\ObjectManager::getInstance(self::class);
        
        // 先获取要删除的数量
        $existingComponents = clone $componentModel;
        $toDeleteItems = $existingComponents->clear()
            ->where(self::fields_STYLE_CODE, $styleCode)
            ->select()
            ->fetch()
            ->getItems();
        $result['cleaned'] = count($toDeleteItems);
        
        // 使用批量删除
        if ($result['cleaned'] > 0) {
            $deleteQuery = clone $componentModel;
            $deleteQuery->clear()
                ->where(self::fields_STYLE_CODE, $styleCode)
                ->delete()
                ->fetch();
        }
        
        // 1. 扫描系统组件（header.phtml 和 footer.phtml）
        $systemFiles = [
            'header' => $basePath . 'header.phtml',
            'footer' => $basePath . 'footer.phtml',
        ];
        
        foreach ($systemFiles as $category => $filePath) {
            if (file_exists($filePath)) {
                $result['scanned']++;
                try {
                    // 使用统一格式生成组件代码：模板名_类型_名字
                    $componentCode = self::generateComponentCode($styleCode, $category, $category);
                    $registerResult = self::registerComponentFromFile(
                        $styleCode,
                        $componentCode,
                        $filePath,
                        $category,
                        self::TYPE_SYSTEM,
                        true
                    );
                    if ($registerResult['created']) {
                        $result['registered']++;
                    } else {
                        $result['updated']++;
                    }
                } catch (\Exception $e) {
                    $result['errors'][] = "Error registering {$category}: " . $e->getMessage();
                }
            }
        }
        
        // 2. 扫描 components 目录（优先使用 component.json 配置）
        $componentsDir = $basePath . 'components/';
        $componentJsonFile = $componentsDir . 'component.json';
        
        if (file_exists($componentJsonFile)) {
            // 从 component.json 读取组件配置
            $jsonContent = file_get_contents($componentJsonFile);
            $jsonConfig = json_decode($jsonContent, true);
            
            if ($jsonConfig && isset($jsonConfig['components'])) {
                foreach ($jsonConfig['components'] as $code => $config) {
                    $result['scanned']++;
                    
                    // 组件文件路径
                    $componentFile = $config['file'] ?? ($code . '.phtml');
                    $filePath = $componentsDir . $componentFile;
                    
                    if (!file_exists($filePath)) {
                        $result['errors'][] = "Component file not found: {$componentFile}";
                        continue;
                    }
                    
                    try {
                        // 获取组件分类（优先使用 region，回退到 category）
                        $category = $config['region'] ?? $config['category'] ?? self::CATEGORY_CONTENT;
                        // 使用统一格式生成组件代码：模板名_类型_名字
                        $componentCode = self::generateComponentCode($styleCode, $category, $code);
                        
                        $registerResult = self::registerComponentFromJson(
                            $styleCode,
                            $componentCode,
                            $config,
                            $filePath,
                            $jsonConfig['regions'] ?? []
                        );
                        if ($registerResult['created']) {
                            $result['registered']++;
                        } else {
                            $result['updated']++;
                        }
                    } catch (\Exception $e) {
                        $result['errors'][] = "Error registering {$code}: " . $e->getMessage();
                    }
                }
            }
        } elseif (is_dir($componentsDir)) {
            // 回退：扫描 components 目录下的所有 phtml 文件（包括子目录）
            $componentFiles = self::globRecursive($componentsDir . '**/*.phtml');
            
            foreach ($componentFiles as $filePath) {
                $result['scanned']++;
                // 从相对路径生成组件代码
                $relativePath = str_replace($componentsDir, '', $filePath);
                $relativePath = str_replace('\\', '/', $relativePath);
                $fileName = basename($relativePath, '.phtml');
                $dirName = dirname($relativePath);
                
                // 确定分类
                $category = self::CATEGORY_CONTENT;
                if (strpos($relativePath, 'header/') === 0) {
                    $category = self::CATEGORY_HEADER;
                } elseif (strpos($relativePath, 'footer/') === 0) {
                    $category = self::CATEGORY_FOOTER;
                }
                
                // 组件名称：使用目录名和文件名
                $componentName = ($dirName && $dirName !== '.') ? $dirName . '_' . $fileName : $fileName;
                // 使用统一格式生成组件代码：模板名_类型_名字
                $componentCode = self::generateComponentCode($styleCode, $category, $componentName);
                
                try {
                    $registerResult = self::registerComponentFromFile(
                        $styleCode,
                        $componentCode,
                        $filePath,
                        $category,
                        self::TYPE_SECTION,
                        false
                    );
                    if ($registerResult['created']) {
                        $result['registered']++;
                    } else {
                        $result['updated']++;
                    }
                } catch (\Exception $e) {
                    $result['errors'][] = "Error registering {$componentCode}: " . $e->getMessage();
                }
            }
        }
        
        // 3. 扫描 content.phtml 并尝试自动拆分组件
        // 注意：如果 component.json 存在且已定义组件，则跳过 content.phtml 解析
        // 因为 content.phtml 的锚点格式路径 (content.phtml#section) 与文件系统不兼容
        $contentFile = $basePath . 'content.phtml';
        $hasComponentJsonComponents = file_exists($componentJsonFile) && 
            isset($jsonConfig) && is_array($jsonConfig) &&
            isset($jsonConfig['components']) && 
            !empty($jsonConfig['components']);
        
        if (file_exists($contentFile) && !$hasComponentJsonComponents) {
            $result['scanned']++;
            $contentSections = self::parseContentSections($contentFile);
            
            foreach ($contentSections as $section) {
                // 获取分类，默认为 content
                $sectionCategory = $section['category'] ?? self::CATEGORY_CONTENT;
                // 使用统一格式生成组件代码：模板名_类型_名字
                $componentCode = self::generateComponentCode($styleCode, $sectionCategory, $section['code']);
                try {
                    // 注册从 content.phtml 解析出的组件
                    $registerResult = self::registerParsedSection(
                        $styleCode,
                        $componentCode,
                        $section
                    );
                    if ($registerResult['created']) {
                        $result['registered']++;
                    } else {
                        $result['updated']++;
                    }
                } catch (\Exception $e) {
                    $result['errors'][] = "Error registering content section {$section['code']}: " . $e->getMessage();
                }
            }
        }
        
        return $result;
    }
    
    /**
     * 从文件注册组件
     */
    private static function registerComponentFromFile(
        string $styleCode,
        string $componentCode,
        string $filePath,
        string $category,
        string $type,
        bool $isSystem
    ): array {
        $componentModel = \Weline\Framework\Manager\ObjectManager::getInstance(self::class);
        
        // 解析组件元数据
        $metadata = self::parseComponentMetadata($filePath);
        
        // 计算相对路径
        $basePath = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/';
        $relativePath = str_replace($basePath, '', $filePath);
        $relativePath = str_replace('\\', '/', $relativePath);
        
        // 检查是否已存在
        $existing = clone $componentModel;
        $existing->clear()
            ->where(self::fields_CODE, $componentCode)
            ->find()
            ->fetch();
        
        // 确保整数字段不为空
        $sortOrder = $metadata['sort_order'] ?? ($isSystem ? 0 : 10);
        if ($sortOrder === '' || $sortOrder === null) {
            $sortOrder = $isSystem ? 0 : 10;
        }
        
        $data = [
            self::fields_CODE => $componentCode,
            self::fields_NAME => $metadata['name'] ?? self::formatName($componentCode),
            self::fields_DESCRIPTION => $metadata['description'] ?? '',
            self::fields_STYLE_CODE => $styleCode,
            self::fields_CATEGORY => $metadata['category'] ?? $category,
            self::fields_TYPE => $metadata['type'] ?? $type,
            self::fields_PATH => $relativePath,
            self::fields_THUMBNAIL => $metadata['thumbnail'] ?? '',
            self::fields_CONFIG_SCHEMA => json_encode($metadata['config_schema'] ?? [], JSON_UNESCAPED_UNICODE),
            self::fields_DEFAULT_CONFIG => json_encode($metadata['default_config'] ?? [], JSON_UNESCAPED_UNICODE),
            self::fields_COMPATIBLE_STYLES => json_encode($metadata['compatible_styles'] ?? ['*'], JSON_UNESCAPED_UNICODE),
            self::fields_IS_SYSTEM => (int)($isSystem ? 1 : 0),
            self::fields_IS_ACTIVE => 1,
            self::fields_SORT_ORDER => (int)$sortOrder,
        ];
        
        if ($existing->getId()) {
            // 更新现有组件
            foreach ($data as $key => $value) {
                $existing->setData($key, $value);
            }
            $existing->save();
            return ['created' => false, 'component' => $existing];
        } else {
            // 创建新组件
            $newComponent = clone $componentModel;
            $newComponent->clearData();
            foreach ($data as $key => $value) {
                $newComponent->setData($key, $value);
            }
            $newComponent->save(true);
            return ['created' => true, 'component' => $newComponent];
        }
    }
    
    /**
     * 从 component.json 配置注册组件
     * 
     * @param string $styleCode 模板代码
     * @param string $componentCode 组件代码
     * @param array $config 组件配置
     * @param string $filePath 组件文件路径
     * @param array $regions 区域配置
     * @return array
     */
    private static function registerComponentFromJson(
        string $styleCode,
        string $componentCode,
        array $config,
        string $filePath,
        array $regions = []
    ): array {
        // #region agent log
        $debugLog = function($msg, $data, $hyp) { @file_put_contents(BP . '.cursor/debug.log', json_encode(['location' => 'Component.php:registerComponentFromJson', 'message' => $msg, 'data' => $data, 'hypothesisId' => $hyp, 'timestamp' => microtime(true)]) . "\n", FILE_APPEND); };
        $debugLog('Entry', ['styleCode' => $styleCode, 'componentCode' => $componentCode], 'C');
        // #endregion
        
        $componentModel = \Weline\Framework\Manager\ObjectManager::getInstance(self::class);
        
        // 计算相对路径
        $basePath = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/';
        $relativePath = str_replace($basePath, '', $filePath);
        $relativePath = str_replace('\\', '/', $relativePath);
        
        // 从配置中获取分类（优先使用 region，回退到 category）
        $category = $config['region'] ?? $config['category'] ?? self::CATEGORY_CONTENT;
        
        // 检查是否已存在
        $existing = clone $componentModel;
        $existing->clear()
            ->where(self::fields_CODE, $componentCode)
            ->find()
            ->fetch();
        
        // 构建数据 - 确保整数字段不为空
        $sortOrder = $config['sort_order'] ?? 10;
        if ($sortOrder === '' || $sortOrder === null) {
            $sortOrder = 10;
        }
        $isSystem = ($config['is_default'] ?? false) ? 1 : 0;
        
        $data = [
            self::fields_CODE => $componentCode,
            self::fields_NAME => $config['name'] ?? self::formatName($componentCode),
            self::fields_DESCRIPTION => $config['description'] ?? '',
            self::fields_STYLE_CODE => $styleCode,
            self::fields_CATEGORY => $category,
            self::fields_TYPE => $config['type'] ?? self::TYPE_SECTION,
            self::fields_PATH => $relativePath,
            self::fields_THUMBNAIL => $config['thumbnail'] ?? '',
            self::fields_CONFIG_SCHEMA => json_encode($config['config_groups'] ?? [], JSON_UNESCAPED_UNICODE),
            self::fields_DEFAULT_CONFIG => json_encode($config['default_config'] ?? [], JSON_UNESCAPED_UNICODE),
            self::fields_COMPATIBLE_STYLES => json_encode($config['compatible_styles'] ?? ['*'], JSON_UNESCAPED_UNICODE),
            self::fields_IS_SYSTEM => (int)$isSystem,
            self::fields_IS_ACTIVE => 1,
            self::fields_SORT_ORDER => (int)$sortOrder,
        ];
        
        // #region agent log
        $debugLog('Data prepared', ['data_keys' => array_keys($data), 'sort_order' => $data[self::fields_SORT_ORDER], 'is_system' => $data[self::fields_IS_SYSTEM], 'is_active' => $data[self::fields_IS_ACTIVE]], 'C');
        // #endregion
        
        // 额外存储 region 信息到 config_schema
        if (isset($config['region'])) {
            $configSchema = [
                'region' => $config['region'],
                'config_groups' => $config['config_groups'] ?? [],
            ];
            $data[self::fields_CONFIG_SCHEMA] = json_encode($configSchema, JSON_UNESCAPED_UNICODE);
        }
        
        if ($existing->getId()) {
            // #region agent log
            $debugLog('Updating existing', ['existingId' => $existing->getId()], 'D');
            // #endregion
            // 更新现有组件
            foreach ($data as $key => $value) {
                $existing->setData($key, $value);
            }
            $existing->save();
            return ['created' => false, 'component' => $existing];
        } else {
            // #region agent log
            $debugLog('Creating new', ['componentCode' => $componentCode], 'D');
            // #endregion
            // 创建新组件
            $newComponent = clone $componentModel;
            $newComponent->clearData();
            foreach ($data as $key => $value) {
                $newComponent->setData($key, $value);
            }
            $newComponent->save(true);
            return ['created' => true, 'component' => $newComponent];
        }
    }
    
    /**
     * 递归扫描目录获取文件
     * 
     * @param string $pattern glob 模式
     * @return array 文件列表
     */
    private static function globRecursive(string $pattern): array
    {
        $files = [];
        
        // 将 ** 模式转换为递归扫描
        $basePath = dirname(dirname($pattern));
        $extension = pathinfo($pattern, PATHINFO_EXTENSION);
        
        if (!is_dir($basePath)) {
            return $files;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === $extension) {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }
    
    /**
     * 解析组件文件中的元数据
     * 
     * 支持格式：
     * @component_start
     * name => 组件名称
     * description => 组件描述
     * category => content
     * type => section
     * thumbnail => path/to/thumbnail.png
     * compatible_styles => jion-landing, market-mastery
     * sort_order => 10
     * @component_end
     */
    private static function parseComponentMetadata(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }
        
        $content = file_get_contents($filePath);
        $metadata = [];
        
        // 查找 @component_start ... @component_end 块
        if (preg_match('/@component_start(.*?)@component_end/s', $content, $matches)) {
            $metaBlock = $matches[1];
            $lines = explode("\n", $metaBlock);
            
            foreach ($lines as $line) {
                $line = trim($line);
                $line = preg_replace('/^\*\s*/', '', $line);
                $line = trim($line);
                
                if (empty($line) || strpos($line, '=>') === false) {
                    continue;
                }
                
                $parts = explode('=>', $line, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $value = trim($parts[1]);
                    
                    // 处理数组类型的值
                    if ($key === 'compatible_styles') {
                        $metadata[$key] = array_map('trim', explode(',', $value));
                    } elseif ($key === 'sort_order') {
                        $metadata[$key] = intval($value);
                    } else {
                        $metadata[$key] = $value;
                    }
                }
            }
        }
        
        // 同时解析 @fields_start ... @fields_end 作为配置定义
        if (preg_match('/@fields_start(.*?)@fields_end/s', $content, $matches)) {
            $metadata['config_schema'] = self::parseFieldsDefinition($matches[1]);
        }
        
        return $metadata;
    }
    
    /**
     * 解析字段定义
     */
    private static function parseFieldsDefinition(string $fieldsBlock): array
    {
        $schema = [];
        $lines = explode("\n", $fieldsBlock);
        $currentGroup = null;
        
        foreach ($lines as $line) {
            $line = trim($line);
            $line = preg_replace('/^\*\s*/', '', $line);
            $line = trim($line);
            
            if (empty($line)) {
                continue;
            }
            
            // 解析分组
            if (preg_match('/^group:([a-zA-Z0-9_-]+)\s*=>\s*(.+)$/', $line, $groupMatch)) {
                $currentGroup = trim($groupMatch[1]);
                $groupLabel = trim($groupMatch[2]);
                $schema['groups'][$currentGroup] = [
                    'key' => $currentGroup,
                    'label' => $groupLabel,
                ];
                continue;
            }
            
            // 解析字段
            if (preg_match('/^([a-zA-Z0-9._-]+)\s*=>\s*(.+)$/', $line, $fieldMatch)) {
                $fieldKey = trim($fieldMatch[1]);
                $fieldDef = trim($fieldMatch[2]);
                
                // 解析字段定义
                $parts = explode(':', $fieldDef);
                if (count($parts) >= 2) {
                    $schema['fields'][$fieldKey] = [
                        'key' => $fieldKey,
                        'label' => trim($parts[0]),
                        'type' => trim($parts[1]),
                        'default' => isset($parts[2]) ? trim($parts[2]) : '',
                        'group' => $currentGroup,
                    ];
                }
            }
        }
        
        return $schema;
    }
    
    /**
     * 解析 content.phtml 中的区块定义
     * 
     * 通过 @section_start 和 @section_end 标记来识别可拆分的区块
     */
    private static function parseContentSections(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }
        
        $content = file_get_contents($filePath);
        $sections = [];
        
        // 查找所有 group 定义，每个 group 可以作为一个独立的组件
        if (preg_match_all('/group:([a-zA-Z0-9_-]+)\s*=>\s*([^\n]+)/m', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $index => $match) {
                $groupCode = trim($match[1]);
                $groupLabel = trim($match[2]);
                
                // 提取分组标签（去掉可能的附加信息）
                $labelParts = explode(':', $groupLabel);
                $name = trim($labelParts[0]);
                
                $sections[] = [
                    'code' => $groupCode,
                    'name' => $name,
                    'description' => $groupLabel,
                    'category' => self::CATEGORY_CONTENT,
                    'type' => self::TYPE_SECTION,
                    'sort_order' => ($index + 1) * 10,
                ];
            }
        }
        
        return $sections;
    }
    
    /**
     * 注册从 content.phtml 解析出的区块
     */
    private static function registerParsedSection(string $styleCode, string $componentCode, array $section): array
    {
        $componentModel = \Weline\Framework\Manager\ObjectManager::getInstance(self::class);
        
        // 检查是否已存在
        $existing = clone $componentModel;
        $existing->clear()
            ->where(self::fields_CODE, $componentCode)
            ->find()
            ->fetch();
        
        $data = [
            self::fields_CODE => $componentCode,
            self::fields_NAME => $section['name'],
            self::fields_DESCRIPTION => $section['description'] ?? '',
            self::fields_STYLE_CODE => $styleCode,
            self::fields_CATEGORY => $section['category'] ?? self::CATEGORY_CONTENT,
            self::fields_TYPE => $section['type'] ?? self::TYPE_SECTION,
            self::fields_PATH => 'style/' . $styleCode . '/content.phtml#' . $section['code'], // 带锚点标记
            self::fields_COMPATIBLE_STYLES => json_encode(['*'], JSON_UNESCAPED_UNICODE),
            self::fields_IS_SYSTEM => 0,
            self::fields_IS_ACTIVE => 1,
            self::fields_SORT_ORDER => $section['sort_order'] ?? 10,
        ];
        
        if ($existing->getId()) {
            foreach ($data as $key => $value) {
                $existing->setData($key, $value);
            }
            $existing->save();
            return ['created' => false, 'component' => $existing];
        } else {
            $newComponent = clone $componentModel;
            $newComponent->clearData();
            foreach ($data as $key => $value) {
                $newComponent->setData($key, $value);
            }
            $newComponent->save(true);
            return ['created' => true, 'component' => $newComponent];
        }
    }
    
    /**
     * 格式化组件名称
     */
    private static function formatName(string $code): string
    {
        // 移除模板前缀
        $name = preg_replace('/^[a-zA-Z0-9_-]+-/', '', $code);
        // 转换连字符为空格
        $name = str_replace(['-', '_'], ' ', $name);
        // 首字母大写
        return ucwords($name);
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }
        
        $setup->createTable('页面构建器-组件表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '组件ID'
            )
            ->addColumn(
                self::fields_CODE,
                TableInterface::column_type_VARCHAR,
                100,
                'not null unique',
                '组件代码(唯一标识)'
            )
            ->addColumn(
                self::fields_NAME,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '组件名称'
            )
            ->addColumn(
                self::fields_DESCRIPTION,
                TableInterface::column_type_TEXT,
                0,
                '',
                '组件描述'
            )
            ->addColumn(
                self::fields_STYLE_CODE,
                TableInterface::column_type_VARCHAR,
                100,
                'not null',
                '所属模板代码'
            )
            ->addColumn(
                self::fields_CATEGORY,
                TableInterface::column_type_VARCHAR,
                50,
                'not null default "content"',
                '组件分类：header/footer/content/widget'
            )
            ->addColumn(
                self::fields_TYPE,
                TableInterface::column_type_VARCHAR,
                50,
                'not null default "section"',
                '组件类型：section/widget/layout/system'
            )
            ->addColumn(
                self::fields_PATH,
                TableInterface::column_type_VARCHAR,
                500,
                'not null',
                '组件文件路径'
            )
            ->addColumn(
                self::fields_THUMBNAIL,
                TableInterface::column_type_VARCHAR,
                500,
                '',
                '组件缩略图路径'
            )
            ->addColumn(
                self::fields_CONFIG_SCHEMA,
                TableInterface::column_type_TEXT,
                0,
                '',
                '配置项定义(JSON)'
            )
            ->addColumn(
                self::fields_DEFAULT_CONFIG,
                TableInterface::column_type_TEXT,
                0,
                '',
                '默认配置(JSON)'
            )
            ->addColumn(
                self::fields_COMPATIBLE_STYLES,
                TableInterface::column_type_TEXT,
                0,
                '',
                '兼容的模板列表(JSON)'
            )
            ->addColumn(
                self::fields_DEPENDENCIES,
                TableInterface::column_type_TEXT,
                0,
                '',
                '依赖的组件(JSON)'
            )
            ->addColumn(
                self::fields_IS_ACTIVE,
                TableInterface::column_type_SMALLINT,
                1,
                'not null default 1',
                '是否启用'
            )
            ->addColumn(
                self::fields_IS_SYSTEM,
                TableInterface::column_type_SMALLINT,
                1,
                'not null default 0',
                '是否系统组件'
            )
            ->addColumn(
                self::fields_SORT_ORDER,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 10',
                '排序'
            )
            ->addColumn(
                self::fields_CREATE_TIME,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP',
                '创建时间'
            )
            ->addColumn(
                self::fields_UPDATE_TIME,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP',
                '更新时间'
            )
            ->addIndex(TableInterface::index_type_KEY, 'idx_code', [self::fields_CODE], '组件代码索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_style_code', [self::fields_STYLE_CODE], '模板代码索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_category', [self::fields_CATEGORY], '分类索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', [self::fields_IS_ACTIVE], '状态索引')
            ->create();
    }

    /**
     * 升级表结构
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 预留升级逻辑
    }

    /**
     * 设置表结构
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
}
