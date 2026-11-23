<?php

declare(strict_types=1);

namespace Weline\Agent\Setup;

use Weline\Agent\Model\AgentRole;
use Weline\Framework\Manager\ObjectManager;

/**
 * 通用默认角色（general）种子/对齐
 *
 * - 新装：Install 直接写入；
 * - 升级：保证 code=general 存在、启用、is_default=1、agent_code=chat_agent，
 *   并把其它角色的 is_default 清零（general 是「未选角色时的缺省角色」）。
 */
final class GeneralRoleSeeder
{
    public const ROLE_CODE = 'general';
    public const AGENT_CODE = 'chat_agent';

    public function ensure(): void
    {
        try {
            $prototype = ObjectManager::getInstance(AgentRole::class);
        } catch (\Throwable) {
            return;
        }

        $role = clone $prototype;
        $role->reset()->clearQuery()
            ->where(AgentRole::schema_fields_CODE, self::ROLE_CODE)
            ->find()->fetch();

        if (!$role->getId()) {
            // 姓名/模型沿用当前默认角色（若有），保证升级后能力不缩水
            $modelId = null;
            $defaultRole = clone $prototype;
            $defaultRole->reset()->clearQuery()
                ->where(AgentRole::schema_fields_IS_DEFAULT, 1)
                ->find()->fetch();
            if ($defaultRole->getId()) {
                $modelId = (int)$defaultRole->getData(AgentRole::schema_fields_MODEL_ID);
            }

            $now = time();
            $role = clone $prototype;
            $role->reset()->clearQuery();
            $role->setData(AgentRole::schema_fields_CODE, self::ROLE_CODE);
            $role->setData(AgentRole::schema_fields_NAME, '通用助手');
            $role->setData(AgentRole::schema_fields_SYSTEM_PROMPT,
                '你是一个通用智能助手，具备文件操作、Shell执行、网络请求等能力。请根据用户指令安全、高效地完成任务。对于危险操作，请务必先询问用户确认。');
            $role->setData(AgentRole::schema_fields_SKILLS, json_encode(
                ['filesystem.read', 'filesystem.write', 'http.request', 'shell.execute'],
                JSON_UNESCAPED_UNICODE));
            $role->setData(AgentRole::schema_fields_SCENARIO_ADAPTER_CODE, 'agent');
            if ($modelId !== null && $modelId > 0) {
                $role->setData(AgentRole::schema_fields_MODEL_ID, $modelId);
            }
            $role->setData(AgentRole::schema_fields_MODEL_CONFIG, json_encode([
                'temperature' => 0.7,
                'max_tokens' => 4096,
            ], JSON_UNESCAPED_UNICODE));
            $role->setData(AgentRole::schema_fields_STATUS, AgentRole::STATUS_ENABLED);
            $role->setData(AgentRole::schema_fields_IS_DEFAULT, 1);
            $role->setData(AgentRole::schema_fields_DESCRIPTION, '默认通用角色，具备基础文件操作和网络请求能力');
            $role->setData(AgentRole::schema_fields_ICON, 'mdi-robot');
            $role->setData(AgentRole::schema_fields_AGENT_CODE, self::AGENT_CODE);
            $role->setData('created_at', $now);
            $role->setData('updated_at', $now);
            $role->save();
        } else {
            $dirty = false;
            if ($role->getData(AgentRole::schema_fields_AGENT_CODE) !== self::AGENT_CODE) {
                $role->setData(AgentRole::schema_fields_AGENT_CODE, self::AGENT_CODE);
                $dirty = true;
            }
            if ((int)$role->getData(AgentRole::schema_fields_IS_DEFAULT) !== 1) {
                $role->setData(AgentRole::schema_fields_IS_DEFAULT, 1);
                $dirty = true;
            }
            if ((string)$role->getData(AgentRole::schema_fields_STATUS) !== AgentRole::STATUS_ENABLED) {
                $role->setData(AgentRole::schema_fields_STATUS, AgentRole::STATUS_ENABLED);
                $dirty = true;
            }
            if ($dirty) {
                $role->save();
            }
        }

        // general 为唯一默认角色
        try {
            $others = clone $prototype;
            $others->reset()->clearQuery()
                ->where(AgentRole::schema_fields_IS_DEFAULT, 1)
                ->where(AgentRole::schema_fields_CODE, self::ROLE_CODE, '!=')
                ->select()->fetchArray();
            foreach ($others as $row) {
                $id = (int)($row[AgentRole::schema_fields_ROLE_ID] ?? $row['role_id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $other = clone $prototype;
                $other->load($id);
                $other->setData(AgentRole::schema_fields_IS_DEFAULT, 0);
                $other->save();
            }
        } catch (\Throwable) {
        }
    }
}
