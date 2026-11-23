<?php

declare(strict_types=1);

namespace Weline\Agent\Service;

use Weline\Agent\Model\AgentRole;
use Weline\Framework\Manager\ObjectManager;

/**
 * 角色汇报链（P2）：parent_role_id → 上级角色解析。
 *
 * 语义：任务先给对应角色处理；AI 判断处理不了时沿 parent 链向上汇报，
 * 禁止越级直达顶层（opc_boss 只收直属下级链路的升级）。
 */
class RoleHierarchyService
{
    /** 角色的上级角色（无上级返回 null） */
    public function parentOf(int $roleId): ?AgentRole
    {
        if ($roleId <= 0) {
            return null;
        }
        try {
            $prototype = ObjectManager::getInstance(AgentRole::class);
            $role = clone $prototype;
            $role->clearData()->load($roleId);
            if (!$role->getId()) {
                return null;
            }
            $parentId = (int)$role->getData(AgentRole::schema_fields_PARENT_ROLE_ID);
            if ($parentId <= 0) {
                return null;
            }
            $parent = clone $prototype;
            $parent->clearData()->load($parentId);
            return $parent->getId() ? $parent : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 汇报链（自身 → 上级 → … → 顶层），最多 8 层防环。
     * @return AgentRole[]
     */
    public function chain(int $roleId): array
    {
        $chain = [];
        $seen = [];
        $current = $roleId;
        while ($current > 0 && !isset($seen[$current]) && count($chain) < 8) {
            $seen[$current] = true;
            try {
                $prototype = ObjectManager::getInstance(AgentRole::class);
                $role = clone $prototype;
                $role->clearData()->load($current);
                if (!$role->getId()) {
                    break;
                }
                $chain[] = $role;
                $current = (int)$role->getData(AgentRole::schema_fields_PARENT_ROLE_ID);
            } catch (\Throwable) {
                break;
            }
        }
        return $chain;
    }

    /** 汇报链文本（注入系统提示词）："你（X）→ 上级（Y）→ 顶层（Z）" */
    public function reportLineText(int $roleId): string
    {
        $chain = $this->chain($roleId);
        if (count($chain) < 2) {
            return '';
        }
        $parts = [];
        foreach ($chain as $i => $role) {
            $name = (string)$role->getData(AgentRole::schema_fields_NAME);
            $parts[] = $i === 0 ? "你（{$name}）" : $name;
        }
        return implode(' → ', $parts);
    }
}
