<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Skill;

use Weline\Ai\Model\AiAdapterSkill;
use Weline\Framework\Manager\ObjectManager;

final class AdapterSkillRepository
{
    public function __construct(
        private readonly ?AiAdapterSkill $bindingModel = null,
        private readonly ?SkillNormalizer $normalizer = null
    ) {
    }

    public function create(): AiAdapterSkill
    {
        return clone $this->baseModel();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listBindings(string $adapterCode, bool $activeOnly = true): array
    {
        $adapterCode = \trim($adapterCode);
        if ($adapterCode === '') {
            return [];
        }

        $query = $this->create()->clearData()->clearQuery()
            ->where(AiAdapterSkill::schema_fields_ADAPTER_CODE, $adapterCode)
            ->where(AiAdapterSkill::schema_fields_BIND_TYPE, AiAdapterSkill::BIND_TYPE_MANUAL);
        if ($activeOnly) {
            $query->where(AiAdapterSkill::schema_fields_STATUS, AiAdapterSkill::STATUS_ACTIVE);
        }

        try {
            $rows = $query->order(AiAdapterSkill::schema_fields_SKILL_CODE, 'ASC')->select()->fetchArray();
        } catch (\Throwable $throwable) {
            if (\function_exists('w_log_error')) {
                w_log_error('AI adapter skill binding table unavailable: ' . $throwable->getMessage());
            }
            return [];
        }
        if (!\is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $result[] = [
                'id' => (int)($row[AiAdapterSkill::schema_fields_ID] ?? 0),
                'adapter_code' => (string)($row[AiAdapterSkill::schema_fields_ADAPTER_CODE] ?? ''),
                'skill_code' => (string)($row[AiAdapterSkill::schema_fields_SKILL_CODE] ?? ''),
                'bind_type' => (string)($row[AiAdapterSkill::schema_fields_BIND_TYPE] ?? AiAdapterSkill::BIND_TYPE_MANUAL),
                'status' => (string)($row[AiAdapterSkill::schema_fields_STATUS] ?? AiAdapterSkill::STATUS_ACTIVE),
            ];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function listActiveSkillCodes(string $adapterCode): array
    {
        $codes = [];
        foreach ($this->listBindings($adapterCode, true) as $binding) {
            try {
                $code = $this->normalizer()->normalizeCode((string)($binding['skill_code'] ?? ''));
            } catch (\InvalidArgumentException) {
                continue;
            }
            if (!\in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public function bind(string $adapterCode, string $skillCode, int $createdBy = 0): AiAdapterSkill
    {
        $adapterCode = \trim($adapterCode);
        $skillCode = $this->normalizer()->normalizeCode($skillCode);
        if ($adapterCode === '') {
            throw new \InvalidArgumentException('Adapter code is required.');
        }

        $binding = $this->find($adapterCode, $skillCode) ?? $this->create();
        $now = \date('Y-m-d H:i:s');
        if ($binding->getId() <= 0) {
            $binding->setData(AiAdapterSkill::schema_fields_CREATED_AT, $now);
            $binding->setData(AiAdapterSkill::schema_fields_CREATED_BY, $createdBy > 0 ? $createdBy : null);
        }

        $binding->setData(AiAdapterSkill::schema_fields_ADAPTER_CODE, $adapterCode);
        $binding->setData(AiAdapterSkill::schema_fields_SKILL_CODE, $skillCode);
        $binding->setData(AiAdapterSkill::schema_fields_BIND_TYPE, AiAdapterSkill::BIND_TYPE_MANUAL);
        $binding->setData(AiAdapterSkill::schema_fields_STATUS, AiAdapterSkill::STATUS_ACTIVE);
        $binding->setData(AiAdapterSkill::schema_fields_UPDATED_AT, $now);
        $binding->save();

        return $binding;
    }

    public function unbind(string $adapterCode, string $skillCode): bool
    {
        $binding = $this->find(\trim($adapterCode), $this->normalizer()->normalizeCode($skillCode));
        if (!$binding) {
            return false;
        }

        $binding->delete();
        return true;
    }

    private function find(string $adapterCode, string $skillCode): ?AiAdapterSkill
    {
        if ($adapterCode === '' || $skillCode === '') {
            return null;
        }
        $binding = $this->create();
        $binding->clearData()->clearQuery()
            ->where(AiAdapterSkill::schema_fields_ADAPTER_CODE, $adapterCode)
            ->where(AiAdapterSkill::schema_fields_SKILL_CODE, $skillCode)
            ->where(AiAdapterSkill::schema_fields_BIND_TYPE, AiAdapterSkill::BIND_TYPE_MANUAL)
            ->find()
            ->fetch();

        return $binding->getId() > 0 ? $binding : null;
    }

    private function baseModel(): AiAdapterSkill
    {
        return $this->bindingModel ?? ObjectManager::getInstance(AiAdapterSkill::class);
    }

    private function normalizer(): SkillNormalizer
    {
        return $this->normalizer ?? new SkillNormalizer();
    }
}
