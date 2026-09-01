<?php

declare(strict_types=1);

/*
 * EAV统一管理控制器
 * 提供EAV后台统一管理界面和API
 */

namespace Weline\Eav\Controller\Backend;

use Weline\Eav\Api\Metadata\CompareMode;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavAttribute\Placement;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavAttribute\Type\Value;
use Weline\Eav\Model\EavEntity;
use Weline\Eav\Service\LocalTranslation\EavLocalTranslationQueueService;
use Weline\Eav\Service\LocalTranslation\EavLocalTranslationService;
use Weline\Framework\App\Controller\BackendController;

/**
 * EAV统一管理控制器
 * 
 * 提供树形视图的统一管理界面
 * 左侧：Entity → Set → Group → Attribute 树形导航
 * 右侧：详情编辑面板
 */
class Manager extends BackendController
{
    private const DEFAULT_STRUCTURE_CODE = 'default';

    private EavEntity $eavEntity;
    private Set $eavSet;
    private Group $eavGroup;
    private EavAttribute $eavAttribute;
    private Type $eavType;

    public function __construct(
        EavEntity $eavEntity,
        Set $eavSet,
        Group $eavGroup,
        EavAttribute $eavAttribute,
        Type $eavType,
        private readonly EavLocalTranslationService $localTranslationService,
        private readonly EavLocalTranslationQueueService $localTranslationQueueService,
    ) {
        $this->eavEntity = $eavEntity;
        $this->eavSet = $eavSet;
        $this->eavGroup = $eavGroup;
        $this->eavAttribute = $eavAttribute;
        $this->eavType = $eavType;
    }

    public function __init()
    {
        parent::__init();
        // OffCanvas / iframe / embed 外部嵌入时用空白布局，避免嵌套后台顶栏侧栏
        if ($this->request->isIframe() || $this->request->getParam('embed') === '1') {
            $this->layoutType = 'default.blank';
        }
    }

    /**
     * EAV统一管理首页
     * 
     * GET /eav/backend/manager
     * GET /eav/backend/manager?entity_code=product
     */
    public function index()
    {
        try {
            $scope = $this->resolveEntityScopeFromRequest();
            if ($scope !== null) {
                $this->assign('entityScope', $scope);
            }
        } catch (\InvalidArgumentException $e) {
            $this->assign('entityScopeError', $e->getMessage());
        }

        return $this->fetch();
    }

    // ========== Tree API ==========

    /**
     * 获取树形数据（实体列表，或 entity_code scoped 时的属性集根节点）
     * 
     * GET /eav/backend/manager/tree
     * GET /eav/backend/manager/tree?entity_code=product
     */
    public function getTree(): string
    {
        try {
            $scope = $this->resolveEntityScopeFromRequest();
            if ($scope !== null) {
                $structure = $this->resolveStructureContext();
                if ($structure['structureScope'] === 'free' && $structure['productId'] > 0) {
                    $treeData = $this->getProductFreeGroups($scope['entityId'], $structure['productId']);
                } elseif ($structure['selectedSetId'] > 0) {
                    $setNode = $this->loadSetNode($structure['selectedSetId']);
                    $treeData = $setNode !== null ? [$setNode] : [];
                } else {
                    $treeData = $this->getEntityChildren($scope['entityId']);
                }
                if ($search = trim((string)($this->request->getGet('search') ?? ''))) {
                    $needle = mb_strtolower($search);
                    $treeData = array_values(array_filter(
                        $treeData,
                        static fn(array $node): bool => str_contains(mb_strtolower((string)($node['code'] ?? '')), $needle)
                            || str_contains(mb_strtolower((string)($node['name'] ?? '')), $needle),
                    ));
                }
                return $this->fetchJson([
                    'success' => true,
                    'data' => $treeData,
                    'meta' => ['entityScope' => $scope, 'structure' => $structure],
                ]);
            }

            $search = $this->request->getGet('search') ?? '';
            
            $query = clone $this->eavEntity;
            $query->loadLocalDescription();
            
            if ($search) {
                $query->where('concat(main_table.code, main_table.name, local.name)', "%{$search}%", 'like');
            }
            
            $entities = $query->select()->fetchArray();
            
            $treeData = [];
            foreach ($entities as $entity) {
                $treeData[] = $this->formatEntityNode($entity);
            }
            
            return $this->fetchJson(['success' => true, 'data' => $treeData]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 获取子节点（懒加载）
     * 
     * GET /eav/backend/manager/children?type=entity&id=1
     */
    public function getChildren(): string
    {
        try {
            $type = $this->request->getGet('type');
            $id = (int)$this->request->getGet('id');
            
            $children = match ($type) {
                'entity' => $this->getEntityChildren($id),
                'set' => $this->getSetChildren($id),
                'group' => $this->getGroupChildren($id),
                default => throw new \InvalidArgumentException(__('未知节点类型: %1', $type)),
            };
            
            return $this->fetchJson(['success' => true, 'data' => $children]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ========== Entity API ==========

    /**
     * 获取实体详情
     * 
     * GET /eav/backend/manager/entityDetail?id=1
     */
    public function getEntityDetail(): string
    {
        try {
            $id = (int)$this->request->getGet('id');
            
            $query = clone $this->eavEntity;
            $query->loadLocalDescription();
            $query->where('main_table.eav_entity_id', $id);
            $entity = $query->find()->fetchArray();
            
            if (empty($entity)) {
                throw new \InvalidArgumentException(__('实体不存在: %1', $id));
            }

            $entity = $this->enrichStructureDetail($entity, 'entity');
            
            return $this->fetchJson(['success' => true, 'data' => $entity]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 保存实体
     * 
     * POST /eav/backend/manager/entitySave
     */
    public function postEntitySave(): string
    {
        try {
            $id = (int)$this->request->getPost('eav_entity_id');
            $code = $this->request->getPost('code');
            $name = $this->request->getPost('name');
            $class = $this->request->getPost('class');
            
            if (!$code) {
                throw new \InvalidArgumentException(__('实体代码不能为空'));
            }
            if (!$name) {
                throw new \InvalidArgumentException(__('实体名称不能为空'));
            }
            if (!$class) {
                throw new \InvalidArgumentException(__('实体类不能为空'));
            }
            
            $entity = clone $this->eavEntity;
            
            if ($id) {
                $entity->load($id);
                if (!$entity->getId()) {
                    throw new \InvalidArgumentException(__('实体不存在'));
                }
                
                // 如果是系统实体，不允许修改代码和类
                if ($entity->getData('is_system')) {
                    if ($entity->getData('code') !== $code) {
                        throw new \InvalidArgumentException(__('系统实体的代码不能修改'));
                    }
                    if ($entity->getData('class') !== $class) {
                        throw new \InvalidArgumentException(__('系统实体的实体类不能修改'));
                    }
                }
            }
            
            $entity->setData('code', $code);
            if (!$id || $this->localTranslationService->isSourceLocale()) {
                $entity->setData('name', $name);
            }
            $entity->setData('class', $class);
            $entity->setData('eav_entity_id_field_type', $this->request->getPost('eav_entity_id_field_type') ?? 'integer');
            $entity->setData('eav_entity_id_field_length', (int)($this->request->getPost('eav_entity_id_field_length') ?? 11));
            
            $entity->save();
            $entityId = (int)$entity->getId();
            if ($id && !$this->localTranslationService->isSourceLocale()) {
                $this->localTranslationService->saveLocalizedField('entity', $entityId, (string)$name);
            } else {
                $this->localTranslationService->syncSourceLocale('entity', $entityId, (string)$name);
            }
            if (!$id) {
                $this->ensureDefaultStructure($entityId);
            }
            
            return $this->fetchJson([
                'success' => true,
                'message' => $id ? __('实体更新成功') : __('实体创建成功'),
                'data' => ['eav_entity_id' => $entityId]
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ========== Set API ==========

    /**
     * 获取属性集详情
     */
    public function getSetDetail(): string
    {
        try {
            $id = (int)$this->request->getGet('id');
            
            $query = clone $this->eavSet;
            $query->loadLocalDescription();
            $query->where('main_table.set_id', $id);
            $set = $query->find()->fetchArray();
            
            if (empty($set)) {
                throw new \InvalidArgumentException(__('属性集不存在'));
            }

            $set = $this->enrichStructureDetail($set, 'set');
            
            return $this->fetchJson(['success' => true, 'data' => $set]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 保存属性集
     */
    public function postSetSave(): string
    {
        try {
            $id = (int)$this->request->getPost('set_id');
            $entityId = (int)$this->request->getPost('eav_entity_id');
            $code = $this->request->getPost('code');
            $name = $this->request->getPost('name');
            
            if (!$entityId) {
                throw new \InvalidArgumentException(__('请选择所属实体'));
            }
            $this->assertEntityScope($entityId);
            if (!$code) {
                throw new \InvalidArgumentException(__('属性集代码不能为空'));
            }
            if (!$name) {
                throw new \InvalidArgumentException(__('属性集名称不能为空'));
            }
            
            $set = clone $this->eavSet;
            
            if ($id) {
                $set->load($id);
                if ((int)$set->getId() && $this->isDefaultStructureCode((string)$set->getData(Set::schema_fields_code))) {
                    throw new \InvalidArgumentException(__('默认属性集不能修改'));
                }
            }
            if ($this->isDefaultStructureCode((string)$code) && (!$id || !$this->isDefaultStructureCode((string)$set->getData(Set::schema_fields_code)))) {
                throw new \InvalidArgumentException(__('不能使用保留代码 default'));
            }
            
            $set->setData('eav_entity_id', $entityId);
            $set->setData('code', $code);
            if (!$id || $this->localTranslationService->isSourceLocale()) {
                $set->setData('name', $name);
            }
            $set->save();
            $setId = (int)$set->getId();
            if ($id && !$this->localTranslationService->isSourceLocale()) {
                $this->localTranslationService->saveLocalizedField('set', $setId, (string)$name);
            } else {
                $this->localTranslationService->syncSourceLocale('set', $setId, (string)$name);
            }
            
            return $this->fetchJson([
                'success' => true,
                'message' => $id ? __('属性集更新成功') : __('属性集创建成功'),
                'data' => ['set_id' => $setId]
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ========== Group API ==========

    /**
     * 获取属性组详情
     */
    public function getGroupDetail(): string
    {
        try {
            $id = (int)$this->request->getGet('id');
            
            $query = clone $this->eavGroup;
            $query->loadLocalDescription();
            $query->where('main_table.group_id', $id);
            $group = $query->find()->fetchArray();
            
            if (empty($group)) {
                throw new \InvalidArgumentException(__('属性组不存在'));
            }

            $group = $this->enrichStructureDetail($group, 'group');
            
            return $this->fetchJson(['success' => true, 'data' => $group]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 保存属性组
     */
    public function postGroupSave(): string
    {
        try {
            $id = (int)$this->request->getPost('group_id');
            $entityId = (int)$this->request->getPost('eav_entity_id');
            $setId = (int)$this->request->getPost('set_id');
            $code = $this->request->getPost('code');
            $name = $this->request->getPost('name');
            
            if (!$entityId || !$setId || !$code || !$name) {
                throw new \InvalidArgumentException(__('缺少必填字段'));
            }
            $this->assertEntityScope($entityId);
            
            $group = clone $this->eavGroup;
            
            if ($id) {
                $group->load($id);
                if ((int)$group->getId() && $this->isDefaultStructureCode((string)$group->getData(Group::schema_fields_code))) {
                    throw new \InvalidArgumentException(__('默认属性组不能修改'));
                }
            }
            if ($this->isDefaultStructureCode((string)$code) && (!$id || !$this->isDefaultStructureCode((string)$group->getData(Group::schema_fields_code)))) {
                throw new \InvalidArgumentException(__('不能使用保留代码 default'));
            }
            
            $group->setData('eav_entity_id', $entityId);
            $group->setData('set_id', $setId);
            $group->setData('code', $code);
            if (!$id || $this->localTranslationService->isSourceLocale()) {
                $group->setData('name', $name);
            }
            $structure = $this->resolveStructureContext();
            if ($structure['structureScope'] === 'free' && $structure['productId'] > 0) {
                $freeSetId = $this->resolveProductFreeSetId($entityId);
                if ($freeSetId <= 0) {
                    throw new \InvalidArgumentException(__('自由属性集不存在，请先执行升级'));
                }
                $group->setData('set_id', $freeSetId);
                $group->setData(Group::schema_fields_scope_product_id, $structure['productId']);
            } else {
                $group->setData(Group::schema_fields_scope_product_id, null);
            }
            $group->save();
            $groupId = (int)$group->getId();
            if ($id && !$this->localTranslationService->isSourceLocale()) {
                $this->localTranslationService->saveLocalizedField('group', $groupId, (string)$name);
            } else {
                $this->localTranslationService->syncSourceLocale('group', $groupId, (string)$name);
            }
            
            return $this->fetchJson([
                'success' => true,
                'message' => $id ? __('属性组更新成功') : __('属性组创建成功'),
                'data' => ['group_id' => $groupId]
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ========== Attribute API ==========

    /**
     * 获取属性详情
     */
    public function getAttributeDetail(): string
    {
        try {
            $id = (int)$this->request->getGet('id');
            
            $query = clone $this->eavAttribute;
            $query->loadLocalDescription();
            $query->joinModel(
                Type::class,
                'type',
                'main_table.type_id=type.type_id',
                'left',
                'type.name as type_name, type.code as type_code, type.element as type_element, type.is_swatch as type_is_swatch, type.swatch_color as type_swatch_color, type.swatch_image as type_swatch_image, type.swatch_text as type_swatch_text'
            );
            $query->where('main_table.attribute_id', $id);
            $attribute = $query->find()->fetchArray();
            
            if (empty($attribute)) {
                throw new \InvalidArgumentException(__('属性不存在'));
            }

            $attributeModel = clone $this->eavAttribute;
            $attributeModel->load($id);
            $attribute = $this->enrichAttributeDetail($attribute, $attributeModel);
            
            if ($this->attributeTypeSupportsOptions($attribute)) {
                $optionModel = \Weline\Framework\Manager\ObjectManager::getInstance(Option::class);
                $attribute['options'] = $optionModel->where('attribute_id', $id)->select()->fetchArray();
            } else {
                $attribute['options'] = [];
            }
            
            return $this->fetchJson(['success' => true, 'data' => $attribute]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 保存属性
     */
    public function postAttributeSave(): string
    {
        try {
            $id = (int)$this->request->getPost('attribute_id');
            $entityId = (int)$this->request->getPost('eav_entity_id');
            $setId = (int)$this->request->getPost('set_id');
            $groupId = (int)$this->request->getPost('group_id');
            $typeId = (int)$this->request->getPost('type_id');
            $code = $this->request->getPost('code');
            $name = $this->request->getPost('name');
            
            if (!$entityId || !$setId || !$groupId || !$typeId || !$code || !$name) {
                throw new \InvalidArgumentException(__('缺少必填字段'));
            }
            $this->assertEntityScope($entityId);
            
            $attribute = clone $this->eavAttribute;
            
            if ($id) {
                $attribute->load($id);
                if (!(int)$attribute->getAttributeId()) {
                    throw new \InvalidArgumentException(__('属性不存在'));
                }
                $isSystem = !empty($attribute->getData('is_system'));
                $hasEntityData = $this->attributeHasEntityData($attribute);
                if ($isSystem || $hasEntityData) {
                    $lockedTypeId = (int)$attribute->getData(EavAttribute::schema_fields_type_id);
                    $lockedTypeRow = $this->loadAttributeTypeRow($lockedTypeId);
                    $supportsOptions = $this->attributeTypeSupportsOptions($lockedTypeRow)
                        || (int)$attribute->getData('data_has_option') === 1;
                    if (!$supportsOptions) {
                        $this->assertAttributeEditable($attribute);
                    }
                    $options = $this->request->getPost('options');
                    $this->syncAttributeOptions(
                        (int)$attribute->getAttributeId(),
                        (int)$attribute->getData(EavAttribute::schema_fields_eav_entity_id),
                        is_array($options) ? $options : [],
                    );

                    return $this->fetchJson([
                        'success' => true,
                        'message' => __('属性选项已更新'),
                        'data' => ['attribute_id' => (int)$attribute->getAttributeId()],
                    ]);
                }
                if ((int)$attribute->getData(EavAttribute::schema_fields_type_id) !== $typeId) {
                    throw new \InvalidArgumentException(__('属性类型保存后不可修改'));
                }
            }

            $typeRow = $this->loadAttributeTypeRow($typeId);
            if ($typeRow === []) {
                throw new \InvalidArgumentException(__('属性类型不存在'));
            }
            
            $attribute->setData('eav_entity_id', $entityId);
            $attribute->setData('set_id', $setId);
            $attribute->setData('group_id', $groupId);
            $attribute->setData('type_id', $typeId);
            $attribute->setData('code', $code);
            if (!$id || $this->localTranslationService->isSourceLocale()) {
                $attribute->setData('name', $name);
            }
            // 基本设置组
            $attribute->setData('basic_is_enable', $this->request->getPost('basic_is_enable') ? 1 : 0);
            // 前端显示组
            $attribute->setData('frontend_is_filterable', $this->request->getPost('frontend_is_filterable') ? 1 : 0);
            $attribute->setData('frontend_is_searchable', $this->request->getPost('frontend_is_searchable') ? 1 : 0);
            $attribute->setData('frontend_is_visible', $this->request->getPost('frontend_is_visible') ? 1 : 0);
            $postedCompareMode = $this->request->getPost('compare_mode');
            if ($postedCompareMode !== null && $postedCompareMode !== '' && !CompareMode::isValid($postedCompareMode)) {
                throw new \InvalidArgumentException(__('无效的可比模式'));
            }
            $attribute->setData(
                EavAttribute::schema_fields_compare_mode,
                CompareMode::normalize($postedCompareMode ?? CompareMode::NONE),
            );
            // 数据配置组
            $attribute->setData('data_is_multiple', $this->request->getPost('data_is_multiple') ? 1 : 0);
            $attribute->setData(
                'data_has_option',
                $this->attributeTypeSupportsOptions($typeRow)
                    ? 1
                    : ($this->request->getPost('data_has_option') ? 1 : 0),
            );
            $structure = $this->resolveStructureContext();
            if ($structure['structureScope'] === 'free' && $structure['productId'] > 0) {
                $freeSetId = $this->resolveProductFreeSetId($entityId);
                if ($freeSetId <= 0) {
                    throw new \InvalidArgumentException(__('自由属性集不存在，请先执行升级'));
                }
                $attribute->setData('set_id', $freeSetId);
                $attribute->setData(EavAttribute::schema_fields_scope_product_id, $structure['productId']);
            } else {
                $attribute->setData(EavAttribute::schema_fields_scope_product_id, null);
            }

            $attribute->save();
            $attributeId = (int)$attribute->getAttributeId();
            if ($id && !$this->localTranslationService->isSourceLocale()) {
                $this->localTranslationService->saveLocalizedField('attribute', $attributeId, (string)$name);
            } else {
                $this->localTranslationService->syncSourceLocale('attribute', $attributeId, (string)$name);
            }

            if ($this->attributeTypeSupportsOptions($typeRow) || (int)$attribute->getData('data_has_option') === 1) {
                $options = $this->request->getPost('options');
                $this->syncAttributeOptions(
                    $attributeId,
                    $entityId,
                    is_array($options) ? $options : [],
                );
            }
            
            return $this->fetchJson([
                'success' => true,
                'message' => $id ? __('属性更新成功') : __('属性创建成功'),
                'data' => ['attribute_id' => $attributeId]
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 获取属性类型列表
     */
    public function getTypes(): string
    {
        try {
            $types = $this->eavType->select()->fetchArray();
            
            $options = [];
            foreach ($types as $type) {
                $options[] = [
                    'value' => (int)$type['type_id'],
                    'label' => $type['name'],
                    'code' => $type['code'],
                    'element' => $type['element'],
                    'hasOption' => $this->attributeTypeSupportsOptions($type),
                    'isSwatch' => (bool)($type['is_swatch'] ?? false),
                    'swatchColor' => (bool)($type['swatch_color'] ?? false),
                    'swatchImage' => (bool)($type['swatch_image'] ?? false),
                    'swatchText' => (bool)($type['swatch_text'] ?? false),
                ];
            }
            
            return $this->fetchJson(['success' => true, 'data' => $options]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * AI 翻译当前 EAV 节点 LocalDescription（名称/选项值）
     *
     * POST /eav/backend/manager/local-ai-translate
     */
    public function postLocalAiTranslate(): string
    {
        try {
            $type = strtolower(trim((string)$this->request->getPost('type')));
            $id = (int)$this->request->getPost('id');
            $retranslateAll = $this->request->getPost('retranslate_all') === '1'
                || $this->request->getPost('retranslate') === '1';
            $result = $this->localTranslationService->aiTranslateNode($type, $id, $retranslateAll);

            if ((int)($result['translated'] ?? 0) === 0) {
                if ((int)($result['skipped'] ?? 0) > 0 && !$retranslateAll) {
                    return $this->fetchJson([
                        'success' => true,
                        'message' => (string)__('所有语言均已翻译，无需补充'),
                        'data' => $result,
                    ]);
                }

                $message = !empty($result['errors'])
                    ? (string)__('AI翻译调用失败')
                    : (string)__('AI翻译未返回目标语言结果');

                return $this->fetchJson(['success' => false, 'message' => $message, 'data' => $result]);
            }

            return $this->fetchJson([
                'success' => true,
                'message' => (string)__(
                    'AI 翻译完成：%{1} 个语言',
                    [(string)($result['translated'] ?? 0)],
                ),
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }


    /**
     * @deprecated 请使用 POST LocalModel 翻译或 I18n LocalModel cron；EAV 定时翻译入口已移除。
     */
    public function postLocalTranslateSchedule(): string
    {
        return $this->fetchJson([
            'success' => false,
            'message' => (string)__(
                'EAV 定时翻译入口已移除，请使用 I18n LocalModel 自动翻译队列（i18n_ai_translation cron）。',
            ),
        ]);
    }

    // ========== Delete API ==========

    /**
     * 拖拽移动/归属节点：属性组 → 属性集；属性 → 属性组（同集移动，跨集归属）
     *
     * POST /eav/backend/manager/move
     */
    public function postMove(): string
    {
        try {
            $type = strtolower(trim((string)$this->request->getPost('type')));
            $id = (int)$this->request->getPost('id');
            $targetType = strtolower(trim((string)$this->request->getPost('target_type')));
            $targetId = (int)$this->request->getPost('target_id');
            $placementId = (int)$this->request->getPost('placement_id');

            if ($id <= 0 || $targetId <= 0) {
                throw new \InvalidArgumentException(__('缺少必填字段'));
            }

            if ($type === 'group' && $targetType === 'set') {
                return $this->moveGroupToSet($id, $targetId);
            }
            if ($type === 'attribute' && $targetType === 'group') {
                return $this->relocateAttribute($id, $targetId, $placementId);
            }

            throw new \InvalidArgumentException(__('不支持的拖拽操作'));
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 删除节点
     */
    public function postDelete(): string
    {
        try {
            $type = (string)$this->request->getPost('type');
            $id = (int)$this->request->getPost('id');

            match ($type) {
                'entity' => $this->deleteEntityRecord($id),
                'set' => $this->deleteSetRecord($id),
                'group' => $this->deleteGroupRecord($id),
                'attribute' => $this->deleteAttributeRecord($id),
                default => throw new \InvalidArgumentException(__('未知类型')),
            };

            return $this->fetchJson(['success' => true, 'message' => __('删除成功')]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ========== Helper Methods ==========

    private function formatEntityNode(array $entity): array
    {
        return [
            'id' => 'entity_' . $entity['eav_entity_id'],
            'nodeId' => (int)$entity['eav_entity_id'],
            'type' => 'entity',
            'code' => $entity['code'],
            'name' => $entity['local_name'] ?? $entity['name'] ?? $entity['code'],
            'class' => $entity['class'] ?? '',
            'icon' => 'box',
            'isSystem' => (bool)($entity['is_system'] ?? false),
            'lazy' => true,
            'children' => [],
        ];
    }

    private function formatSetNode(array $set): array
    {
        return [
            'id' => 'set_' . $set['set_id'],
            'nodeId' => (int)$set['set_id'],
            'type' => 'set',
            'code' => $set['code'],
            'name' => $set['local_name'] ?? $set['name'] ?? $set['code'],
            'icon' => 'folder',
            'entityId' => (int)$set['eav_entity_id'],
            'isDefault' => $this->isDefaultStructureCode((string)($set['code'] ?? '')),
            'lazy' => true,
            'children' => [],
        ];
    }

    private function formatGroupNode(array $group): array
    {
        return [
            'id' => 'group_' . $group['group_id'],
            'nodeId' => (int)$group['group_id'],
            'type' => 'group',
            'code' => $group['code'],
            'name' => $group['local_name'] ?? $group['name'] ?? $group['code'],
            'icon' => 'folder',
            'entityId' => (int)$group['eav_entity_id'],
            'setId' => (int)$group['set_id'],
            'isDefault' => $this->isDefaultStructureCode((string)($group['code'] ?? '')),
            'lazy' => true,
            'children' => [],
        ];
    }

    private function formatAttributeNode(array $attribute): array
    {
        $hasOption = (bool)($attribute['data_has_option'] ?? false);
        $hasMultiple = (bool)($attribute['data_is_multiple'] ?? false);
        $optionCount = (int)($attribute['option_count'] ?? 0);
        $hasSwatchColor = (int)($attribute['swatch_color_count'] ?? 0) > 0;
        $hasSwatchImage = (int)($attribute['swatch_image_count'] ?? 0) > 0;
        $hasSwatchText = (int)($attribute['swatch_text_count'] ?? 0) > 0;
        $hasSwatch = $hasSwatchColor || $hasSwatchImage || $hasSwatchText;

        $structures = [];
        if ($hasOption) {
            $structures[] = 'options';
        }
        if ($hasMultiple) {
            $structures[] = 'multiple';
        }
        if ($hasSwatchColor) {
            $structures[] = 'swatch_color';
        }
        if ($hasSwatchImage) {
            $structures[] = 'swatch_image';
        }
        if ($hasSwatchText) {
            $structures[] = 'swatch_text';
        }

        return [
            'id' => !empty($attribute['is_assigned'])
                ? 'placement_' . (int)($attribute['placement_id'] ?? 0)
                : 'attribute_' . $attribute['attribute_id'],
            'nodeId' => (int)$attribute['attribute_id'],
            'type' => 'attribute',
            'code' => $attribute['code'],
            'name' => $attribute['local_name'] ?? $attribute['name'] ?? $attribute['code'],
            'icon' => $hasOption ? 'list' : 'circle',
            'entityId' => (int)$attribute['eav_entity_id'],
            'setId' => (int)($attribute['display_set_id'] ?? $attribute['set_id'] ?? 0),
            'groupId' => (int)($attribute['display_group_id'] ?? $attribute['group_id'] ?? 0),
            'homeSetId' => (int)($attribute['set_id'] ?? 0),
            'homeGroupId' => (int)($attribute['group_id'] ?? 0),
            'placementId' => (int)($attribute['placement_id'] ?? 0),
            'isAssigned' => (bool)($attribute['is_assigned'] ?? false),
            'isSystem' => (bool)($attribute['is_system'] ?? false),
            'hasOption' => $hasOption,
            'hasMultiple' => $hasMultiple,
            'hasSwatch' => $hasSwatch,
            'optionCount' => $optionCount,
            'structures' => $structures,
            'lazy' => false,
            'children' => [],
        ];
    }

    private function getEntityChildren(int $entityId): array
    {
        $query = clone $this->eavSet;
        $query->loadLocalDescription();
        $query->where('main_table.eav_entity_id', $entityId);
        $sets = $query->select()->fetchArray();
        
        $children = [];
        foreach ($sets as $set) {
            if ($this->isProductFreeSetCode((string)($set['code'] ?? ''))) {
                continue;
            }
            $children[] = $this->formatSetNode($set);
        }
        return $children;
    }

    private function getSetChildren(int $setId): array
    {
        $structure = $this->resolveStructureContext();
        $query = clone $this->eavGroup;
        $query->loadLocalDescription();
        $query->where('main_table.set_id', $setId);
        if ($structure['structureScope'] === 'free' && $structure['productId'] > 0) {
            $query->where('main_table.' . Group::schema_fields_scope_product_id, $structure['productId']);
        } else {
            $query->where(
                'main_table.' . Group::schema_fields_scope_product_id,
                null,
                'IS NULL',
            );
        }
        $groups = $query->select()->fetchArray();
        
        $children = [];
        foreach ($groups as $group) {
            $children[] = $this->formatGroupNode($group);
        }
        return $children;
    }

    private function getGroupChildren(int $groupId): array
    {
        $group = clone $this->eavGroup;
        $group->load($groupId);
        if (!(int)$group->getId()) {
            return [];
        }
        $targetSetId = (int)$group->getData(Group::schema_fields_set_id);

        $query = clone $this->eavAttribute;
        $query->loadLocalDescription();
        $query->joinModel(
            Type::class,
            'type',
            'main_table.type_id=type.type_id',
            'left',
            'type.is_swatch as type_is_swatch, type.swatch_color as type_swatch_color, type.swatch_image as type_swatch_image, type.swatch_text as type_swatch_text, type.element as type_element'
        );
        $query->where('main_table.group_id', $groupId);
        $query->where('main_table.' . EavAttribute::schema_fields_set_id, $targetSetId);
        $attributes = $query->select()->fetchArray();

        $seenAttributeIds = [];
        foreach ($attributes as $attribute) {
            $seenAttributeIds[(int)($attribute['attribute_id'] ?? 0)] = true;
        }

        /** @var Placement $placementModel */
        $placementModel = \Weline\Framework\Manager\ObjectManager::getInstance(Placement::class);
        $placements = $placementModel
            ->where(Placement::schema_fields_group_id, $groupId)
            ->select()
            ->fetchArray();

        foreach ($placements as $placement) {
            $attributeId = (int)($placement[Placement::schema_fields_attribute_id] ?? 0);
            if ($attributeId <= 0 || isset($seenAttributeIds[$attributeId])) {
                continue;
            }
            $assigned = clone $this->eavAttribute;
            $assigned->loadLocalDescription();
            $assigned->joinModel(
                Type::class,
                'type',
                'main_table.type_id=type.type_id',
                'left',
                'type.is_swatch as type_is_swatch, type.swatch_color as type_swatch_color, type.swatch_image as type_swatch_image, type.swatch_text as type_swatch_text, type.element as type_element'
            );
            $assigned->where('main_table.attribute_id', $attributeId);
            $row = $assigned->find()->fetchArray();
            if ($row === []) {
                continue;
            }
            $row['is_assigned'] = true;
            $row['placement_id'] = (int)($placement[Placement::schema_fields_placement_id] ?? 0);
            $row['display_set_id'] = (int)($placement[Placement::schema_fields_set_id] ?? $targetSetId);
            $row['display_group_id'] = (int)($placement[Placement::schema_fields_group_id] ?? $groupId);
            $attributes[] = $row;
            $seenAttributeIds[$attributeId] = true;
        }

        $optionStats = $this->optionStructureStats(array_map(
            static fn(array $attribute): int => (int)($attribute['attribute_id'] ?? 0),
            $attributes,
        ));

        $children = [];
        foreach ($attributes as $attribute) {
            $attributeId = (int)($attribute['attribute_id'] ?? 0);
            $stats = $optionStats[$attributeId] ?? [
                'option_count' => 0,
                'swatch_color_count' => 0,
                'swatch_image_count' => 0,
                'swatch_text_count' => 0,
            ];
            if (empty($attribute['display_set_id'])) {
                $attribute['display_set_id'] = (int)($attribute['set_id'] ?? 0);
            }
            if (empty($attribute['display_group_id'])) {
                $attribute['display_group_id'] = (int)($attribute['group_id'] ?? 0);
            }
            $children[] = $this->formatAttributeNode(array_merge($attribute, $stats));
        }
        return $children;
    }

    /**
     * @param list<int> $attributeIds
     * @return array<int, array{option_count:int,swatch_color_count:int,swatch_image_count:int,swatch_text_count:int}>
     */
    private function optionStructureStats(array $attributeIds): array
    {
        $ids = array_values(array_unique(array_filter($attributeIds, static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        /** @var Option $optionModel */
        $optionModel = \Weline\Framework\Manager\ObjectManager::getInstance(Option::class);
        $rows = $optionModel
            ->where(Option::schema_fields_attribute_id, $ids, 'IN')
            ->select()
            ->fetchArray();

        $stats = [];
        foreach ($ids as $id) {
            $stats[$id] = [
                'option_count' => 0,
                'swatch_color_count' => 0,
                'swatch_image_count' => 0,
                'swatch_text_count' => 0,
            ];
        }
        foreach ($rows as $row) {
            $attributeId = (int)($row[Option::schema_fields_attribute_id] ?? 0);
            if ($attributeId <= 0 || !isset($stats[$attributeId])) {
                continue;
            }
            ++$stats[$attributeId]['option_count'];
            if (trim((string)($row[Option::schema_fields_swatch_color] ?? '')) !== '') {
                ++$stats[$attributeId]['swatch_color_count'];
            }
            if (trim((string)($row[Option::schema_fields_swatch_image] ?? '')) !== '') {
                ++$stats[$attributeId]['swatch_image_count'];
            }
            if (trim((string)($row[Option::schema_fields_swatch_text] ?? '')) !== '') {
                ++$stats[$attributeId]['swatch_text_count'];
            }
        }

        return $stats;
    }

    private function moveGroupToSet(int $groupId, int $setId): string
    {
        $group = clone $this->eavGroup;
        $group->load($groupId);
        if (!(int)$group->getId()) {
            throw new \InvalidArgumentException(__('记录不存在'));
        }

        $set = clone $this->eavSet;
        $set->load($setId);
        if (!(int)$set->getId()) {
            throw new \InvalidArgumentException(__('属性集不存在'));
        }

        if ((int)$group->getData(Group::schema_fields_eav_entity_id) !== (int)$set->getData(Set::schema_fields_eav_entity_id)) {
            throw new \InvalidArgumentException(__('只能在同一实体内移动'));
        }
        $this->assertEntityScope((int)$group->getData(Group::schema_fields_eav_entity_id));

        if ((int)$group->getData(Group::schema_fields_set_id) === $setId) {
            return $this->fetchJson(['success' => true, 'message' => __('已在目标位置'), 'data' => ['group_id' => $groupId]]);
        }

        $group->setData(Group::schema_fields_set_id, $setId)->save();

        return $this->fetchJson([
            'success' => true,
            'message' => __('移动成功'),
            'data' => [
                'group_id' => $groupId,
                'set_id' => $setId,
                'eav_entity_id' => (int)$group->getData(Group::schema_fields_eav_entity_id),
            ],
        ]);
    }

    private function relocateAttribute(int $attributeId, int $groupId, int $placementId = 0): string
    {
        $attribute = clone $this->eavAttribute;
        $attribute->load($attributeId);
        if (!(int)$attribute->getAttributeId()) {
            throw new \InvalidArgumentException(__('属性不存在'));
        }

        $group = clone $this->eavGroup;
        $group->load($groupId);
        if (!(int)$group->getId()) {
            throw new \InvalidArgumentException(__('属性组不存在'));
        }

        if ((int)$attribute->getData(EavAttribute::schema_fields_eav_entity_id) !== (int)$group->getData(Group::schema_fields_eav_entity_id)) {
            throw new \InvalidArgumentException(__('只能在同一实体内操作'));
        }
        $this->assertEntityScope((int)$attribute->getData(EavAttribute::schema_fields_eav_entity_id));

        $targetSetId = (int)$group->getData(Group::schema_fields_set_id);
        if ($placementId > 0) {
            return $this->relocateAssignedAttribute($attribute, $placementId, $targetSetId, $groupId);
        }

        $homeSetId = (int)$attribute->getData(EavAttribute::schema_fields_set_id);
        if ($homeSetId !== $targetSetId) {
            return $this->assignAttributeToSetGroup($attribute, $targetSetId, $groupId);
        }

        return $this->moveAttributeWithinSet($attribute, $groupId, $targetSetId);
    }

    private function moveAttributeWithinSet(EavAttribute $attribute, int $groupId, int $setId): string
    {
        $attributeId = (int)$attribute->getAttributeId();
        if ((int)$attribute->getData(EavAttribute::schema_fields_group_id) === $groupId
            && (int)$attribute->getData(EavAttribute::schema_fields_set_id) === $setId) {
            return $this->fetchJson(['success' => true, 'message' => __('已在目标位置'), 'data' => ['attribute_id' => $attributeId]]);
        }

        $attribute->setData(EavAttribute::schema_fields_group_id, $groupId);
        $attribute->setData(EavAttribute::schema_fields_set_id, $setId);
        $attribute->save();

        return $this->fetchJson([
            'success' => true,
            'message' => __('移动成功'),
            'data' => [
                'attribute_id' => $attributeId,
                'group_id' => $groupId,
                'set_id' => $setId,
                'mode' => 'move',
            ],
        ]);
    }

    private function assignAttributeToSetGroup(EavAttribute $attribute, int $setId, int $groupId): string
    {
        $attributeId = (int)$attribute->getAttributeId();
        $entityId = (int)$attribute->getData(EavAttribute::schema_fields_eav_entity_id);

        /** @var Placement $placementModel */
        $placementModel = \Weline\Framework\Manager\ObjectManager::getInstance(Placement::class);
        $existing = clone $placementModel;
        $existing->where(Placement::schema_fields_attribute_id, $attributeId)
            ->where(Placement::schema_fields_set_id, $setId)
            ->find();

        if ((int)$existing->getId()) {
            if ((int)$existing->getData(Placement::schema_fields_group_id) === $groupId) {
                return $this->fetchJson([
                    'success' => true,
                    'message' => __('已在目标位置'),
                    'data' => [
                        'attribute_id' => $attributeId,
                        'placement_id' => (int)$existing->getId(),
                        'mode' => 'assign',
                    ],
                ]);
            }
            $existing->setData(Placement::schema_fields_group_id, $groupId)->save();

            return $this->fetchJson([
                'success' => true,
                'message' => __('移动成功'),
                'data' => [
                    'attribute_id' => $attributeId,
                    'placement_id' => (int)$existing->getId(),
                    'group_id' => $groupId,
                    'set_id' => $setId,
                    'mode' => 'move',
                ],
            ]);
        }
        $placement = clone $placementModel;
        $placement->setData(Placement::schema_fields_attribute_id, $attributeId);
        $placement->setData(Placement::schema_fields_eav_entity_id, $entityId);
        $placement->setData(Placement::schema_fields_set_id, $setId);
        $placement->setData(Placement::schema_fields_group_id, $groupId);
        $placement->save();
        $placementId = (int)$placement->getId();

        return $this->fetchJson([
            'success' => true,
            'message' => __('归属成功'),
            'data' => [
                'attribute_id' => $attributeId,
                'placement_id' => $placementId,
                'group_id' => $groupId,
                'set_id' => $setId,
                'mode' => 'assign',
            ],
        ]);
    }

    private function relocateAssignedAttribute(
        EavAttribute $attribute,
        int $placementId,
        int $targetSetId,
        int $targetGroupId,
    ): string {
        /** @var Placement $placementModel */
        $placementModel = \Weline\Framework\Manager\ObjectManager::getInstance(Placement::class);
        $placement = clone $placementModel;
        $placement->load($placementId);
        if (!(int)$placement->getId()) {
            throw new \InvalidArgumentException(__('归属记录不存在'));
        }
        if ((int)$placement->getData(Placement::schema_fields_attribute_id) !== (int)$attribute->getAttributeId()) {
            throw new \InvalidArgumentException(__('归属记录与属性不匹配'));
        }

        $placementSetId = (int)$placement->getData(Placement::schema_fields_set_id);
        $homeSetId = (int)$attribute->getData(EavAttribute::schema_fields_set_id);

        if ($placementSetId === $targetSetId) {
            if ((int)$placement->getData(Placement::schema_fields_group_id) === $targetGroupId) {
                return $this->fetchJson([
                    'success' => true,
                    'message' => __('已在目标位置'),
                    'data' => [
                        'attribute_id' => (int)$attribute->getAttributeId(),
                        'placement_id' => $placementId,
                        'mode' => 'move',
                    ],
                ]);
            }

            $placement->setData(Placement::schema_fields_group_id, $targetGroupId)->save();

            return $this->fetchJson([
                'success' => true,
                'message' => __('移动成功'),
                'data' => [
                    'attribute_id' => (int)$attribute->getAttributeId(),
                    'placement_id' => $placementId,
                    'group_id' => $targetGroupId,
                    'set_id' => $targetSetId,
                    'mode' => 'move',
                ],
            ]);
        }

        if ($targetSetId === $homeSetId) {
            $this->moveAttributeWithinSet($attribute, $targetGroupId, $targetSetId);
            $placement->delete();

            return $this->fetchJson([
                'success' => true,
                'message' => __('移动成功'),
                'data' => [
                    'attribute_id' => (int)$attribute->getAttributeId(),
                    'group_id' => $targetGroupId,
                    'set_id' => $targetSetId,
                    'mode' => 'move',
                ],
            ]);
        }

        $duplicate = clone $placementModel;
        $duplicate->where(Placement::schema_fields_attribute_id, (int)$attribute->getAttributeId())
            ->where(Placement::schema_fields_set_id, $targetSetId)
            ->find();
        if ((int)$duplicate->getId() && (int)$duplicate->getId() !== $placementId) {
            $duplicate->delete();
        }

        $placement->setData(Placement::schema_fields_set_id, $targetSetId);
        $placement->setData(Placement::schema_fields_group_id, $targetGroupId);
        $placement->save();

        return $this->fetchJson([
            'success' => true,
            'message' => __('归属成功'),
            'data' => [
                'attribute_id' => (int)$attribute->getAttributeId(),
                'placement_id' => $placementId,
                'group_id' => $targetGroupId,
                'set_id' => $targetSetId,
                'mode' => 'assign',
            ],
        ]);
    }

    /**
     * @return array{code:string,entityId:int,name:string}|null
     */
    private function resolveEntityScopeFromRequest(?string $entityCode = null): ?array
    {
        $entityCode = strtolower(trim($entityCode ?? (string)($this->request->getParam('entity_code') ?? '')));
        if ($entityCode === '') {
            return null;
        }

        $query = clone $this->eavEntity;
        $query->loadLocalDescription();
        $query->where(EavEntity::schema_fields_code, $entityCode);
        $entity = $query->find()->fetchArray();
        if ($entity === []) {
            throw new \InvalidArgumentException(__('实体不存在: %1', $entityCode));
        }

        return [
            'code' => (string)($entity['code'] ?? $entityCode),
            'entityId' => (int)($entity['eav_entity_id'] ?? 0),
            'name' => (string)($entity['local_name'] ?? $entity['name'] ?? $entity['code'] ?? $entityCode),
        ];
    }

    private function assertEntityScope(int $entityId): void
    {
        $scope = $this->resolveEntityScopeFromRequest();
        if ($scope !== null && $scope['entityId'] !== $entityId) {
            throw new \InvalidArgumentException(__('实体范围不匹配'));
        }
    }

    /**
     * @return array{productId:int,structureScope:string,selectedSetId:int}
     */
    private function resolveStructureContext(): array
    {
        return [
            'productId' => max(0, (int)($this->request->getParam('product_id') ?? 0)),
            'structureScope' => strtolower(trim((string)($this->request->getParam('structure_scope') ?: 'catalog'))),
            'selectedSetId' => max(0, (int)($this->request->getParam('selected_set_id') ?? 0)),
        ];
    }

    private function isProductFreeSetCode(string $code): bool
    {
        return strtolower(trim($code)) === '__product_free';
    }

    private function resolveProductFreeSetId(int $entityId): int
    {
        $query = clone $this->eavSet;
        $query->where(Set::schema_fields_eav_entity_id, $entityId);
        $query->where(Set::schema_fields_code, '__product_free');
        $set = $query->find()->fetchArray();

        return (int)($set['set_id'] ?? 0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getProductFreeGroups(int $entityId, int $productId): array
    {
        $freeSetId = $this->resolveProductFreeSetId($entityId);
        if ($freeSetId <= 0) {
            return [];
        }

        $query = clone $this->eavGroup;
        $query->loadLocalDescription();
        $query->where('main_table.set_id', $freeSetId);
        $query->where('main_table.' . Group::schema_fields_scope_product_id, $productId);
        $groups = $query->select()->fetchArray();
        $children = [];
        foreach ($groups as $group) {
            $children[] = $this->formatGroupNode($group);
        }

        return $children;
    }

    private function loadSetNode(int $setId): ?array
    {
        if ($setId <= 0) {
            return null;
        }
        $query = clone $this->eavSet;
        $query->loadLocalDescription();
        $query->where('main_table.set_id', $setId);
        $set = $query->find()->fetchArray();

        return $set !== [] ? $this->formatSetNode($set) : null;
    }

    /**
     * @param array<string, mixed> $typeRow
     */
    private function attributeTypeSupportsOptions(array $typeRow): bool
    {
        if (!empty($typeRow['data_has_option'])) {
            return true;
        }
        $element = strtolower(trim((string)($typeRow['type_element'] ?? $typeRow['element'] ?? '')));

        return in_array($element, ['select', 'multiselect', 'radio'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadAttributeTypeRow(int $typeId): array
    {
        if ($typeId <= 0) {
            return [];
        }
        $type = clone $this->eavType;
        $type->load($typeId);

        return (int)$type->getId() ? $type->getData() : [];
    }

    private function attributeHasEntityData(EavAttribute $attribute): bool
    {
        if ((int)$attribute->getAttributeId() <= 0) {
            return false;
        }
        try {
            $valueModel = $attribute->w_getValueModel();
            $rows = $valueModel
                ->where(Value::schema_fields_attribute_id, (int)$attribute->getAttributeId())
                ->limit(1)
                ->select()
                ->fetchArray();

            return $rows !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertAttributeEditable(EavAttribute $attribute): void
    {
        if ((int)$attribute->getAttributeId() <= 0) {
            return;
        }
        if ($attribute->getData('is_system')) {
            throw new \InvalidArgumentException(__('系统属性不可修改'));
        }
        if ($this->attributeHasEntityData($attribute)) {
            throw new \InvalidArgumentException(__('属性已有实例数据，不可修改'));
        }
    }

    /**
     * @param array<string, mixed> $attribute
     * @return array<string, mixed>
     */
    private function enrichAttributeDetail(array $attribute, EavAttribute $attributeModel): array
    {
        $attribute = $this->normalizeLocalizedStructureRow($attribute);
        $isSystem = !empty($attribute['is_system']);
        $hasEntityData = $this->attributeHasEntityData($attributeModel);
        $attribute['has_entity_data'] = $hasEntityData;
        $attribute['can_edit'] = !$isSystem && !$hasEntityData;
        $attribute['can_delete'] = $attribute['can_edit'];
        $attribute['can_edit_options'] = $this->attributeTypeSupportsOptions($attribute);
        $attribute['type_locked'] = true;
        if ($isSystem) {
            $attribute['edit_lock_reason'] = (string)__('系统属性不可修改');
        } elseif ($hasEntityData) {
            $attribute['edit_lock_reason'] = (string)__('属性已有实例数据，不可修改');
        } else {
            $attribute['edit_lock_reason'] = '';
        }

        return $attribute;
    }

    /**
     * @param array<int, array<string, mixed>> $options
     */
    private function syncAttributeOptions(int $attributeId, int $entityId, array $options): void
    {
        /** @var Option $optionModel */
        $optionModel = \Weline\Framework\Manager\ObjectManager::getInstance(Option::class);
        $keptIds = [];

        foreach ($options as $optionData) {
            if (!is_array($optionData)) {
                continue;
            }
            if (!empty($optionData['_delete'])) {
                continue;
            }
            $code = trim((string)($optionData['code'] ?? ''));
            $value = trim((string)($optionData['value'] ?? ''));
            if ($code === '' && $value === '') {
                continue;
            }

            $optionId = (int)($optionData['option_id'] ?? 0);
            $option = clone $optionModel;
            if ($optionId > 0) {
                $option->load($optionId);
                if ((int)$option->getData(Option::schema_fields_attribute_id) !== $attributeId) {
                    continue;
                }
            }

            $option->setData(Option::schema_fields_attribute_id, $attributeId);
            $option->setData(Option::schema_fields_eav_entity_id, $entityId);
            $option->setData(Option::schema_fields_code, $code);
            $option->setData(Option::schema_fields_value, $value !== '' ? $value : $code);
            $option->setData(Option::schema_fields_swatch_image, trim((string)($optionData['swatch_image'] ?? '')));
            $option->setData(Option::schema_fields_swatch_color, trim((string)($optionData['swatch_color'] ?? '')));
            $option->setData(Option::schema_fields_swatch_text, trim((string)($optionData['swatch_text'] ?? '')));
            $option->save();
            $savedOptionId = (int)$option->getId();
            $this->localTranslationService->syncSourceLocale(
                'option',
                $savedOptionId,
                (string)$option->getData(Option::schema_fields_value),
            );
            $keptIds[] = $savedOptionId;
        }

        $existing = clone $optionModel;
        $existingRows = $existing
            ->where(Option::schema_fields_attribute_id, $attributeId)
            ->select()
            ->fetchArray();
        foreach ($existingRows as $row) {
            $optionId = (int)($row[Option::schema_fields_option_id] ?? 0);
            if ($optionId > 0 && !in_array($optionId, $keptIds, true)) {
                (clone $optionModel)->load($optionId)->delete();
            }
        }
    }

    private function isDefaultStructureCode(string $code): bool
    {
        return strtolower(trim($code)) === self::DEFAULT_STRUCTURE_CODE;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeLocalizedStructureRow(array $row): array
    {
        $row['source_name'] = (string)($row['name'] ?? '');

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function enrichStructureDetail(array $row, string $type): array
    {
        $row = $this->normalizeLocalizedStructureRow($row);
        $isDefault = $this->isDefaultStructureCode((string)($row['code'] ?? ''));
        $row['is_default'] = $isDefault;
        $row['can_edit'] = !$isDefault;

        if ($type === 'entity') {
            $entityId = (int)($row['eav_entity_id'] ?? 0);
            $row['can_delete'] = empty($row['is_system']) && !$this->entityHasAttributeData($entityId);
        } elseif ($type === 'set' || $type === 'group') {
            $row['can_delete'] = !$isDefault;
        }

        return $row;
    }

    private function ensureDefaultStructure(int $entityId): void
    {
        if ($entityId <= 0) {
            return;
        }
        $defaultSet = $this->resolveDefaultSet($entityId, true);
        $this->resolveDefaultGroup($entityId, true, (int)$defaultSet->getId());
    }

    private function resolveDefaultSet(int $entityId, bool $create = false): Set
    {
        $set = clone $this->eavSet;
        $set->clearData()
            ->where(Set::schema_fields_eav_entity_id, $entityId)
            ->where(Set::schema_fields_code, self::DEFAULT_STRUCTURE_CODE)
            ->find()
            ->fetch();
        if ((int)$set->getId()) {
            return $set;
        }
        if (!$create) {
            return $set;
        }

        $set->clearData();
        $set->setData(Set::schema_fields_eav_entity_id, $entityId);
        $set->setData(Set::schema_fields_code, self::DEFAULT_STRUCTURE_CODE);
        $set->setData(Set::schema_fields_name, (string)__('默认属性集'));
        $set->save();

        return $set;
    }

    private function resolveDefaultGroup(int $entityId, bool $create = false, int $defaultSetId = 0): Group
    {
        $group = clone $this->eavGroup;
        $group->clearData()
            ->where(Group::schema_fields_eav_entity_id, $entityId)
            ->where(Group::schema_fields_code, self::DEFAULT_STRUCTURE_CODE)
            ->find()
            ->fetch();
        if ((int)$group->getId()) {
            return $group;
        }
        if (!$create) {
            return $group;
        }

        if ($defaultSetId <= 0) {
            $defaultSetId = (int)$this->resolveDefaultSet($entityId, true)->getId();
        }

        $group->clearData();
        $group->setData(Group::schema_fields_eav_entity_id, $entityId);
        $group->setData(Group::schema_fields_set_id, $defaultSetId);
        $group->setData(Group::schema_fields_code, self::DEFAULT_STRUCTURE_CODE);
        $group->setData(Group::schema_fields_name, (string)__('默认属性组'));
        $group->save();

        return $group;
    }

    private function deleteAttributeRecord(int $attributeId): void
    {
        $attribute = clone $this->eavAttribute;
        $attribute->load($attributeId);
        if (!(int)$attribute->getAttributeId()) {
            throw new \InvalidArgumentException(__('记录不存在'));
        }
        if ($attribute->getData('is_system')) {
            throw new \InvalidArgumentException(__('系统记录不能删除'));
        }
        if ($this->attributeHasEntityData($attribute)) {
            throw new \InvalidArgumentException(__('属性已有实体数据，不能删除'));
        }

        /** @var Option $optionModel */
        $optionModel = \Weline\Framework\Manager\ObjectManager::getInstance(Option::class);
        $options = clone $optionModel;
        foreach ($options->where(Option::schema_fields_attribute_id, $attributeId)->select()->fetchArray() as $row) {
            (clone $optionModel)->load((int)$row[Option::schema_fields_option_id])->delete();
        }

        /** @var Placement $placementModel */
        $placementModel = \Weline\Framework\Manager\ObjectManager::getInstance(Placement::class);
        $placements = clone $placementModel;
        foreach ($placements->where(Placement::schema_fields_attribute_id, $attributeId)->select()->fetchArray() as $row) {
            (clone $placementModel)->load((int)$row[Placement::schema_fields_placement_id])->delete();
        }

        $attribute->delete();
    }

    private function deleteGroupRecord(int $groupId): void
    {
        $group = clone $this->eavGroup;
        $group->load($groupId);
        if (!(int)$group->getId()) {
            throw new \InvalidArgumentException(__('记录不存在'));
        }
        if ($this->isDefaultStructureCode((string)$group->getData(Group::schema_fields_code))) {
            throw new \InvalidArgumentException(__('默认属性组不能删除'));
        }

        $entityId = (int)$group->getData(Group::schema_fields_eav_entity_id);
        $this->assertEntityScope($entityId);
        $this->releaseAttributesFromGroup($entityId, $groupId);
        $group->delete();
    }

    private function deleteSetRecord(int $setId): void
    {
        $set = clone $this->eavSet;
        $set->load($setId);
        if (!(int)$set->getId()) {
            throw new \InvalidArgumentException(__('记录不存在'));
        }
        if ($this->isDefaultStructureCode((string)$set->getData(Set::schema_fields_code))) {
            throw new \InvalidArgumentException(__('默认属性集不能删除'));
        }

        $entityId = (int)$set->getData(Set::schema_fields_eav_entity_id);
        $this->assertEntityScope($entityId);
        $this->releaseStructureFromSet($entityId, $setId);
        $set->delete();
    }

    private function deleteEntityRecord(int $entityId): void
    {
        $entity = clone $this->eavEntity;
        $entity->load($entityId);
        if (!(int)$entity->getId()) {
            throw new \InvalidArgumentException(__('记录不存在'));
        }
        if ($entity->getData('is_system')) {
            throw new \InvalidArgumentException(__('系统记录不能删除'));
        }
        if ($this->entityHasAttributeData($entityId)) {
            throw new \InvalidArgumentException(__('实体已有属性数据引用，不能删除'));
        }

        $attributes = clone $this->eavAttribute;
        $attributeRows = $attributes
            ->where(EavAttribute::schema_fields_eav_entity_id, $entityId)
            ->select()
            ->fetchArray();
        foreach ($attributeRows as $row) {
            if (!empty($row['is_system'])) {
                continue;
            }
            $this->deleteAttributeRecord((int)($row[EavAttribute::schema_fields_ID] ?? 0));
        }

        $groups = clone $this->eavGroup;
        foreach ($groups->where(Group::schema_fields_eav_entity_id, $entityId)->select()->fetchArray() as $row) {
            (clone $this->eavGroup)->load((int)$row[Group::schema_fields_group_id])->delete();
        }

        $sets = clone $this->eavSet;
        foreach ($sets->where(Set::schema_fields_eav_entity_id, $entityId)->select()->fetchArray() as $row) {
            (clone $this->eavSet)->load((int)$row[Set::schema_fields_set_id])->delete();
        }

        $entity->delete();
    }

    private function releaseAttributesFromGroup(int $entityId, int $fromGroupId): void
    {
        $defaultSetId = (int)$this->resolveDefaultSet($entityId, true)->getId();
        $defaultGroupId = (int)$this->resolveDefaultGroup($entityId, true, $defaultSetId)->getId();
        if ($defaultGroupId <= 0) {
            throw new \InvalidArgumentException(__('默认属性组不存在'));
        }

        $this->moveAttributesToDefault($entityId, $defaultSetId, $defaultGroupId, null, $fromGroupId);
        $this->movePlacementsToDefault($entityId, $defaultSetId, $defaultGroupId, null, $fromGroupId);
    }

    private function releaseStructureFromSet(int $entityId, int $fromSetId): void
    {
        $defaultSetId = (int)$this->resolveDefaultSet($entityId, true)->getId();
        $defaultGroupId = (int)$this->resolveDefaultGroup($entityId, true, $defaultSetId)->getId();
        if ($defaultSetId <= 0 || $defaultGroupId <= 0) {
            throw new \InvalidArgumentException(__('默认属性结构不存在'));
        }

        $this->moveAttributesToDefault($entityId, $defaultSetId, $defaultGroupId, $fromSetId, null);
        $this->movePlacementsToDefault($entityId, $defaultSetId, $defaultGroupId, $fromSetId, null);

        $groups = clone $this->eavGroup;
        foreach ($groups->where(Group::schema_fields_set_id, $fromSetId)->select()->fetchArray() as $row) {
            $groupId = (int)($row[Group::schema_fields_group_id] ?? 0);
            if ($groupId <= 0 || $this->isDefaultStructureCode((string)($row[Group::schema_fields_code] ?? ''))) {
                continue;
            }
            (clone $this->eavGroup)->load($groupId)->delete();
        }
    }

    private function moveAttributesToDefault(
        int $entityId,
        int $defaultSetId,
        int $defaultGroupId,
        ?int $fromSetId,
        ?int $fromGroupId,
    ): void {
        $query = clone $this->eavAttribute;
        $query->where(EavAttribute::schema_fields_eav_entity_id, $entityId);
        if ($fromSetId !== null) {
            $query->where(EavAttribute::schema_fields_set_id, $fromSetId);
        }
        if ($fromGroupId !== null) {
            $query->where(EavAttribute::schema_fields_group_id, $fromGroupId);
        }

        foreach ($query->select()->fetchArray() as $row) {
            $attribute = clone $this->eavAttribute;
            $attribute->load((int)($row[EavAttribute::schema_fields_ID] ?? 0));
            if (!(int)$attribute->getAttributeId()) {
                continue;
            }
            $attribute->setData(EavAttribute::schema_fields_set_id, $defaultSetId);
            $attribute->setData(EavAttribute::schema_fields_group_id, $defaultGroupId);
            $attribute->save();
        }
    }

    private function movePlacementsToDefault(
        int $entityId,
        int $defaultSetId,
        int $defaultGroupId,
        ?int $fromSetId,
        ?int $fromGroupId,
    ): void {
        /** @var Placement $placementModel */
        $placementModel = \Weline\Framework\Manager\ObjectManager::getInstance(Placement::class);
        $query = clone $placementModel;
        $query->where(Placement::schema_fields_eav_entity_id, $entityId);
        if ($fromSetId !== null) {
            $query->where(Placement::schema_fields_set_id, $fromSetId);
        }
        if ($fromGroupId !== null) {
            $query->where(Placement::schema_fields_group_id, $fromGroupId);
        }

        foreach ($query->select()->fetchArray() as $row) {
            $placementId = (int)($row[Placement::schema_fields_placement_id] ?? 0);
            if ($placementId <= 0) {
                continue;
            }
            $placement = clone $placementModel;
            $placement->load($placementId);
            if (!(int)$placement->getId()) {
                continue;
            }

            $attributeId = (int)$placement->getData(Placement::schema_fields_attribute_id);
            $home = clone $this->eavAttribute;
            $home->load($attributeId);
            if ((int)$home->getAttributeId()
                && (int)$home->getData(EavAttribute::schema_fields_set_id) === $defaultSetId
                && (int)$home->getData(EavAttribute::schema_fields_group_id) === $defaultGroupId) {
                $placement->delete();
                continue;
            }

            $duplicate = clone $placementModel;
            $duplicate->clearData()
                ->where(Placement::schema_fields_attribute_id, $attributeId)
                ->where(Placement::schema_fields_set_id, $defaultSetId)
                ->find()
                ->fetch();
            if ((int)$duplicate->getId() && (int)$duplicate->getId() !== $placementId) {
                $placement->delete();
                continue;
            }

            $placement->setData(Placement::schema_fields_set_id, $defaultSetId);
            $placement->setData(Placement::schema_fields_group_id, $defaultGroupId);
            $placement->save();
        }
    }

    private function entityHasAttributeData(int $entityId): bool
    {
        if ($entityId <= 0) {
            return false;
        }

        $attributes = clone $this->eavAttribute;
        $rows = $attributes
            ->where(EavAttribute::schema_fields_eav_entity_id, $entityId)
            ->select()
            ->fetchArray();
        foreach ($rows as $row) {
            $attribute = clone $this->eavAttribute;
            $attribute->load((int)($row[EavAttribute::schema_fields_ID] ?? 0));
            if ((int)$attribute->getAttributeId() && $this->attributeHasEntityData($attribute)) {
                return true;
            }
        }

        return false;
    }
}
