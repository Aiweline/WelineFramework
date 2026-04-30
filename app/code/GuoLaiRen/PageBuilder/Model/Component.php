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
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
#[Table(comment: '页面构建器-组件表')]
#[Index(name: 'idx_code', columns: ['code'], comment: '组件代码索引')]
#[Index(name: 'idx_style_code', columns: ['style_code'], comment: '模板代码索引')]
#[Index(name: 'idx_category', columns: ['category'], comment: '分类索引')]
#[Index(name: 'idx_is_active', columns: ['is_active'], comment: '状态索引')]
#[Index(name: 'idx_is_ai_generated', columns: ['is_ai_generated'], comment: 'AI组件索引')]
class Component extends Model
{
    public const schema_table = 'guolairen_page_builder_component';
    public const schema_primary_key = 'component_id';
    /**
     * 标志：是否正在同步实体文件（防止递归）
     */
    private bool $syncingEntityFile = false;
    // 字段定义
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '组件ID')]
    public const schema_fields_ID = 'component_id';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: '组件代码（唯一标识）')]
    public const schema_fields_CODE = 'code';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '组件名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: '组件描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '所属模板代码')]
    public const schema_fields_STYLE_CODE = 'style_code';
    #[Col(type: 'varchar', length: 32, nullable: false, comment: '组件分类：header, footer, content')]
    public const schema_fields_CATEGORY = 'category';
    #[Col(type: 'varchar', length: 32, nullable: false, comment: '组件类型：section, widget, layout')]
    public const schema_fields_TYPE = 'type';
    #[Col(type: 'varchar', length: 512, nullable: true, comment: '组件文件路径')]
    public const schema_fields_PATH = 'path';
    #[Col(type: 'varchar', length: 512, nullable: true, comment: '组件缩略图')]
    public const schema_fields_THUMBNAIL = 'thumbnail';
    #[Col(type: 'text', nullable: true, comment: '配置项定义（JSON）')]
    public const schema_fields_CONFIG_SCHEMA = 'config_schema';
    #[Col(type: 'text', nullable: true, comment: '默认配置（JSON）')]
    public const schema_fields_DEFAULT_CONFIG = 'default_config';
    #[Col(type: 'text', nullable: true, comment: '兼容的模板列表（JSON）')]
    public const schema_fields_COMPATIBLE_STYLES = 'compatible_styles';
    #[Col(type: 'text', nullable: true, comment: '依赖的其他组件（JSON）')]
    public const schema_fields_DEPENDENCIES = 'dependencies';
    #[Col(type: 'int', nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '是否系统组件')]
    public const schema_fields_IS_SYSTEM = 'is_system';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '是否 AI 生成')]
    public const schema_fields_IS_AI_GENERATED = 'is_ai_generated';
    #[Col(type: 'text', nullable: true, comment: 'AI 生成时的用户提示')]
    public const schema_fields_AI_PROMPT = 'ai_prompt';
    #[Col(type: 'varchar', length: 32, nullable: true, comment: 'AI 生成版本号')]
    public const schema_fields_AI_VERSION = 'ai_version';
    #[Col(type: 'mediumtext', nullable: true, comment: '组件模板内容（AI 组件存储）')]
    public const schema_fields_TEMPLATE_CONTENT = 'template_content';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: '实体文件内容哈希')]
    public const schema_fields_ENTITY_FILE_HASH = 'entity_file_hash';
    #[Col(type: 'datetime', nullable: true, comment: '实体文件生成时间')]
    public const schema_fields_ENTITY_GENERATED_AT = 'entity_generated_at';
    
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
    
    // AI 组件样式代码
    public const STYLE_CODE_AI_GENERATED = '_ai_generated';
    
    /**
     * 检查是否是 AI 生成的组件
     */
    public function isAIGenerated(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_AI_GENERATED);
    }
    
    /**
     * 设置为 AI 生成组件
     */
    public function setAIGenerated(bool $isAI = true): self
    {
        return $this->setData(self::schema_fields_IS_AI_GENERATED, $isAI ? 1 : 0);
    }
    
    /**
     * 获取 AI 提示词
     */
    public function getAIPrompt(): string
    {
        return $this->getData(self::schema_fields_AI_PROMPT) ?: '';
    }
    
    /**
     * 设置 AI 提示词
     */
    public function setAIPrompt(string $prompt): self
    {
        return $this->setData(self::schema_fields_AI_PROMPT, $prompt);
    }
    
    /**
     * 获取模板内容（用于 AI 组件）
     */
    public function getTemplateContent(): string
    {
        return $this->getData(self::schema_fields_TEMPLATE_CONTENT) ?: '';
    }
    
    /**
     * 设置模板内容
     */
    public function setTemplateContent(string $content): self
    {
        return $this->setData(self::schema_fields_TEMPLATE_CONTENT, $content);
    }
    
    /**
     * 获取实体文件哈希
     */
    public function getEntityFileHash(): string
    {
        return $this->getData(self::schema_fields_ENTITY_FILE_HASH) ?: '';
    }
    
    /**
     * 设置实体文件哈希
     */
    public function setEntityFileHash(string $hash): self
    {
        return $this->setData(self::schema_fields_ENTITY_FILE_HASH, $hash);
    }
    
    /**
     * 检查实体文件是否需要更新
     * 
     * @param string $currentContentHash 当前内容的哈希
     * @return bool
     */
    public function needsEntityFileUpdate(string $currentContentHash): bool
    {
        $storedHash = $this->getEntityFileHash();
        return empty($storedHash) || $storedHash !== $currentContentHash;
    }
    
    /**
     * 生成 AI 组件代码
     * 
     * @param string $category 组件分类
     * @param string|null $name 可选的描述性名称
     * @return string 组件代码
     */
    public static function generateAIComponentCode(string $category, ?string $name = null): string
    {
        if ($name) {
            // 清理名称：只保留字母数字和破折号
            $cleanName = preg_replace('/[^a-zA-Z0-9\-]/', '', str_replace(' ', '-', strtolower($name)));
            if (!empty($cleanName)) {
                return strtolower($category) . '-' . $cleanName;
            }
        }
        
        // 使用时间戳格式：{category}-ai-{yymmddHHMM}
        return strtolower($category) . '-ai-' . date('ymdHi');
    }
    
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
    /**
     * 生成标准化的组件代码
     * 
     * 新规范：使用简单格式 {category}-{name}，不带模板前缀
     * 模板区分通过 style_code 字段实现
     * 
     * @param string $styleCode 模板代码（保留参数以保持兼容，实际不使用）
     * @param string $category 组件分类
     * @param string $name 组件名称（可以是 component.json 中的 key，如 header-nav）
     * @return string 标准化的组件代码
     */
    public static function generateComponentCode(string $styleCode, string $category, string $name): string
    {
        // 如果名称已经是标准格式（包含破折号），直接返回小写版本
        if (strpos($name, '-') !== false && strpos($name, '_') === false) {
            return strtolower($name);
        }
        
        // 清理组件名称：移除路径分隔符，统一为破折号格式
        $cleanName = str_replace(['/', '\\', '_'], '-', $name);
        // 移除多余的破折号
        $cleanName = preg_replace('/-+/', '-', $cleanName);
        $cleanName = trim($cleanName, '-');
        
        // 如果名称以 category 开头，移除重复的前缀
        // 例如：header-nav -> nav (当 category=header 时)
        $categoryPrefix = strtolower($category) . '-';
        if (stripos($cleanName, $categoryPrefix) === 0) {
            $cleanName = substr($cleanName, strlen($categoryPrefix));
        }
        
        // 如果名称等于 category，保持原名
        if (strtolower($cleanName) === strtolower($category)) {
            $cleanName = $category;
        }
        
        // 如果名称为空，使用 category 作为名称
        if (empty($cleanName)) {
            $cleanName = $category;
        }
        
        // 生成组件代码：{category}-{name}（不带模板前缀）
        return strtolower($category . '-' . $cleanName);
    }
    
    /**
     * 直接使用 component.json 中的组件代码
     * 
     * 这是推荐的方式：直接使用 component.json 中定义的 key 作为组件代码
     * 
     * @param string $code component.json 中的组件代码
     * @return string 标准化的组件代码
     */
    public static function normalizeComponentCode(string $code): string
    {
        // 如果已经是标准格式，直接返回
        if (strpos($code, '-') !== false && strpos($code, '_') === false) {
            return strtolower($code);
        }
        
        // 转换下划线为破折号
        return strtolower(str_replace('_', '-', $code));
    }
    
    /**
     * 获取组件的配置定义
     */
    public function getConfigSchema(): array
    {
        $schema = $this->getData(self::schema_fields_CONFIG_SCHEMA);
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
        return $this->setData(self::schema_fields_CONFIG_SCHEMA, json_encode($schema, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 获取组件的 slots 定义
     * 
     * Slots 定义了组件内部可以嵌套子组件的位置
     * 
     * @return array slots 配置数组
     *   [
     *     'slot_name' => [
     *       'name' => '显示名称',
     *       'accepts' => ['content', 'widget'],  // 可接受的组件类别
     *       'slot_type' => 'filters-header',     // slot 类型标识
     *       'max' => 10                          // 最大组件数
     *     ]
     *   ]
     */
    public function getSlots(): array
    {
        $configSchema = $this->getConfigSchema();
        return $configSchema['slots'] ?? [];
    }
    
    /**
     * 设置组件的 slots 定义
     */
    public function setSlots(array $slots): self
    {
        $configSchema = $this->getConfigSchema();
        $configSchema['slots'] = $slots;
        return $this->setConfigSchema($configSchema);
    }
    
    /**
     * 检查组件是否是容器（有 slots 定义）
     */
    public function isContainer(): bool
    {
        return !empty($this->getSlots());
    }
    
    /**
     * 获取组件可放置的区域列表
     * 
     * @return array 可放置的区域，如 ['header'], ['content'], ['footer']
     */
    public function getPlaceableIn(): array
    {
        $configSchema = $this->getConfigSchema();
        
        // 优先使用 config_schema 中的 placeable_in
        if (!empty($configSchema['placeable_in'])) {
            return $configSchema['placeable_in'];
        }
        
        // 回退到 region
        if (!empty($configSchema['region'])) {
            return [$configSchema['region']];
        }
        
        // 最后回退到 category
        $category = $this->getData(self::schema_fields_CATEGORY) ?: self::CATEGORY_CONTENT;
        return [$category];
    }
    
    /**
     * 设置组件可放置的区域
     */
    public function setPlaceableIn(array $regions): self
    {
        $configSchema = $this->getConfigSchema();
        $configSchema['placeable_in'] = $regions;
        return $this->setConfigSchema($configSchema);
    }
    
    /**
     * 获取组件兼容的 slot 类型列表
     * 
     * @return array slot 类型列表，['*'] 表示兼容所有 slot
     */
    public function getCompatibleSlotTypes(): array
    {
        $configSchema = $this->getConfigSchema();
        return $configSchema['compatible_slot_types'] ?? ['*'];
    }
    
    /**
     * 设置组件兼容的 slot 类型
     */
    public function setCompatibleSlotTypes(array $types): self
    {
        $configSchema = $this->getConfigSchema();
        $configSchema['compatible_slot_types'] = $types;
        return $this->setConfigSchema($configSchema);
    }
    
    /**
     * 检查组件是否可以放置在指定区域
     * 
     * @param string $region 目标区域
     * @return bool
     */
    public function canPlaceIn(string $region): bool
    {
        return in_array($region, $this->getPlaceableIn());
    }
    
    /**
     * 检查组件是否兼容指定的 slot 类型
     * 
     * @param string $slotType slot 类型
     * @return bool
     */
    public function isCompatibleWithSlotType(string $slotType): bool
    {
        $compatibleTypes = $this->getCompatibleSlotTypes();
        return in_array('*', $compatibleTypes) || in_array($slotType, $compatibleTypes);
    }
    
    /**
     * 检查指定 slot 是否接受某个组件类别
     * 
     * @param string $slotName slot 名称
     * @param string $category 组件类别
     * @return bool
     */
    public function slotAccepts(string $slotName, string $category): bool
    {
        $slots = $this->getSlots();
        if (!isset($slots[$slotName])) {
            return false;
        }
        
        $slotConfig = $slots[$slotName];
        $accepts = $slotConfig['accepts'] ?? [];
        
        // 如果没有定义 accepts，默认接受所有
        if (empty($accepts)) {
            return true;
        }
        
        return in_array($category, $accepts);
    }
    
    /**
     * 获取组件的区域（region）
     * 
     * @return string 区域名称
     */
    public function getRegion(): string
    {
        $configSchema = $this->getConfigSchema();
        return $configSchema['region'] ?? $this->getData(self::schema_fields_CATEGORY) ?? self::CATEGORY_CONTENT;
    }
    
    /**
     * 设置组件的区域
     */
    public function setRegion(string $region): self
    {
        $configSchema = $this->getConfigSchema();
        $configSchema['region'] = $region;
        return $this->setConfigSchema($configSchema);
    }
    
    /**
     * 获取默认配置
     */
    public function getDefaultConfig(): array
    {
        $config = $this->getData(self::schema_fields_DEFAULT_CONFIG);
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
        return $this->setData(self::schema_fields_DEFAULT_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 获取兼容的模板列表
     */
    public function getCompatibleStyles(): array
    {
        $styles = $this->getData(self::schema_fields_COMPATIBLE_STYLES);
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
        $path = $this->getData(self::schema_fields_PATH);
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
            return '<!-- Component file not found: ' . htmlspecialchars($this->getData(self::schema_fields_CODE)) . ' -->';
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
        $componentModel = \Weline\Framework\Manager\ObjectManager::getInstance(self::class);
        
        // 获取属于该模板的组件
        $ownComponents = clone $componentModel;
        $query = $ownComponents->clear()
            ->where(self::schema_fields_STYLE_CODE, $styleCode);
        
        if ($activeOnly) {
            $query->where(self::schema_fields_IS_ACTIVE, 1);
        }
        
        $result = [
            'own' => $query->order(self::schema_fields_SORT_ORDER, 'ASC')
                ->select()
                ->fetch()
                ->getItems(),
            'compatible' => [],
        ];
        
        // 如果需要包含兼容的组件
        if ($includeCompatible) {
            $allComponents = clone $componentModel;
            $allQuery = $allComponents->clear()
                ->where(self::schema_fields_STYLE_CODE, $styleCode, '!=');
            
            if ($activeOnly) {
                $allQuery->where(self::schema_fields_IS_ACTIVE, 1);
            }
            
            $allItems = $allQuery->order(self::schema_fields_STYLE_CODE, 'ASC')
                ->order(self::schema_fields_SORT_ORDER, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            
            // 过滤出兼容的组件
            foreach ($allItems as $component) {
                if ($component->isCompatibleWith($styleCode)) {
                    $componentStyleCode = $component->getData(self::schema_fields_STYLE_CODE);
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
            ->where(self::schema_fields_STYLE_CODE, $styleCode)
            ->select()
            ->fetch()
            ->getItems();
        $result['cleaned'] = count($toDeleteItems);
        
        // 使用批量删除
        if ($result['cleaned'] > 0) {
            $deleteQuery = clone $componentModel;
            $deleteQuery->clear()
                ->where(self::schema_fields_STYLE_CODE, $styleCode)
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
                    // 系统组件代码：{category}-system（不带模板前缀）
                    $componentCode = $category . '-system';
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
                        // 直接使用 component.json 中的组件代码（新规范：不带模板前缀）
                        $componentCode = self::normalizeComponentCode($code);
                        
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
                $componentName = ($dirName && $dirName !== '.') ? $dirName . '-' . $fileName : $fileName;
                // 生成标准组件代码：{category}-{name}（不带模板前缀）
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
        
        // 检查是否已存在（使用 code + style_code 作为唯一键）
        $existing = clone $componentModel;
        $existing->clear()
            ->where(self::schema_fields_CODE, $componentCode)
            ->where(self::schema_fields_STYLE_CODE, $styleCode)
            ->find()
            ->fetch();
        
        // 确保整数字段不为空
        $sortOrder = $metadata['sort_order'] ?? ($isSystem ? 0 : 10);
        if ($sortOrder === '' || $sortOrder === null) {
            $sortOrder = $isSystem ? 0 : 10;
        }
        
        // 检查是否是 AI 生成的组件
        // 条件1: 来自 _ai_generated 模板目录
        // 条件2: 组件代码包含 -ai- 模式（如 header-ai-2602081802）
        $isAiGenerated = ($styleCode === self::STYLE_CODE_AI_GENERATED) 
            || (bool)preg_match('/-ai-\d+$/', $componentCode);
        
        $data = [
            self::schema_fields_CODE => $componentCode,
            self::schema_fields_NAME => $metadata['name'] ?? self::formatName($componentCode),
            self::schema_fields_DESCRIPTION => $metadata['description'] ?? '',
            self::schema_fields_STYLE_CODE => $styleCode,
            self::schema_fields_CATEGORY => $metadata['category'] ?? $category,
            self::schema_fields_TYPE => $metadata['type'] ?? $type,
            self::schema_fields_PATH => $relativePath,
            self::schema_fields_THUMBNAIL => $metadata['thumbnail'] ?? '',
            self::schema_fields_CONFIG_SCHEMA => json_encode($metadata['config_schema'] ?? [], JSON_UNESCAPED_UNICODE),
            self::schema_fields_DEFAULT_CONFIG => json_encode($metadata['default_config'] ?? [], JSON_UNESCAPED_UNICODE),
            self::schema_fields_COMPATIBLE_STYLES => json_encode($metadata['compatible_styles'] ?? ['*'], JSON_UNESCAPED_UNICODE),
            self::schema_fields_IS_SYSTEM => (int)($isSystem ? 1 : 0),
            self::schema_fields_IS_ACTIVE => 1,
            self::schema_fields_SORT_ORDER => (int)$sortOrder,
            self::schema_fields_IS_AI_GENERATED => $isAiGenerated ? 1 : 0,
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
        $componentModel = \Weline\Framework\Manager\ObjectManager::getInstance(self::class);
        
        // 计算相对路径
        $basePath = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/';
        $relativePath = str_replace($basePath, '', $filePath);
        $relativePath = str_replace('\\', '/', $relativePath);
        
        // 从配置中获取分类（优先使用 region，回退到 category）
        $category = $config['region'] ?? $config['category'] ?? self::CATEGORY_CONTENT;
        
        // 检查是否已存在（使用 code + style_code 作为唯一键）
        $existing = clone $componentModel;
        $existing->clear()
            ->where(self::schema_fields_CODE, $componentCode)
            ->where(self::schema_fields_STYLE_CODE, $styleCode)
            ->find()
            ->fetch();
        
        // 构建数据 - 确保整数字段不为空
        $sortOrder = $config['sort_order'] ?? 10;
        if ($sortOrder === '' || $sortOrder === null) {
            $sortOrder = 10;
        }
        $isSystem = ($config['is_default'] ?? false) ? 1 : 0;
        
        // 检查是否是 AI 生成的组件
        // 条件1: 来自 _ai_generated 模板目录
        // 条件2: 组件代码包含 -ai- 模式（如 header-ai-2602081802）
        $isAiGenerated = ($styleCode === self::STYLE_CODE_AI_GENERATED) 
            || (bool)preg_match('/-ai-\d+$/', $componentCode);
        
        $data = [
            self::schema_fields_CODE => $componentCode,
            self::schema_fields_NAME => $config['name'] ?? self::formatName($componentCode),
            self::schema_fields_DESCRIPTION => $config['description'] ?? '',
            self::schema_fields_STYLE_CODE => $styleCode,
            self::schema_fields_CATEGORY => $category,
            self::schema_fields_TYPE => $config['type'] ?? self::TYPE_SECTION,
            self::schema_fields_PATH => $relativePath,
            self::schema_fields_THUMBNAIL => $config['thumbnail'] ?? '',
            self::schema_fields_CONFIG_SCHEMA => json_encode($config['config_groups'] ?? [], JSON_UNESCAPED_UNICODE),
            self::schema_fields_DEFAULT_CONFIG => json_encode($config['default_config'] ?? [], JSON_UNESCAPED_UNICODE),
            self::schema_fields_COMPATIBLE_STYLES => json_encode($config['compatible_styles'] ?? ['*'], JSON_UNESCAPED_UNICODE),
            self::schema_fields_IS_SYSTEM => (int)$isSystem,
            self::schema_fields_IS_ACTIVE => 1,
            self::schema_fields_SORT_ORDER => (int)$sortOrder,
            self::schema_fields_IS_AI_GENERATED => $isAiGenerated ? 1 : 0,
        ];
        
        // 额外存储 region 和 icon 信息到 config_schema
        $configSchema = [
            'config_groups' => $config['config_groups'] ?? [],
        ];
        if (isset($config['region'])) {
            $configSchema['region'] = $config['region'];
        }
        if (isset($config['icon'])) {
            $configSchema['icon'] = $config['icon'];
        }
        $data[self::schema_fields_CONFIG_SCHEMA] = json_encode($configSchema, JSON_UNESCAPED_UNICODE);
        
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
            ->where(self::schema_fields_CODE, $componentCode)
            ->find()
            ->fetch();
        
        // 检查是否是 AI 生成的组件
        // 条件1: 来自 _ai_generated 模板目录
        // 条件2: 组件代码包含 -ai- 模式（如 header-ai-2602081802）
        $isAiGenerated = ($styleCode === self::STYLE_CODE_AI_GENERATED) 
            || (bool)preg_match('/-ai-\d+$/', $componentCode);
        
        $data = [
            self::schema_fields_CODE => $componentCode,
            self::schema_fields_NAME => $section['name'],
            self::schema_fields_DESCRIPTION => $section['description'] ?? '',
            self::schema_fields_STYLE_CODE => $styleCode,
            self::schema_fields_CATEGORY => $section['category'] ?? self::CATEGORY_CONTENT,
            self::schema_fields_TYPE => $section['type'] ?? self::TYPE_SECTION,
            self::schema_fields_PATH => 'style/' . $styleCode . '/content.phtml#' . $section['code'], // 带锚点标记
            self::schema_fields_COMPATIBLE_STYLES => json_encode(['*'], JSON_UNESCAPED_UNICODE),
            self::schema_fields_IS_SYSTEM => 0,
            self::schema_fields_IS_ACTIVE => 1,
            self::schema_fields_SORT_ORDER => $section['sort_order'] ?? 10,
            self::schema_fields_IS_AI_GENERATED => $isAiGenerated ? 1 : 0,
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
     * 保存后钩子 - 自动同步 AI 组件的实体文件
     */
    public function save_after(): void
    {
        parent::save_after();
        
        // 如果是 AI 生成的组件，且模板内容不为空，自动同步实体文件
        // 使用实例标志防止递归保存
        if (!$this->syncingEntityFile && $this->isAIGenerated() && !empty($this->getTemplateContent())) {
            try {
                $entityFileManager = \Weline\Framework\Manager\ObjectManager::getInstance(
                    \GuoLaiRen\PageBuilder\Service\AI\EntityFileManager::class
                );
                
                // 检查是否需要更新
                if ($entityFileManager->needsUpdate($this)) {
                    $this->syncingEntityFile = true;
                    try {
                        // 同步实体文件（updateModel=true 会更新哈希和时间戳并保存）
                        // 但由于 syncingEntityFile 标志，不会再次进入此逻辑
                        $entityFileManager->syncEntityFile($this, true);
                        
                        // 更新 component.json
                        $entityFileManager->updateComponentJson();
                    } finally {
                        $this->syncingEntityFile = false;
                    }
                }
            } catch (\Exception $e) {
                // 记录错误但不中断保存流程
                w_log_error("[Component] Failed to sync AI component entity file: " . $e->getMessage());
                $this->syncingEntityFile = false;
            }
        }
    }
}
