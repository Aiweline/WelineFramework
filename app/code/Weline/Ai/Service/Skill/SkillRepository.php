<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Skill;

use Weline\Ai\Model\AiSkill;
use Weline\Framework\Manager\ObjectManager;

final class SkillRepository
{
    public function __construct(
        private readonly ?AiSkill $skillModel = null,
        private readonly ?SkillNormalizer $normalizer = null
    ) {
    }

    public function create(): AiSkill
    {
        return clone $this->baseModel();
    }

    public function findByCode(string $code): ?AiSkill
    {
        try {
            $code = $this->normalizer()->normalizeCode($code);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $skill = $this->create();
        $skill->clearData()->clearQuery()
            ->where(AiSkill::schema_fields_CODE, $code)
            ->find()
            ->fetch();

        return $skill->getId() > 0 ? $skill : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function listByCode(bool $includeInactive = true): array
    {
        $query = $this->create()->clearData()->clearQuery();
        if (!$includeInactive) {
            $query->where(AiSkill::schema_fields_STATUS, AiSkill::STATUS_ACTIVE);
        }

        $rows = $query->order(AiSkill::schema_fields_CODE, 'ASC')->select()->fetchArray();
        if (!\is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $skill = $this->rowToArray($row);
            $code = (string)$skill['code'];
            if ($code !== '') {
                $result[$code] = $skill;
            }
        }

        return $result;
    }

    public function findArrayByCode(string $code): ?array
    {
        $skill = $this->findByCode($code);
        return $skill ? $this->modelToArray($skill) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveFromArray(array $data, string $defaultSourceType = AiSkill::SOURCE_CUSTOM): AiSkill
    {
        $code = $this->normalizer()->normalizeCode((string)($data['code'] ?? ''));
        $id = (int)($data[AiSkill::schema_fields_ID] ?? $data['id'] ?? 0);
        $existing = $this->findByCode($code);
        if ($existing !== null && $id > 0 && $existing->getId() !== $id) {
            throw new \InvalidArgumentException('Skill code "' . $code . '" already exists.');
        }

        $sourceType = \trim((string)($data['source_type'] ?? $defaultSourceType));
        $allowedSourceTypes = [
            AiSkill::SOURCE_SYSTEM,
            AiSkill::SOURCE_MODULE,
            AiSkill::SOURCE_CUSTOM,
            AiSkill::SOURCE_IMPORT_URL,
            AiSkill::SOURCE_IMPORT_PACKAGE,
            AiSkill::SOURCE_PLATFORM,
        ];
        if (!\in_array($sourceType, $allowedSourceTypes, true)) {
            throw new \InvalidArgumentException('Invalid skill source type.');
        }
        if ($existing !== null) {
            $existingSourceType = (string)$existing->getData(AiSkill::schema_fields_SOURCE_TYPE);
            if (\in_array($existingSourceType, [AiSkill::SOURCE_SYSTEM, AiSkill::SOURCE_MODULE], true)) {
                throw new \InvalidArgumentException('System and module skills cannot be overwritten.');
            }
        }

        $status = \trim((string)($data['status'] ?? ($sourceType === AiSkill::SOURCE_CUSTOM ? AiSkill::STATUS_ACTIVE : AiSkill::STATUS_PENDING)));
        if (!\in_array($status, [AiSkill::STATUS_ACTIVE, AiSkill::STATUS_PENDING, AiSkill::STATUS_DISABLED], true)) {
            throw new \InvalidArgumentException('Skill status must be active, pending, or disabled.');
        }

        $body = $this->normalizer()->normalizeBody((string)($data['body'] ?? $data['normalized_body'] ?? ''));
        $now = \date('Y-m-d H:i:s');
        $skill = $existing ?? $this->create();
        if ($skill->getId() <= 0) {
            $skill->setData(AiSkill::schema_fields_CREATED_AT, $now);
        }

        $skill->setData(AiSkill::schema_fields_CODE, $code);
        $skill->setData(AiSkill::schema_fields_NAME, \trim((string)($data['name'] ?? $code)));
        $skill->setData(AiSkill::schema_fields_DESCRIPTION, \trim((string)($data['description'] ?? '')));
        $skill->setData(AiSkill::schema_fields_BODY, $body);
        $skill->setData(AiSkill::schema_fields_BODY_HASH, $this->normalizer()->hashBody($body));
        $skill->setData(AiSkill::schema_fields_SOURCE_TYPE, $sourceType);
        $skill->setData(AiSkill::schema_fields_SOURCE_MODULE, \trim((string)($data['source_module'] ?? '')));
        $skill->setData(AiSkill::schema_fields_SOURCE_URL, \trim((string)($data['source_url'] ?? '')));
        $skill->setData(AiSkill::schema_fields_SOURCE_PLATFORM, \trim((string)($data['source_platform'] ?? '')));
        $skill->setData(AiSkill::schema_fields_VERSION, \trim((string)($data['version'] ?? '')));
        $skill->setData(AiSkill::schema_fields_STATUS, $status);
        $skill->setData(AiSkill::schema_fields_UPDATED_AT, $now);
        $skill->save();

        return $skill;
    }

    public function disable(string $code): ?AiSkill
    {
        $skill = $this->findByCode($code);
        if (!$skill) {
            return null;
        }

        $sourceType = (string)$skill->getData(AiSkill::schema_fields_SOURCE_TYPE);
        if (\in_array($sourceType, [AiSkill::SOURCE_SYSTEM, AiSkill::SOURCE_MODULE], true)) {
            throw new \InvalidArgumentException('System and module skills cannot be disabled from the database.');
        }

        $skill->setData(AiSkill::schema_fields_STATUS, AiSkill::STATUS_DISABLED);
        $skill->setData(AiSkill::schema_fields_UPDATED_AT, \date('Y-m-d H:i:s'));
        $skill->save();

        return $skill;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function rowToArray(array $row): array
    {
        $body = (string)($row[AiSkill::schema_fields_BODY] ?? '');
        $normalizedBody = '';
        $bodyHash = (string)($row[AiSkill::schema_fields_BODY_HASH] ?? '');
        if (\trim($body) !== '') {
            try {
                $normalizedBody = $this->normalizer()->normalizeBody($body);
                $bodyHash = $bodyHash !== '' ? $bodyHash : $this->normalizer()->hashBody($normalizedBody);
            } catch (\InvalidArgumentException) {
                $normalizedBody = \trim(\str_replace(["\r\n", "\r"], "\n", $body));
            }
        }

        $sourceType = (string)($row[AiSkill::schema_fields_SOURCE_TYPE] ?? AiSkill::SOURCE_CUSTOM);
        return [
            'id' => (int)($row[AiSkill::schema_fields_ID] ?? 0),
            'code' => (string)($row[AiSkill::schema_fields_CODE] ?? ''),
            'name' => (string)($row[AiSkill::schema_fields_NAME] ?? ''),
            'description' => (string)($row[AiSkill::schema_fields_DESCRIPTION] ?? ''),
            'body' => $body,
            'normalized_body' => $normalizedBody,
            'body_hash' => $bodyHash,
            'status' => (string)($row[AiSkill::schema_fields_STATUS] ?? AiSkill::STATUS_PENDING),
            'source' => $sourceType === AiSkill::SOURCE_CUSTOM ? 'custom_db' : $sourceType,
            'source_type' => $sourceType,
            'source_module' => (string)($row[AiSkill::schema_fields_SOURCE_MODULE] ?? ''),
            'source_url' => (string)($row[AiSkill::schema_fields_SOURCE_URL] ?? ''),
            'source_platform' => (string)($row[AiSkill::schema_fields_SOURCE_PLATFORM] ?? ''),
            'version' => (string)($row[AiSkill::schema_fields_VERSION] ?? ''),
            'local_path' => '',
            'abs_path' => '',
            'exists' => true,
            'readonly' => !\in_array($sourceType, [AiSkill::SOURCE_CUSTOM, AiSkill::SOURCE_IMPORT_URL, AiSkill::SOURCE_IMPORT_PACKAGE, AiSkill::SOURCE_PLATFORM], true),
            'created_at' => (string)($row[AiSkill::schema_fields_CREATED_AT] ?? ''),
            'updated_at' => (string)($row[AiSkill::schema_fields_UPDATED_AT] ?? ''),
        ];
    }

    public function modelToArray(AiSkill $skill): array
    {
        return $this->rowToArray([
            AiSkill::schema_fields_ID => $skill->getData(AiSkill::schema_fields_ID),
            AiSkill::schema_fields_CODE => $skill->getData(AiSkill::schema_fields_CODE),
            AiSkill::schema_fields_NAME => $skill->getData(AiSkill::schema_fields_NAME),
            AiSkill::schema_fields_DESCRIPTION => $skill->getData(AiSkill::schema_fields_DESCRIPTION),
            AiSkill::schema_fields_BODY => $skill->getData(AiSkill::schema_fields_BODY),
            AiSkill::schema_fields_BODY_HASH => $skill->getData(AiSkill::schema_fields_BODY_HASH),
            AiSkill::schema_fields_SOURCE_TYPE => $skill->getData(AiSkill::schema_fields_SOURCE_TYPE),
            AiSkill::schema_fields_SOURCE_MODULE => $skill->getData(AiSkill::schema_fields_SOURCE_MODULE),
            AiSkill::schema_fields_SOURCE_URL => $skill->getData(AiSkill::schema_fields_SOURCE_URL),
            AiSkill::schema_fields_SOURCE_PLATFORM => $skill->getData(AiSkill::schema_fields_SOURCE_PLATFORM),
            AiSkill::schema_fields_VERSION => $skill->getData(AiSkill::schema_fields_VERSION),
            AiSkill::schema_fields_STATUS => $skill->getData(AiSkill::schema_fields_STATUS),
            AiSkill::schema_fields_CREATED_AT => $skill->getData(AiSkill::schema_fields_CREATED_AT),
            AiSkill::schema_fields_UPDATED_AT => $skill->getData(AiSkill::schema_fields_UPDATED_AT),
        ]);
    }

    private function baseModel(): AiSkill
    {
        return $this->skillModel ?? ObjectManager::getInstance(AiSkill::class);
    }

    private function normalizer(): SkillNormalizer
    {
        return $this->normalizer ?? new SkillNormalizer();
    }
}
