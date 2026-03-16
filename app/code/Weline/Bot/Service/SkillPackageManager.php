<?php
declare(strict_types=1);

namespace Weline\Bot\Service;

use Weline\Bot\Model\BotRole;
use Weline\Bot\Model\BotSkill;

/**
 * 技能包管理器
 *
 * 管理技能的注册、安装、获取
 */
class SkillPackageManager
{
    public function __construct(
        private readonly BotSkill $skillModel,
    ) {}

    /**
     * 获取技能
     */
    public function getSkill(string $code): ?BotSkill
    {
        $skill = $this->skillModel->reset()
            ->where(BotSkill::schema_fields_CODE, $code)
            ->where(BotSkill::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();

        return $skill->getId() ? $skill : null;
    }

    /**
     * 获取所有可用技能
     */
    public function getAllSkills(): array
    {
        $skills = $this->skillModel->reset()
            ->where(BotSkill::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        return $skills->getItems();
    }

    /**
     * 获取角色可用的技能列表
     */
    public function getSkillsForRole(BotRole $role): array
    {
        $skillCodes = $role->getSkills();
        if (empty($skillCodes)) {
            return [];
        }

        $skills = $this->skillModel->reset()
            ->where(BotSkill::schema_fields_CODE, $skillCodes, 'IN')
            ->where(BotSkill::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        return $skills->getItems();
    }

    /**
     * 获取角色可用的技能（OpenAI Tools 格式）
     */
    public function getToolsForRole(BotRole $role): array
    {
        $skills = $this->getSkillsForRole($role);
        $tools = [];

        foreach ($skills as $skill) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $skill->getData(BotSkill::schema_fields_CODE),
                    'description' => $skill->getData(BotSkill::schema_fields_DESCRIPTION),
                    'parameters' => $skill->getParameters(),
                ],
            ];
        }

        return $tools;
    }

    /**
     * 安装技能
     */
    public function install(array $skillData): BotSkill
    {
        $skill = $this->skillModel;
        $skill->setData(BotSkill::schema_fields_CODE, $skillData['code']);
        $skill->setData(BotSkill::schema_fields_NAME, $skillData['name']);
        $skill->setData(BotSkill::schema_fields_DESCRIPTION, $skillData['description'] ?? '');
        $skill->setData(BotSkill::schema_fields_CATEGORY, $skillData['category'] ?? 'api');

        if (isset($skillData['class_name'])) {
            $skill->setData(BotSkill::schema_fields_CLASS_NAME, $skillData['class_name']);
        }
        if (isset($skillData['parameters'])) {
            $skill->setParameters($skillData['parameters']);
        }
        if (isset($skillData['permission_required'])) {
            $skill->setPermissionRequired($skillData['permission_required']);
        }
        if (isset($skillData['is_dangerous'])) {
            $skill->setData(BotSkill::schema_fields_IS_DANGEROUS, $skillData['is_dangerous'] ? 1 : 0);
        }
        if (isset($skillData['requires_confirmation'])) {
            $skill->setData(BotSkill::schema_fields_REQUIRES_CONFIRMATION, $skillData['requires_confirmation'] ? 1 : 0);
        }

        $skill->save();
        return $skill;
    }

    /**
     * 卸载技能
     */
    public function uninstall(string $code): bool
    {
        $skill = $this->getSkill($code);
        if (!$skill) {
            return false;
        }

        // 内置技能不能卸载
        if ($skill->getData(BotSkill::schema_fields_IS_BUILTIN)) {
            return false;
        }

        $skill->delete();
        return true;
    }

    /**
     * 禁用技能
     */
    public function disable(string $code): bool
    {
        $skill = $this->getSkill($code);
        if (!$skill) {
            return false;
        }

        $skill->setData(BotSkill::schema_fields_IS_ACTIVE, 0);
        $skill->save();
        return true;
    }

    /**
     * 启用技能
     */
    public function enable(string $code): bool
    {
        $skill = $this->skillModel->reset()
            ->where(BotSkill::schema_fields_CODE, $code)
            ->find()
            ->fetch();

        if (!$skill->getId()) {
            return false;
        }

        $skill->setData(BotSkill::schema_fields_IS_ACTIVE, 1);
        $skill->save();
        return true;
    }

    /**
     * 按分类获取技能
     */
    public function getSkillsByCategory(string $category): array
    {
        $skills = $this->skillModel->reset()
            ->where(BotSkill::schema_fields_CATEGORY, $category)
            ->where(BotSkill::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        return $skills->getItems();
    }
}
