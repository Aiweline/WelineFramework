<?php
declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Model\Acl;
use Weline\Framework\Manager\ObjectManager;

/**
 * ACL 资源验证服务
 * 
 * 负责：
 * - 检测 parent_source 循环引用
 * - 验证 parent/type 组合规则
 * - 检测 source_id 冲突
 */
class AclValidationService
{
    /**
     * 允许的 parent/type 组合规则
     * 
     * - menus -> menus：允许（菜单下可以有菜单）
     * - menus -> pc：允许（菜单下可以有控制器权限）
     * - pc(class) -> menus：允许（控制器类可以有菜单父级）
     * - pc(class) -> pc：允许（控制器类可以有控制器父级）
     * - pc(method) -> pc(class)：默认规则（方法继承自类）
     */
    private const ALLOWED_PARENT_RULES = [
        'menus->menus' => true,
        'menus->pc' => true,
        'pc->menus' => true,
        'pc->pc' => true,
    ];

    /**
     * 检测 parent_source 循环引用
     * 
     * @param string $sourceId 当前资源 ID
     * @param string $parentSource 要设置的父级资源 ID
     * @return array{valid: bool, message: string}
     */
    public function checkParentCycle(string $sourceId, string $parentSource): array
    {
        if ($parentSource === '' || $sourceId === '') {
            return ['valid' => true, 'message' => ''];
        }

        // 不能以自己为父级
        if ($sourceId === $parentSource) {
            return [
                'valid' => false,
                'message' => __('资源不能以自己为父级： %{1}', [$sourceId])
            ];
        }

        // 加载所有 ACL 资源，构建 parent 映射
        $aclModel = ObjectManager::getInstance(Acl::class, [], false);
        $allAcl = $aclModel->reset()
            ->select()
            ->fetchArray();

        $parentMap = [];
        foreach ($allAcl as $acl) {
            $sid = $acl[Acl::schema_fields_SOURCE_ID] ?? '';
            $pid = $acl[Acl::schema_fields_PARENT_SOURCE] ?? '';
            if ($sid !== '') {
                $parentMap[$sid] = $pid;
            }
        }

        // 检查新父级是否是当前资源的后代
        $current = $parentSource;
        $visited = [$sourceId];
        while ($current !== '' && $current !== null) {
            if (in_array($current, $visited, true)) {
                return [
                    'valid' => false,
                    'message' => __('检测到循环引用： %{1} -> %{2}', [$sourceId, $parentSource])
                ];
            }
            // 如果新父级的祖先中包含当前资源，则形成循环
            if ($current === $sourceId) {
                return [
                    'valid' => false,
                    'message' => __('设置此父级会形成循环引用： %{1} -> %{2}', [$sourceId, $parentSource])
                ];
            }
            $visited[] = $current;
            $current = $parentMap[$current] ?? '';
        }

        return ['valid' => true, 'message' => ''];
    }

    /**
     * 验证 parent/type 组合规则
     * 
     * @param string $type 当前资源类型
     * @param string $parentSource 父级资源 ID
     * @return array{valid: bool, message: string}
     */
    public function validateParentTypeRule(string $type, string $parentSource): array
    {
        if ($parentSource === '') {
            return ['valid' => true, 'message' => ''];
        }

        // 获取父级的类型
        $aclModel = ObjectManager::getInstance(Acl::class, [], false);
        $parent = $aclModel->load($parentSource, Acl::schema_fields_SOURCE_ID);
        
        if (!$parent->getSourceId()) {
            // 父级不存在，允许设置（可能是还未创建的父级）
            return ['valid' => true, 'message' => ''];
        }

        $parentType = $parent->getType();
        
        // 检查规则
        $ruleKey = $parentType . '->' . $type;
        
        // 所有组合都允许，但记录警告信息供调试
        if (!isset(self::ALLOWED_PARENT_RULES[$ruleKey])) {
            // 未知规则组合，但仍然允许
            return [
                'valid' => true,
                'message' => __('非标准父子类型组合： %{1} -> %{2}', [$parentType, $type])
            ];
        }

        return ['valid' => true, 'message' => ''];
    }

    /**
     * 检测 source_id 是否存在冲突
     * 
     * @param string $sourceId 资源 ID
     * @param int|null $excludeAclId 排除的 ACL ID（用于更新时排除自身）
     * @return array{valid: bool, message: string, existing?: array}
     */
    public function checkSourceIdConflict(string $sourceId, ?int $excludeAclId = null): array
    {
        if ($sourceId === '') {
            return ['valid' => false, 'message' => __('资源 ID 不能为空')];
        }

        $aclModel = ObjectManager::getInstance(Acl::class, [], false);
        $existing = $aclModel->load($sourceId, Acl::schema_fields_SOURCE_ID);

        if ($existing->getSourceId() && $existing->getAclId() !== $excludeAclId) {
            return [
                'valid' => false,
                'message' => __('资源 ID 已存在： %{1}', [$sourceId]),
                'existing' => $existing->getData()
            ];
        }

        return ['valid' => true, 'message' => ''];
    }

    /**
     * 验证是否可以删除菜单资源
     * 
     * @param string $sourceId 资源 ID
     * @return array{valid: bool, message: string, children?: array}
     */
    public function canDeleteMenuResource(string $sourceId): array
    {
        // 检查是否有子菜单
        $aclModel = ObjectManager::getInstance(Acl::class, [], false);
        $children = $aclModel->reset()
            ->where(Acl::schema_fields_PARENT_SOURCE, $sourceId)
            ->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
            ->select()
            ->fetchArray();

        if (!empty($children)) {
            $childIds = array_column($children, Acl::schema_fields_SOURCE_ID);
            return [
                'valid' => false,
                'message' => __('该菜单下有 %{1} 个子菜单，请先删除子菜单', [count($children)]),
                'children' => $childIds
            ];
        }

        return ['valid' => true, 'message' => ''];
    }

    /**
     * 全面验证 ACL 资源数据
     * 
     * @param array $data 资源数据
     * @param int|null $excludeAclId 排除的 ACL ID（用于更新）
     * @return array{valid: bool, errors: array<string>}
     */
    public function validateAclData(array $data, ?int $excludeAclId = null): array
    {
        $errors = [];

        $sourceId = $data[Acl::schema_fields_SOURCE_ID] ?? '';
        $parentSource = $data[Acl::schema_fields_PARENT_SOURCE] ?? '';
        $type = $data[Acl::schema_fields_TYPE] ?? '';

        // 检查 source_id 冲突
        $sourceResult = $this->checkSourceIdConflict($sourceId, $excludeAclId);
        if (!$sourceResult['valid']) {
            $errors[] = $sourceResult['message'];
        }

        // 检查循环引用
        if ($sourceId !== '' && $parentSource !== '') {
            $cycleResult = $this->checkParentCycle($sourceId, $parentSource);
            if (!$cycleResult['valid']) {
                $errors[] = $cycleResult['message'];
            }
        }

        // 验证 parent/type 规则
        if ($type !== '' && $parentSource !== '') {
            $ruleResult = $this->validateParentTypeRule($type, $parentSource);
            if (!$ruleResult['valid']) {
                $errors[] = $ruleResult['message'];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}
