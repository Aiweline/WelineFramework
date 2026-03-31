<?php

declare(strict_types=1);

/**
 * Slot 验证服务
 * 
 * 负责验证组件放置规则：
 * 1. 区域隔离：header 组件只能放 header 区域，footer 只能放 footer 区域
 * 2. Slot 接受规则：slot 只能接受其声明的组件类别
 * 3. Slot 类型匹配：组件的 compatible_slot_types 与 slot 的 slot_type 匹配
 * 4. 数量限制：slot 内组件数量不能超过 max 限制
 * 
 * @author GuoLaiRen
 * @since 2.0.0
 */

namespace GuoLaiRen\PageBuilder\Service\Component;

use GuoLaiRen\PageBuilder\Model\Component;
use GuoLaiRen\PageBuilder\Model\VirtualThemeComponent;
use GuoLaiRen\PageBuilder\Service\Theme\PageBuilderThemeComponentBridge;
use GuoLaiRen\PageBuilder\Service\Template\TemplatePathResolver;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Dto\ThemeComponentDefinition;

class SlotValidator
{
    private ?ComponentResolver $componentResolver = null;
    private ?TemplatePathResolver $pathResolver = null;
    
    /**
     * 区域可接受的组件类别映射
     */
    private const REGION_ACCEPTS = [
        'header' => ['header'],
        'content' => ['content', 'widget'],
        'footer' => ['footer'],
        'sidebar' => ['sidebar', 'widget'],
    ];
    
    /**
     * 单例实例
     */
    private static ?self $instance = null;
    
    /**
     * component.json 缓存
     */
    private static array $componentJsonCache = [];
    
    public function __construct(
        ?ComponentResolver $componentResolver = null,
        ?TemplatePathResolver $pathResolver = null
    ) {
        $this->componentResolver = $componentResolver;
        $this->pathResolver = $pathResolver;
    }
    
    /**
     * 获取 ComponentResolver（延迟加载）
     */
    private function getComponentResolver(): ComponentResolver
    {
        if ($this->componentResolver === null) {
            $this->componentResolver = ComponentResolver::getInstance();
        }
        return $this->componentResolver;
    }
    
    /**
     * 获取 TemplatePathResolver（延迟加载）
     */
    private function getPathResolver(): TemplatePathResolver
    {
        if ($this->pathResolver === null) {
            $this->pathResolver = TemplatePathResolver::getInstance();
        }
        return $this->pathResolver;
    }
    
    /**
     * 验证组件是否可以放置到目标位置
     * 
     * @param string $componentCode 要放置的组件代码
     * @param string $targetRegion 目标区域 (header/content/footer)
     * @param string $styleCode 模板代码
     * @param string|null $parentComponentCode 父组件代码（放入 slot 时）
     * @param string|null $targetSlot slot 名称（放入 slot 时）
     * @param string|null $parentInstanceId 父组件实例ID（用于检查数量限制）
     * @param int $welineThemeId 大于 0 时从未注册部件（Weline 主题虚拟部件）解析元数据
     * @param string $themeComponentArea frontend|backend
     * @return ValidationResult
     */
    public function canPlace(
        string $componentCode,
        string $targetRegion,
        string $styleCode,
        ?string $parentComponentCode = null,
        ?string $targetSlot = null,
        ?string $parentInstanceId = null,
        int $welineThemeId = 0,
        string $themeComponentArea = 'frontend'
    ): ValidationResult {
        // 获取组件信息（文件/DB + 可选 Weline 虚拟）
        $componentInfo = $this->resolvePlacementComponentInfo($componentCode, $styleCode, $welineThemeId, $themeComponentArea);
        if ($componentInfo === null) {
            return ValidationResult::fail(
                sprintf('组件 [%s] 不存在或未注册', $componentCode),
                'COMPONENT_NOT_FOUND'
            );
        }
        
        $category = $componentInfo['category'] ?? 'content';
        $placeableIn = $componentInfo['placeable_in'] ?? [$componentInfo['region'] ?? $category];
        
        // ========== 规则 1: 区域匹配 ==========
        // 组件只能放到其声明的 placeable_in 区域
        if (!in_array($targetRegion, $placeableIn)) {
            return ValidationResult::fail(
                sprintf(
                    '组件 [%s] 类别为 %s，只能放置在: %s，不能放到 %s 区域',
                    $componentCode,
                    $category,
                    implode(', ', $placeableIn),
                    $targetRegion
                ),
                'REGION_NOT_ALLOWED'
            );
        }
        
        // ========== 规则 2: Slot 放置验证 ==========
        if ($parentComponentCode && $targetSlot) {
            $parentInfo = $this->resolvePlacementComponentInfo($parentComponentCode, $styleCode, $welineThemeId, $themeComponentArea);
            if ($parentInfo === null) {
                return ValidationResult::fail(
                    sprintf('父组件 [%s] 不存在', $parentComponentCode),
                    'PARENT_NOT_FOUND'
                );
            }
            
            $parentSlots = $parentInfo['slots'] ?? [];
            
            // 2.1 检查父组件是否有 slots 定义
            if (empty($parentSlots)) {
                return ValidationResult::fail(
                    sprintf('组件 [%s] 不支持嵌套子组件（未定义 slots）', $parentComponentCode),
                    'NO_SLOTS_DEFINED'
                );
            }
            
            // 2.2 检查 slot 是否存在
            if (!isset($parentSlots[$targetSlot])) {
                return ValidationResult::fail(
                    sprintf(
                        '组件 [%s] 没有名为 [%s] 的 slot，可用的 slots: %s',
                        $parentComponentCode,
                        $targetSlot,
                        implode(', ', array_keys($parentSlots))
                    ),
                    'SLOT_NOT_FOUND'
                );
            }
            
            $slotConfig = $parentSlots[$targetSlot];
            
            // 2.3 检查 slot 是否接受此类别
            $slotAccepts = $slotConfig['accepts'] ?? [];
            if (!empty($slotAccepts) && !in_array($category, $slotAccepts)) {
                return ValidationResult::fail(
                    sprintf(
                        'Slot [%s] 只接受 %s 类别的组件，不接受 %s 类别',
                        $targetSlot,
                        implode('/', $slotAccepts),
                        $category
                    ),
                    'SLOT_CATEGORY_MISMATCH'
                );
            }
            
            // 2.4 关键规则：slot 的 accepts 必须是父组件所在区域的子集
            $parentRegion = $parentInfo['region'] ?? 'content';
            $regionAccepts = $this->getRegionAccepts($parentRegion);
            foreach ($slotAccepts as $acceptCategory) {
                if (!in_array($acceptCategory, $regionAccepts)) {
                    return ValidationResult::fail(
                        sprintf(
                            'Slot [%s] 配置错误：%s 区域不接受 %s 类别的组件',
                            $targetSlot,
                            $parentRegion,
                            $acceptCategory
                        ),
                        'SLOT_CONFIG_INVALID'
                    );
                }
            }
            
            // 2.5 检查 slot_type 匹配（如果组件定义了 compatible_slot_types）
            $slotType = $slotConfig['slot_type'] ?? null;
            $compatibleSlotTypes = $componentInfo['compatible_slot_types'] ?? ['*'];
            
            if ($slotType && !in_array('*', $compatibleSlotTypes) && !in_array($slotType, $compatibleSlotTypes)) {
                return ValidationResult::fail(
                    sprintf(
                        '组件 [%s] 不兼容 slot 类型 [%s]，兼容的类型: %s',
                        $componentCode,
                        $slotType,
                        implode(', ', $compatibleSlotTypes)
                    ),
                    'SLOT_TYPE_MISMATCH'
                );
            }
            
            // 2.6 检查 slot 数量限制（如果提供了 parentInstanceId）
            if ($parentInstanceId !== null) {
                $maxCount = $slotConfig['max'] ?? PHP_INT_MAX;
                $currentCount = $this->getSlotComponentCount($parentInstanceId, $targetSlot);
                
                if ($currentCount >= $maxCount) {
                    return ValidationResult::fail(
                        sprintf(
                            'Slot [%s] 最多只能放置 %d 个组件，当前已有 %d 个',
                            $targetSlot,
                            $maxCount,
                            $currentCount
                        ),
                        'SLOT_MAX_REACHED'
                    );
                }
            }
        }
        
        return ValidationResult::success();
    }
    
    /**
     * 验证组件是否可以放置到顶级区域（不进入 slot）
     * 
     * @param string $componentCode 组件代码
     * @param string $targetRegion 目标区域
     * @param string $styleCode 模板代码
     * @return ValidationResult
     */
    public function canPlaceInRegion(
        string $componentCode,
        string $targetRegion,
        string $styleCode,
        int $welineThemeId = 0,
        string $themeComponentArea = 'frontend'
    ): ValidationResult {
        return $this->canPlace($componentCode, $targetRegion, $styleCode, null, null, null, $welineThemeId, $themeComponentArea);
    }
    
    /**
     * 验证组件是否可以放置到另一个组件的 slot 中
     * 
     * @param string $componentCode 组件代码
     * @param string $parentComponentCode 父组件代码
     * @param string $targetSlot slot 名称
     * @param string $styleCode 模板代码
     * @param string|null $parentInstanceId 父组件实例ID
     * @return ValidationResult
     */
    public function canPlaceInSlot(
        string $componentCode,
        string $parentComponentCode,
        string $targetSlot,
        string $styleCode,
        ?string $parentInstanceId = null,
        int $welineThemeId = 0,
        string $themeComponentArea = 'frontend'
    ): ValidationResult {
        // 获取父组件的区域
        $parentInfo = $this->resolvePlacementComponentInfo($parentComponentCode, $styleCode, $welineThemeId, $themeComponentArea);
        if ($parentInfo === null) {
            return ValidationResult::fail(
                sprintf('父组件 [%s] 不存在', $parentComponentCode),
                'PARENT_NOT_FOUND'
            );
        }
        
        $parentRegion = $parentInfo['region'] ?? 'content';
        
        return $this->canPlace(
            $componentCode,
            $parentRegion,
            $styleCode,
            $parentComponentCode,
            $targetSlot,
            $parentInstanceId,
            $welineThemeId,
            $themeComponentArea
        );
    }
    
    /**
     * 获取区域可接受的组件类别
     * 
     * @param string $region 区域名称
     * @return array 可接受的类别列表
     */
    public function getRegionAccepts(string $region): array
    {
        return self::REGION_ACCEPTS[$region] ?? ['content', 'widget'];
    }
    
    /**
     * 获取组件信息（从 component.json 或数据库）
     * 
     * @param string $componentCode 组件代码
     * @param string $styleCode 模板代码
     * @return array|null 组件信息数组，包含 category, region, slots, placeable_in, compatible_slot_types
     */
    public function getComponentInfo(string $componentCode, string $styleCode): ?array
    {
        // 首先尝试从 component.json 获取（更完整的信息）
        $jsonConfig = $this->getComponentJsonConfig($styleCode);
        
        if ($jsonConfig && isset($jsonConfig['components'][$componentCode])) {
            $config = $jsonConfig['components'][$componentCode];
            return [
                'code' => $componentCode,
                'name' => $config['name'] ?? $componentCode,
                'region' => $config['region'] ?? $config['category'] ?? 'content',
                'category' => $config['category'] ?? $config['region'] ?? 'content',
                'type' => $config['type'] ?? 'section',
                'placeable_in' => $config['placeable_in'] ?? [$config['region'] ?? $config['category'] ?? 'content'],
                'slots' => $config['slots'] ?? [],
                'compatible_slot_types' => $config['compatible_slot_types'] ?? ['*'],
                'is_container' => !empty($config['slots']),
            ];
        }
        
        // 尝试从共享组件获取
        if ($styleCode !== '_shared') {
            $sharedConfig = $this->getComponentJsonConfig('_shared');
            if ($sharedConfig && isset($sharedConfig['components'][$componentCode])) {
                $config = $sharedConfig['components'][$componentCode];
                return [
                    'code' => $componentCode,
                    'name' => $config['name'] ?? $componentCode,
                    'region' => $config['region'] ?? $config['category'] ?? 'content',
                    'category' => $config['category'] ?? $config['region'] ?? 'content',
                    'type' => $config['type'] ?? 'section',
                    'placeable_in' => $config['placeable_in'] ?? [$config['region'] ?? $config['category'] ?? 'content'],
                    'slots' => $config['slots'] ?? [],
                    'compatible_slot_types' => $config['compatible_slot_types'] ?? ['*'],
                    'is_container' => !empty($config['slots']),
                ];
            }
        }
        
        // 回退到数据库查询
        $component = $this->getComponentResolver()->resolve($componentCode, $styleCode);
        if ($component) {
            $category = $component->getData(Component::schema_fields_CATEGORY) ?: 'content';
            $configSchema = $component->getConfigSchema();
            
            return [
                'code' => $componentCode,
                'name' => $component->getData(Component::schema_fields_NAME) ?: $componentCode,
                'region' => $configSchema['region'] ?? $category,
                'category' => $category,
                'type' => $component->getData(Component::schema_fields_TYPE) ?: 'section',
                'placeable_in' => $configSchema['placeable_in'] ?? [$category],
                'slots' => $configSchema['slots'] ?? [],
                'compatible_slot_types' => $configSchema['compatible_slot_types'] ?? ['*'],
                'is_container' => !empty($configSchema['slots']),
            ];
        }
        
        return null;
    }
    
    /**
     * 文件/DB 部件信息 + 可选 Weline 主题虚拟部件
     *
     * @return array<string, mixed>|null
     */
    public function resolvePlacementComponentInfo(
        string $componentCode,
        string $styleCode,
        int $welineThemeId = 0,
        string $themeComponentArea = 'frontend'
    ): ?array {
        $info = $this->getComponentInfo($componentCode, $styleCode);
        if ($info !== null) {
            return $info;
        }
        if ($welineThemeId > 0) {
            return $this->getVirtualThemeComponentInfo($componentCode, $welineThemeId, $themeComponentArea);
        }
        return null;
    }
    
    /**
     * 将 Weline ThemeComponentDefinition 转为 Slot 校验用的结构
     *
     * @return array<string, mixed>
     */
    private function themeDefinitionToSlotInfo(ThemeComponentDefinition $def): array
    {
        $normalizedRegions = [];
        foreach ($def->position as $p) {
            $p = strtolower((string) $p);
            if ($p === '' || $p === '*') {
                continue;
            }
            if (in_array($p, ['header', 'footer', 'content', 'sidebar', 'hero', 'cta'], true)) {
                $normalizedRegions[] = $p;
            }
        }
        if ($normalizedRegions === []) {
            $normalizedRegions = ['content'];
        }
        $placeableIn = $normalizedRegions;
        $primary = $normalizedRegions[0];
        $category = match (true) {
            $normalizedRegions === ['header'] => 'header',
            $normalizedRegions === ['footer'] => 'footer',
            in_array('sidebar', $normalizedRegions, true) => 'widget',
            default => in_array($def->category, ['header', 'footer', 'content', 'widget', 'sidebar'], true)
                ? $def->category
                : 'content',
        };
        $schema = $def->configSchema;
        $compatible = is_array($schema) ? ($schema['compatible_slot_types'] ?? ['*']) : ['*'];
        if (!is_array($compatible)) {
            $compatible = ['*'];
        }
        
        return [
            'code' => $def->code,
            'name' => $def->name,
            'region' => $primary,
            'category' => $category,
            'type' => 'section',
            'placeable_in' => $placeableIn,
            'slots' => is_array($def->slots) ? $def->slots : [],
            'compatible_slot_types' => $compatible,
            'is_container' => $def->isContainer || (is_array($def->slots) && $def->slots !== []),
        ];
    }
    
    /**
     * @return array<string, mixed>|null
     */
    private function getVirtualThemeComponentInfo(string $componentCode, int $welineThemeId, string $themeComponentArea): ?array
    {
        $area = strtolower($themeComponentArea) === 'backend' ? 'backend' : 'frontend';
        $bridge = ObjectManager::getInstance(PageBuilderThemeComponentBridge::class);
        $def = $bridge->resolveDefinition($componentCode, $welineThemeId, $area);
        if ($def === null) {
            return null;
        }
        
        return $this->themeDefinitionToSlotInfo($def);
    }
    
    /**
     * 获取组件的 slots 定义
     * 
     * @param string $componentCode 组件代码
     * @param string $styleCode 模板代码
     * @return array slots 定义
     */
    public function getComponentSlots(
        string $componentCode,
        string $styleCode,
        int $welineThemeId = 0,
        string $themeComponentArea = 'frontend'
    ): array
    {
        $info = $this->resolvePlacementComponentInfo($componentCode, $styleCode, $welineThemeId, $themeComponentArea);
        return $info['slots'] ?? [];
    }
    
    /**
     * 检查组件是否是容器（有 slots 定义）
     * 
     * @param string $componentCode 组件代码
     * @param string $styleCode 模板代码
     * @return bool
     */
    public function isContainer(
        string $componentCode,
        string $styleCode,
        int $welineThemeId = 0,
        string $themeComponentArea = 'frontend'
    ): bool
    {
        $info = $this->resolvePlacementComponentInfo($componentCode, $styleCode, $welineThemeId, $themeComponentArea);
        return $info !== null && !empty($info['slots']);
    }
    
    /**
     * 获取 slot 内当前组件数量
     * 
     * @param string $parentInstanceId 父组件实例ID
     * @param string $slotName slot 名称
     * @return int 当前数量
     */
    private function getSlotComponentCount(string $parentInstanceId, string $slotName): int
    {
        // TODO: 从布局配置中查询 slot 内的组件数量
        // 这需要访问 PageLayout 或 LayoutService
        // 暂时返回 0，后续实现
        return 0;
    }
    
    /**
     * 获取 component.json 配置
     * 
     * @param string $styleCode 模板代码
     * @return array|null
     */
    private function getComponentJsonConfig(string $styleCode): ?array
    {
        if (isset(self::$componentJsonCache[$styleCode])) {
            return self::$componentJsonCache[$styleCode];
        }
        
        $jsonPath = $this->getPathResolver()->getComponentJsonPath($styleCode);
        
        if (!file_exists($jsonPath)) {
            self::$componentJsonCache[$styleCode] = null;
            return null;
        }
        
        $content = file_get_contents($jsonPath);
        $config = json_decode($content, true);
        
        self::$componentJsonCache[$styleCode] = $config;
        return $config;
    }
    
    /**
     * 获取可放置到指定区域的组件列表
     * 
     * @param string $targetRegion 目标区域
     * @param string $styleCode 模板代码
     * @return array 兼容的组件代码列表
     */
    public function getCompatibleComponentsForRegion(
        string $targetRegion,
        string $styleCode,
        int $welineThemeId = 0,
        string $themeComponentArea = 'frontend'
    ): array
    {
        $seen = [];
        $jsonConfig = $this->getComponentJsonConfig($styleCode);
        $compatible = [];
        if ($jsonConfig && isset($jsonConfig['components'])) {
            foreach ($jsonConfig['components'] as $code => $config) {
                $placeableIn = $config['placeable_in'] ?? [$config['region'] ?? $config['category'] ?? 'content'];
                if (in_array($targetRegion, $placeableIn)) {
                    $compatible[] = $code;
                    $seen[$code] = true;
                }
            }
        }

        if ($welineThemeId > 0) {
            foreach ($this->listVirtualThemeComponentInfos($welineThemeId, $themeComponentArea) as $virtualInfo) {
                $code = (string) ($virtualInfo['code'] ?? '');
                if ($code === '' || isset($seen[$code])) {
                    continue;
                }
                $placeableIn = $virtualInfo['placeable_in'] ?? [$virtualInfo['region'] ?? 'content'];
                if (in_array($targetRegion, $placeableIn, true)) {
                    $compatible[] = $code;
                    $seen[$code] = true;
                }
            }
        }

        return $compatible;
    }
    
    /**
     * 获取可放置到指定 slot 的组件列表
     * 
     * @param string $parentComponentCode 父组件代码
     * @param string $targetSlot slot 名称
     * @param string $styleCode 模板代码
     * @return array 兼容的组件代码列表
     */
    public function getCompatibleComponentsForSlot(
        string $parentComponentCode,
        string $targetSlot,
        string $styleCode,
        int $welineThemeId = 0,
        string $themeComponentArea = 'frontend'
    ): array {
        $parentInfo = $this->resolvePlacementComponentInfo($parentComponentCode, $styleCode, $welineThemeId, $themeComponentArea);
        if (!$parentInfo || empty($parentInfo['slots'][$targetSlot])) {
            return [];
        }
        
        $slotConfig = $parentInfo['slots'][$targetSlot];
        $slotAccepts = $slotConfig['accepts'] ?? [];
        $slotType = $slotConfig['slot_type'] ?? null;
        
        $seen = [];
        $compatible = [];
        $jsonConfig = $this->getComponentJsonConfig($styleCode);
        if ($jsonConfig && isset($jsonConfig['components'])) {
            foreach ($jsonConfig['components'] as $code => $config) {
                $category = $config['category'] ?? $config['region'] ?? 'content';

                // 检查类别是否被接受
                if (!empty($slotAccepts) && !in_array($category, $slotAccepts, true)) {
                    continue;
                }

                // 检查 slot_type 匹配
                if ($slotType) {
                    $compatibleTypes = $config['compatible_slot_types'] ?? ['*'];
                    if (!in_array('*', $compatibleTypes, true) && !in_array($slotType, $compatibleTypes, true)) {
                        continue;
                    }
                }

                $compatible[] = $code;
                $seen[$code] = true;
            }
        }

        if ($welineThemeId > 0) {
            foreach ($this->listVirtualThemeComponentInfos($welineThemeId, $themeComponentArea) as $virtualInfo) {
                $code = (string) ($virtualInfo['code'] ?? '');
                if ($code === '' || isset($seen[$code])) {
                    continue;
                }
                $category = (string) ($virtualInfo['category'] ?? 'content');
                if (!empty($slotAccepts) && !in_array($category, $slotAccepts, true)) {
                    continue;
                }
                if ($slotType) {
                    $compatibleTypes = $virtualInfo['compatible_slot_types'] ?? ['*'];
                    if (!is_array($compatibleTypes)) {
                        $compatibleTypes = ['*'];
                    }
                    if (!in_array('*', $compatibleTypes, true) && !in_array($slotType, $compatibleTypes, true)) {
                        continue;
                    }
                }
                $compatible[] = $code;
                $seen[$code] = true;
            }
        }
        
        return $compatible;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listVirtualThemeComponentInfos(int $welineThemeId, string $themeComponentArea): array
    {
        if ($welineThemeId <= 0) {
            return [];
        }
        $area = strtolower($themeComponentArea) === 'backend' ? 'backend' : 'frontend';

        /** @var VirtualThemeComponent $componentModel */
        $componentModel = clone ObjectManager::getInstance(VirtualThemeComponent::class);
        $componentModel->clearData()->clearQuery();
        $components = $componentModel
            ->where(VirtualThemeComponent::schema_fields_VIRTUAL_THEME_ID, $welineThemeId)
            ->where(VirtualThemeComponent::schema_fields_AREA, $area)
            ->where(VirtualThemeComponent::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch()
            ->getItems();
        $rows = [];
        foreach ($components as $component) {
            if (!$component instanceof VirtualThemeComponent) {
                continue;
            }
            $meta = $component->getMeta();
            $positions = \is_array($meta['position'] ?? null) ? $meta['position'] : ['content'];
            $position = [];
            foreach ($positions as $p) {
                if (\is_string($p) && \trim($p) !== '') {
                    $position[] = $p;
                }
            }
            if ($position === []) {
                $position = ['content'];
            }
            $rows[] = $this->themeDefinitionToSlotInfo(new ThemeComponentDefinition(
                module: 'GuoLaiRen_PageBuilder',
                type: 'virtual_theme_component',
                code: (string)$component->getComponentCode(),
                name: (string)$component->getName(),
                description: '',
                area: $area,
                sourceType: 'virtual',
                category: (string)$component->getCategory(),
                renderMode: \Weline\Theme\Dto\ThemeRenderable::MODE_TEMPLATE_CONTENT,
                configSchema: [],
                defaultConfig: $component->getDefaultConfig(),
                meta: $meta,
                params: [],
                position: $position,
                pageLayouts: \is_array($meta['page_layouts'] ?? null) ? $meta['page_layouts'] : ['*'],
                slots: [],
                slot: null,
                exclusive: false,
                compatible: true,
                isContainer: false,
                isAiGenerated: $component->isAiGenerated(),
                icon: null,
                templatePath: null,
                templateContent: (string)$component->getTemplateContent(),
                blockClass: null,
                themeId: $welineThemeId,
                themePath: null,
                logicalKey: null,
                layerKey: null,
                componentId: (int)$component->getId(),
                versionId: $component->getPublishedVersionId() ?: null,
                sortOrder: (int)($meta['sort_order'] ?? 0),
            ));
        }
        return $rows;
    }
    
    /**
     * 清除缓存
     */
    public function clearCache(): void
    {
        self::$componentJsonCache = [];
    }
    
    /**
     * 获取实例（单例模式）
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}

/**
 * 验证结果类
 */
class ValidationResult
{
    private bool $valid;
    private string $message;
    private string $errorCode;
    private array $data;
    
    private function __construct(bool $valid, string $message = '', string $errorCode = '', array $data = [])
    {
        $this->valid = $valid;
        $this->message = $message;
        $this->errorCode = $errorCode;
        $this->data = $data;
    }
    
    /**
     * 创建成功结果
     */
    public static function success(array $data = []): self
    {
        return new self(true, '', '', $data);
    }
    
    /**
     * 创建失败结果
     */
    public static function fail(string $message, string $errorCode = 'VALIDATION_FAILED', array $data = []): self
    {
        return new self(false, $message, $errorCode, $data);
    }
    
    /**
     * 是否验证通过
     */
    public function isValid(): bool
    {
        return $this->valid;
    }
    
    /**
     * 获取错误消息
     */
    public function getMessage(): string
    {
        return $this->message;
    }
    
    /**
     * 获取错误代码
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
    
    /**
     * 获取附加数据
     */
    public function getData(): array
    {
        return $this->data;
    }
    
    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'message' => $this->message,
            'error_code' => $this->errorCode,
            'data' => $this->data,
        ];
    }
}
