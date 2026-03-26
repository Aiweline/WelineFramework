<?php

declare(strict_types=1);

namespace Weline\Bot\Service;

use Weline\Bot\Model\BotRole;
use Weline\Bot\Model\BotSkill;

class SkillPackageManager
{
    public function __construct(
        private readonly BotSkill $skillModel,
    ) {}

    public function getSkill(string $code): ?BotSkill
    {
        $skill = $this->skillModel->reset()
            ->where(BotSkill::schema_fields_CODE, $code)
            ->where(BotSkill::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();

        return $skill->getId() ? $skill : null;
    }

    public function getAllSkills(): array
    {
        $skills = $this->skillModel->reset()
            ->where(BotSkill::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        return $skills->getItems();
    }

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

    public function getToolsForRole(BotRole $role): array
    {
        $skills = $this->getSkillsForRole($role);
        $tools = [];

        foreach ($skills as $skill) {
            $parameters = $skill->getParameters();
            if (!is_array($parameters) || empty($parameters)) {
                $parameters = [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ];
            }

            $tools[] = [
                'name' => (string)$skill->getData(BotSkill::schema_fields_CODE),
                'description' => (string)($skill->getData(BotSkill::schema_fields_DESCRIPTION) ?? ''),
                'parameters' => $parameters,
            ];
        }

        return $tools;
    }

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

    public function uninstall(string $code): bool
    {
        $skill = $this->getSkill($code);
        if (!$skill) {
            return false;
        }

        if ($skill->getData(BotSkill::schema_fields_IS_BUILTIN)) {
            return false;
        }

        $skill->delete();
        return true;
    }

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