<?php

declare(strict_types=1);

namespace Weline\Ai\Controller\Backend;

use Weline\Ai\Model\AiSkill;
use Weline\Ai\Service\Skill\AdapterSkillRepository;
use Weline\Ai\Service\Skill\AdapterSkillResolver;
use Weline\Ai\Service\Skill\SkillExporter;
use Weline\Ai\Service\Skill\SkillImporter;
use Weline\Ai\Service\Skill\SkillRegistry;
use Weline\Ai\Service\Skill\SkillRepository;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_Ai::ai_skill_list', 'AI skill governance', 'mdi-shape-outline', 'AI skill list and governance', 'Weline_Backend::ai_group')]
class Skill extends BackendController
{
    #[Acl('Weline_Ai::ai_skill_index', 'AI skill list', 'mdi-view-list', 'View AI skills')]
    public function index(): string
    {
        if ($this->request->getGet('embed') === '1') {
            $this->layoutType = 'default.blank';
        }
        $this->assign('activeTab', 'skill');
        $this->assign('skills', \array_values($this->registry()->listAvailableSkills(true)));
        $this->assign('embed', ($this->request->getGet('embed') === '1' || $this->request->getGet('embed') === true));

        return $this->fetch();
    }

    #[Acl('Weline_Ai::ai_skill_view', 'AI skill catalog', 'mdi-tag-multiple', 'View AI skill catalog')]
    public function getCatalog(): string
    {
        return $this->catalogResponse(false);
    }

    #[Acl('Weline_Ai::ai_skill_view', 'AI skill catalog', 'mdi-tag-multiple', 'View AI skill catalog')]
    public function postCatalog(): string
    {
        return $this->catalogResponse(true);
    }

    #[Acl('Weline_Ai::ai_skill_save', 'Save AI skill', 'mdi-content-save', 'Save AI custom skill')]
    public function postSave(): string
    {
        $data = [
            'code' => $this->bodyValue('code', ''),
            'name' => $this->bodyValue('name', ''),
            'description' => $this->bodyValue('description', ''),
            'body' => $this->bodyValue('body', ''),
            'status' => $this->bodyValue('status', AiSkill::STATUS_ACTIVE),
            'source_type' => AiSkill::SOURCE_CUSTOM,
        ];

        try {
            $code = (string)$data['code'];
            if ($this->registry()->isReservedCode($code)) {
                throw new \InvalidArgumentException('Skill code "' . $code . '" conflicts with a system/module skill.');
            }
            $saved = $this->repository()->saveFromArray($data, AiSkill::SOURCE_CUSTOM);
            return $this->jsonResponse([
                'success' => true,
                'item' => $this->repository()->modelToArray($saved),
            ]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse([
                'success' => false,
                'code' => 'SKILL_SAVE_FAILED',
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    #[Acl('Weline_Ai::ai_skill_disable', 'Disable AI skill', 'mdi-close-circle-outline', 'Disable AI custom/imported skill')]
    public function postDisable(): string
    {
        $code = \trim((string)$this->bodyValue('code', ''));
        if ($code === '') {
            return $this->jsonResponse(['success' => false, 'code' => 'INVALID_SKILL_CODE', 'message' => 'Skill code is required.']);
        }

        try {
            $skill = $this->repository()->disable($code);
            if (!$skill) {
                return $this->jsonResponse(['success' => false, 'code' => 'SKILL_NOT_FOUND', 'message' => 'Skill not found.']);
            }

            return $this->jsonResponse([
                'success' => true,
                'item' => $this->repository()->modelToArray($skill),
            ]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse([
                'success' => false,
                'code' => 'SKILL_DISABLE_FAILED',
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    #[Acl('Weline_Ai::ai_skill_import', 'Import AI skill', 'mdi-import', 'Import AI skill from URL')]
    public function postImportUrl(): string
    {
        $url = \trim((string)$this->bodyValue('url', ''));
        if ($url === '') {
            return $this->jsonResponse(['success' => false, 'code' => 'INVALID_URL', 'message' => 'Import URL is required.']);
        }

        try {
            return $this->jsonResponse([
                'success' => true,
                'item' => $this->importer()->importFromUrl($url),
                'message' => 'Skill imported as pending. Enable it manually before binding or temporary use.',
            ]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse([
                'success' => false,
                'code' => 'SKILL_IMPORT_FAILED',
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    #[Acl('Weline_Ai::ai_skill_export', 'Export AI skill', 'mdi-export', 'Export AI skill package')]
    public function getExport(): string
    {
        $code = \trim((string)$this->request->getGet('code', ''));
        $skill = $this->registry()->getSkill($code, true);
        if (empty($skill['exists'])) {
            return $this->jsonResponse(['success' => false, 'code' => 'SKILL_NOT_FOUND', 'message' => 'Skill not found.']);
        }

        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        $this->request->getResponse()->setHeader('Content-Disposition', 'attachment; filename="' . $code . '.ai-skill.json"');
        return \json_encode($this->exporter()->exportPackage($skill), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    #[Acl('Weline_Ai::ai_adapter_skill_manage', 'Manage adapter skills', 'mdi-link-variant', 'Manage manual adapter skill bindings')]
    public function postBindAdapterSkill(): string
    {
        $adapterCode = \trim((string)$this->bodyValue('adapter_code', ''));
        $skillCode = \trim((string)$this->bodyValue('skill_code', ''));
        if ($adapterCode === '' || $skillCode === '') {
            return $this->jsonResponse(['success' => false, 'code' => 'INVALID_BINDING', 'message' => 'Adapter code and skill code are required.']);
        }

        try {
            $skill = $this->registry()->getSkill($skillCode, false);
            if (empty($skill['exists']) || (string)($skill['status'] ?? '') !== AiSkill::STATUS_ACTIVE) {
                return $this->jsonResponse(['success' => false, 'code' => 'SKILL_NOT_ACTIVE', 'message' => 'Only active skills can be bound to adapters.']);
            }
            $binding = $this->adapterSkillRepository()->bind($adapterCode, $skillCode);
            return $this->jsonResponse([
                'success' => true,
                'binding_id' => $binding->getId(),
                'catalog' => $this->resolver()->buildSkillCatalog($adapterCode, [], true),
            ]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse(['success' => false, 'code' => 'BIND_FAILED', 'message' => $throwable->getMessage()]);
        }
    }

    #[Acl('Weline_Ai::ai_adapter_skill_manage', 'Manage adapter skills', 'mdi-link-variant-off', 'Manage manual adapter skill bindings')]
    public function postUnbindAdapterSkill(): string
    {
        $adapterCode = \trim((string)$this->bodyValue('adapter_code', ''));
        $skillCode = \trim((string)$this->bodyValue('skill_code', ''));
        if ($adapterCode === '' || $skillCode === '') {
            return $this->jsonResponse(['success' => false, 'code' => 'INVALID_BINDING', 'message' => 'Adapter code and skill code are required.']);
        }

        try {
            return $this->jsonResponse([
                'success' => true,
                'removed' => $this->adapterSkillRepository()->unbind($adapterCode, $skillCode),
                'catalog' => $this->resolver()->buildSkillCatalog($adapterCode, [], true),
            ]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse(['success' => false, 'code' => 'UNBIND_FAILED', 'message' => $throwable->getMessage()]);
        }
    }

    private function catalogResponse(bool $fromBody): string
    {
        $adapterCode = \trim((string)($fromBody ? $this->bodyValue('adapter_code', '') : $this->request->getGet('adapter_code', '')));
        $temporaryCodes = $fromBody
            ? $this->parseCodeList($this->bodyValue('temporary_skill_codes', $this->bodyValue('selected_skill_codes', [])))
            : $this->parseCodeList($this->request->getGet('temporary_skill_codes', $this->request->getGet('selected_skill_codes', [])));
        $includeInactive = $this->truthy($fromBody ? $this->bodyValue('include_inactive', false) : $this->request->getGet('include_inactive', false));
        $adapterCode = $adapterCode !== '' ? $adapterCode : 'pagebuilder_component_generation';

        try {
            $catalog = $this->resolver()->buildSkillCatalog($adapterCode, $temporaryCodes, $includeInactive);
            return $this->jsonResponse(['success' => true] + $catalog);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse(['success' => false, 'message' => $throwable->getMessage(), 'items' => []]);
        }
    }

    /**
     * @return list<string>
     */
    private function parseCodeList(mixed $raw): array
    {
        if (\is_string($raw)) {
            $decoded = \json_decode($raw, true);
            $raw = \is_array($decoded) ? $decoded : \preg_split('/[\s,;]+/', $raw);
        }
        if (!\is_array($raw)) {
            return [];
        }
        $codes = [];
        foreach ($raw as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $code = \trim((string)$item);
            if ($code !== '' && !\in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }
        return $codes;
    }

    private function bodyValue(string $key, mixed $default = null): mixed
    {
        return $this->request->getBodyParam($key, $this->request->getPost($key, $default));
    }

    private function truthy(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        return \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function jsonResponse(array $data): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function registry(): SkillRegistry
    {
        return ObjectManager::getInstance(SkillRegistry::class);
    }

    private function repository(): SkillRepository
    {
        return ObjectManager::getInstance(SkillRepository::class);
    }

    private function resolver(): AdapterSkillResolver
    {
        return ObjectManager::getInstance(AdapterSkillResolver::class);
    }

    private function importer(): SkillImporter
    {
        return ObjectManager::getInstance(SkillImporter::class);
    }

    private function exporter(): SkillExporter
    {
        return ObjectManager::getInstance(SkillExporter::class);
    }

    private function adapterSkillRepository(): AdapterSkillRepository
    {
        return ObjectManager::getInstance(AdapterSkillRepository::class);
    }
}
