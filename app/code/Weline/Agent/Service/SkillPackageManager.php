<?php

declare(strict_types=1);

namespace Weline\Agent\Service;

use Weline\Agent\Model\AgentRole;
use Weline\Agent\Model\AgentSkill;

class SkillPackageManager
{
    public function __construct(
        private readonly AgentSkill $skillModel,
    ) {}

    public function getSkill(string $code): ?AgentSkill
    {
        $skill = $this->skillModel->reset()
            ->where(AgentSkill::schema_fields_CODE, $code)
            ->where(AgentSkill::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();

        return $skill->getId() ? $skill : null;
    }

    public function getAllSkills(): array
    {
        $skills = $this->skillModel->reset()
            ->where(AgentSkill::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        return $skills->getItems();
    }

    public function getSkillsForRole(AgentRole $role): array
    {
        $skillCodes = $role->getSkills();
        if (empty($skillCodes)) {
            return [];
        }

        $skills = $this->skillModel->reset()
            ->where(AgentSkill::schema_fields_CODE, $skillCodes, 'IN')
            ->where(AgentSkill::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        return $skills->getItems();
    }

    public function getToolsForRole(AgentRole $role): array
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
                'name' => (string)$skill->getData(AgentSkill::schema_fields_CODE),
                'description' => (string)($skill->getData(AgentSkill::schema_fields_DESCRIPTION) ?? ''),
                'parameters' => $parameters,
            ];
        }

        return $tools;
    }

    public function install(array $skillData): AgentSkill
    {
        $skill = $this->skillModel;
        $skill->setData(AgentSkill::schema_fields_CODE, $skillData['code']);
        $skill->setData(AgentSkill::schema_fields_NAME, $skillData['name']);
        $skill->setData(AgentSkill::schema_fields_DESCRIPTION, $skillData['description'] ?? '');
        $skill->setData(AgentSkill::schema_fields_CATEGORY, $skillData['category'] ?? 'api');

        if (isset($skillData['class_name'])) {
            $skill->setData(AgentSkill::schema_fields_CLASS_NAME, $skillData['class_name']);
        }
        if (isset($skillData['parameters'])) {
            $skill->setParameters($skillData['parameters']);
        }
        if (isset($skillData['permission_required'])) {
            $skill->setPermissionRequired($skillData['permission_required']);
        }
        if (isset($skillData['is_dangerous'])) {
            $skill->setData(AgentSkill::schema_fields_IS_DANGEROUS, $skillData['is_dangerous'] ? 1 : 0);
        }
        if (isset($skillData['requires_confirmation'])) {
            $skill->setData(AgentSkill::schema_fields_REQUIRES_CONFIRMATION, $skillData['requires_confirmation'] ? 1 : 0);
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

        if ($skill->getData(AgentSkill::schema_fields_IS_BUILTIN)) {
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

        $skill->setData(AgentSkill::schema_fields_IS_ACTIVE, 0);
        $skill->save();
        return true;
    }

    public function enable(string $code): bool
    {
        $skill = $this->skillModel->reset()
            ->where(AgentSkill::schema_fields_CODE, $code)
            ->find()
            ->fetch();

        if (!$skill->getId()) {
            return false;
        }

        $skill->setData(AgentSkill::schema_fields_IS_ACTIVE, 1);
        $skill->save();
        return true;
    }

    public function getSkillsByCategory(string $category): array
    {
        $skills = $this->skillModel->reset()
            ->where(AgentSkill::schema_fields_CATEGORY, $category)
            ->where(AgentSkill::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        return $skills->getItems();
    }
}