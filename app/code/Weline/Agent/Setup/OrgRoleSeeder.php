<?php

declare(strict_types=1);

namespace Weline\Agent\Setup;

use Weline\Agent\Model\AgentRole;
use Weline\Framework\Manager\ObjectManager;

/**
 * 组织层级角色种子：OPC 老板 → 总监 → 主管 → 执行角色
 *
 * parent_role_id 是「禁止越级汇报」的依据：任何角色只向直接上级汇报。
 * 仅补缺/对齐，不覆盖用户已改的名称、提示词与上级关系（上级为空时才回填）。
 */
final class OrgRoleSeeder
{
    /** @var array<int, array{code:string,name:string,prompt:string,parent:string,desc:string}> */
    private const ROLES = [
        'opc_boss' => [
            'code' => 'opc_boss',
            'name' => 'OPC 老板',
            'parent' => '',
            'desc' => '顶层决策角色：接收老板指令，向下授权总监建立项目',
            'prompt' => '你是 OPC（One Person Company）老板，是整个智能体组织的顶层决策者。你接收老板的最终指令，负责定方向、批预算、拍板取舍。日常执行不要越级插手：具体项目交给总监，任务交给主管。只有下级明确上报且你判断必须由你决定时才介入。汇报遵循直接上下级链条，不允许越级。',
        ],
        'director' => [
            'code' => 'director',
            'name' => '总监',
            'parent' => 'opc_boss',
            'desc' => '项目负责人：接收老板/总监指令，建立项目、配置项目节点与角色分工',
            'prompt' => '你是项目总监，向 OPC 老板汇报。你负责把老板的意图拆成项目：创建项目、规划项目节点（每个节点绑定一个角色）、分配主管。你管理项目整体进度与风险，任务执行交给主管和一线角色，不要越级替他们完成具体事务。遇到超出你权限的决策，向上汇报老板。',
        ],
        'manager' => [
            'code' => 'manager',
            'name' => '主管',
            'parent' => 'director',
            'desc' => '项目任务管理者：在项目内拆解任务、派单、跟踪工时与超时',
            'prompt' => '你是项目主管，向总监汇报。你负责项目内的任务管理：拆解任务、派单给对应角色、跟踪预期/实际工时、发现超时记录原因并向上汇报。执行类工作交给对应角色完成，你不越级替一线角色干活。任务完成后把结果与轨迹汇报给总监。',
        ],
    ];

    /** 一线执行角色默认挂到主管下 */
    private const WORKER_PARENT = 'manager';

    public function ensure(): void
    {
        try {
            $prototype = ObjectManager::getInstance(AgentRole::class);
        } catch (\Throwable) {
            return;
        }

        // 先取全量启用角色行（在任何 ensureRole 查询污染共享 query 状态之前物化）
        $workerRows = [];
        try {
            $list = clone $prototype;
            $workerRows = $list->reset()
                ->where(AgentRole::schema_fields_STATUS, AgentRole::STATUS_ENABLED)
                ->limit(500)
                ->select()->fetchArray();
            if (!is_array($workerRows)) {
                $workerRows = [];
            }
        } catch (\Throwable) {
            $workerRows = [];
        }

        $idByCode = [];
        foreach (self::ROLES as $def) {
            $id = $this->ensureRole($prototype, $def);
            if ($id > 0) {
                $idByCode[$def['code']] = $id;
            }
        }

        // 一线角色：parent 为空时挂到主管（general/seo_assistant 等存量角色）
        $managerId = $idByCode['manager'] ?? 0;
        if ($managerId > 0) {
            try {
                foreach ($workerRows as $row) {
                    $id = (int)($row[AgentRole::schema_fields_ROLE_ID] ?? $row['role_id'] ?? 0);
                    $code = (string)($row[AgentRole::schema_fields_CODE] ?? '');
                    if ($id <= 0 || isset(self::ROLES[$code]) || isset($idByCode[$code])) {
                        continue;
                    }
                    $parent = (int)($row[AgentRole::schema_fields_PARENT_ROLE_ID] ?? $row['parent_role_id'] ?? 0);
                    if ($parent > 0) {
                        continue; // 已有上级，不覆盖
                    }
                    $role = clone $prototype;
                    $role->load($id);
                    if (!$role->getId()) {
                        continue;
                    }
                    $role->setData(AgentRole::schema_fields_PARENT_ROLE_ID, $managerId);
                    $role->save();
                }
            } catch (\Throwable $e) {
                if (\defined('STDERR')) {
                    fwrite(STDERR, '[OrgRoleSeeder] worker backfill failed: ' . $e->getMessage() . "\n");
                }
            }
        }
    }

    /** @param array{code:string,name:string,prompt:string,parent:string,desc:string} $def */
    private function ensureRole(AgentRole $prototype, array $def): int
    {
        $role = clone $prototype;
        $role->reset()->clearQuery()
            ->where(AgentRole::schema_fields_CODE, $def['code'])
            ->find()->fetch();

        if (!$role->getId()) {
            $now = time();
            $role = clone $prototype;
            $role->reset()->clearQuery();
            $role->setData(AgentRole::schema_fields_CODE, $def['code']);
            $role->setData(AgentRole::schema_fields_NAME, $def['name']);
            $role->setData(AgentRole::schema_fields_SYSTEM_PROMPT, $def['prompt']);
            $role->setData(AgentRole::schema_fields_DESCRIPTION, $def['desc']);
            $role->setData(AgentRole::schema_fields_STATUS, AgentRole::STATUS_ENABLED);
            $role->setData(AgentRole::schema_fields_IS_DEFAULT, 0);
            $role->setData(AgentRole::schema_fields_ICON, 'mdi-account-tie');
            $role->setData('created_at', $now);
            $role->setData('updated_at', $now);
            $role->save();
        }

        $id = (int)$role->getId();
        if ($id <= 0) {
            return 0;
        }

        // parent 解析：本级定义的 parent code → role_id；空=顶层
        $parentId = 0;
        if ($def['parent'] !== '') {
            $parent = clone $prototype;
            $parent->reset()->clearQuery()
                ->where(AgentRole::schema_fields_CODE, $def['parent'])
                ->find()->fetch();
            $parentId = (int)$parent->getId();
        }

        $dirty = false;
        // 上级为空才回填，尊重用户手工调整
        if ($parentId > 0 && (int)$role->getData(AgentRole::schema_fields_PARENT_ROLE_ID) <= 0) {
            $role->setData(AgentRole::schema_fields_PARENT_ROLE_ID, $parentId);
            $dirty = true;
        }
        if ($dirty) {
            $role->save();
        }
        return $id;
    }
}
