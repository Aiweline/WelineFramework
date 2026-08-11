<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Model\WlsPanelProject;

class WlsPanelProjectRegistryService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProjects(): array
    {
        return $this->freshProject()->getAllProjects();
    }

    /**
     * @return array<string, mixed>
     */
    public function getFormData(int $projectId = 0): array
    {
        if ($projectId > 0) {
            $project = $this->loadProject($projectId);
            if ($project->getData(WlsPanelProject::schema_fields_ID)) {
                return $project->getData();
            }
        }

        return [
            WlsPanelProject::schema_fields_ID => 0,
            WlsPanelProject::schema_fields_NAME => '',
            WlsPanelProject::schema_fields_DOMAIN => '',
            WlsPanelProject::schema_fields_ADMIN_URL => '',
            WlsPanelProject::schema_fields_PANEL_URL => '',
            WlsPanelProject::schema_fields_PROJECT_PATH => '',
            WlsPanelProject::schema_fields_PHP_PROFILE => '',
            WlsPanelProject::schema_fields_DATABASE_PROFILE => '',
            WlsPanelProject::schema_fields_STATUS => WlsPanelProject::STATUS_ACTIVE,
            WlsPanelProject::schema_fields_DESCRIPTION => '',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool,message:string,project_id:int}
     */
    public function saveFromPanel(array $input): array
    {
        try {
            $projectId = (int)($input[WlsPanelProject::schema_fields_ID] ?? $input['project_id'] ?? 0);
            $project = $projectId > 0 ? $this->loadProject($projectId) : $this->freshProject();
            if ($projectId > 0 && !$project->getData(WlsPanelProject::schema_fields_ID)) {
                throw new \InvalidArgumentException((string)__('Managed project does not exist.'));
            }

            $project->setData(WlsPanelProject::schema_fields_NAME, $this->stringValue($input, WlsPanelProject::schema_fields_NAME));
            $project->setData(WlsPanelProject::schema_fields_DOMAIN, $this->stringValue($input, WlsPanelProject::schema_fields_DOMAIN));
            $project->setData(WlsPanelProject::schema_fields_ADMIN_URL, $this->stringValue($input, WlsPanelProject::schema_fields_ADMIN_URL));
            $project->setData(WlsPanelProject::schema_fields_PANEL_URL, $this->stringValue($input, WlsPanelProject::schema_fields_PANEL_URL));
            $project->setData(WlsPanelProject::schema_fields_PROJECT_PATH, $this->stringValue($input, WlsPanelProject::schema_fields_PROJECT_PATH));
            $project->setData(WlsPanelProject::schema_fields_PHP_PROFILE, $this->stringValue($input, WlsPanelProject::schema_fields_PHP_PROFILE));
            $project->setData(WlsPanelProject::schema_fields_DATABASE_PROFILE, $this->stringValue($input, WlsPanelProject::schema_fields_DATABASE_PROFILE));
            $project->setData(WlsPanelProject::schema_fields_STATUS, $this->normalizeStatus($this->stringValue($input, WlsPanelProject::schema_fields_STATUS)));
            $project->setData(WlsPanelProject::schema_fields_DESCRIPTION, $this->stringValue($input, WlsPanelProject::schema_fields_DESCRIPTION));
            $project->save();

            return [
                'success' => true,
                'message' => (string)__('Managed project saved.'),
                'project_id' => (int)$project->getData(WlsPanelProject::schema_fields_ID),
            ];
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => $throwable->getMessage(),
                'project_id' => (int)($input[WlsPanelProject::schema_fields_ID] ?? $input['project_id'] ?? 0),
            ];
        }
    }

    /**
     * @return array{success:bool,message:string}
     */
    public function deleteFromPanel(int $projectId): array
    {
        if ($projectId <= 0) {
            return ['success' => false, 'message' => (string)__('Managed project ID is invalid.')];
        }

        try {
            $project = $this->loadProject($projectId);
            if (!$project->getData(WlsPanelProject::schema_fields_ID)) {
                return ['success' => false, 'message' => (string)__('Managed project does not exist.')];
            }

            $project->delete();

            return [
                'success' => true,
                'message' => (string)__('Managed project removed.'),
            ];
        } catch (\Throwable $throwable) {
            return ['success' => false, 'message' => $throwable->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $project
     * @return array<string, mixed>
     */
    public function projectToCard(array $project): array
    {
        $status = (string)($project[WlsPanelProject::schema_fields_STATUS] ?? WlsPanelProject::STATUS_ACTIVE);
        $phpProfile = \trim((string)($project[WlsPanelProject::schema_fields_PHP_PROFILE] ?? ''));
        $databaseProfile = \trim((string)($project[WlsPanelProject::schema_fields_DATABASE_PROFILE] ?? ''));
        return [
            'type' => 'registered',
            'id' => (int)($project[WlsPanelProject::schema_fields_ID] ?? 0),
            'name' => (string)($project[WlsPanelProject::schema_fields_NAME] ?? ''),
            'domain' => (string)($project[WlsPanelProject::schema_fields_DOMAIN] ?? ''),
            'status' => $status === WlsPanelProject::STATUS_ACTIVE ? (string)__('Active') : (string)__('Inactive'),
            'path_label' => (string)__('Project Path'),
            'path' => (string)($project[WlsPanelProject::schema_fields_PROJECT_PATH] ?? ''),
            'admin' => (string)($project[WlsPanelProject::schema_fields_ADMIN_URL] ?? ''),
            'panel' => (string)($project[WlsPanelProject::schema_fields_PANEL_URL] ?? ''),
            'php' => '',
            'php_label' => $phpProfile !== '' ? $phpProfile : (string)__('Runtime profile editable'),
            'db' => '',
            'db_label' => $databaseProfile !== '' ? $databaseProfile : (string)__('Click to configure profile'),
        ];
    }

    public function loadProject(int $projectId): WlsPanelProject
    {
        return $this->freshProject()
            ->clearQuery()
            ->where(WlsPanelProject::schema_fields_ID, $projectId)
            ->find()
            ->fetch();
    }

    private function freshProject(): WlsPanelProject
    {
        /** @var WlsPanelProject $project */
        $project = ObjectManager::getInstance(WlsPanelProject::class, [], false);
        return $project;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function stringValue(array $input, string $key): string
    {
        return \trim((string)($input[$key] ?? ''));
    }

    private function normalizeStatus(string $status): string
    {
        return \in_array($status, [WlsPanelProject::STATUS_ACTIVE, WlsPanelProject::STATUS_INACTIVE], true)
            ? $status
            : WlsPanelProject::STATUS_ACTIVE;
    }
}
