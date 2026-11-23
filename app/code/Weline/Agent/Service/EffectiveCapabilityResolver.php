<?php
declare(strict_types=1);

namespace Weline\Agent\Service;

use Weline\Agent\Model\AgentPlugin;
use Weline\Agent\Model\AgentRole;
use Weline\Framework\Manager\ObjectManager;

/**
 * 有效能力解析器
 *
 * effective_skills(role) = role.skills ∪ ⋃ enabled plugins.manifest.skills
 * 插件失效（disabled/missing）在运行时剔除，不改 role.plugins 数据。
 */
final class EffectiveCapabilityResolver
{
    /** @return list<string> 去重后的技能码列表 */
    public function resolveSkillCodes(AgentRole $role): array
    {
        $codes = $role->getSkills();
        foreach ($this->resolvePlugins($role) as $plugin) {
            foreach ($plugin->getSkillCodes() as $skillCode) {
                $codes[] = $skillCode;
            }
        }

        return array_values(array_unique($codes));
    }

    /** @return list<AgentPlugin> 仅 enabled 状态的插件实例 */
    public function resolvePlugins(AgentRole $role): array
    {
        $pluginCodes = $role->getPlugins();
        if ($pluginCodes === []) {
            return [];
        }

        try {
            $model = ObjectManager::getInstance(AgentPlugin::class);
        } catch (\Throwable) {
            return [];
        }

        $plugins = [];
        foreach ($pluginCodes as $code) {
            $code = trim((string)$code);
            if ($code === '') {
                continue;
            }
            try {
                $plugin = clone $model;
                $plugin->reset()->clearQuery()
                    ->where(AgentPlugin::schema_fields_CODE, $code)
                    ->where(AgentPlugin::schema_fields_STATUS, AgentPlugin::STATUS_ENABLED)
                    ->find()
                    ->fetch();
                if ($plugin->getId() && $plugin->isEnabled()) {
                    $plugins[] = $plugin;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $plugins;
    }

    /**
     * 返回角色技能码 → 生效来源映射（builtin/plugin:<code>），供治理页展示。
     *
     * @return array<string, string>
     */
    public function resolveSkillSources(AgentRole $role): array
    {
        $sources = [];
        foreach ($role->getSkills() as $code) {
            $sources[(string)$code] = 'role';
        }
        foreach ($this->resolvePlugins($role) as $plugin) {
            foreach ($plugin->getSkillCodes() as $code) {
                $sources[(string)$code] = 'plugin:' . $plugin->getData(AgentPlugin::schema_fields_CODE);
            }
        }

        return $sources;
    }
}
