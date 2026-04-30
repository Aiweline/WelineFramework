<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Skill;

use GuoLaiRen\PageBuilder\Model\AiSiteSkill;
use Weline\Framework\Manager\ObjectManager;

final class CustomSkillRepository
{
    public function __construct(
        private readonly ?AiSiteSkill $skillModel = null,
        private readonly ?BuiltinSkillProvider $builtinProvider = null,
        private readonly ?SkillNormalizer $normalizer = null
    ) {
    }

    public function create(): AiSiteSkill
    {
        return clone $this->baseModel();
    }

    public function findByCode(string $code): ?AiSiteSkill
    {
        try {
            $code = $this->normalizer()->normalizeCode($code);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $skill = $this->create();
        $skill->clearData()->clearQuery()
            ->where(AiSiteSkill::schema_fields_CODE, $code)
            ->find()
            ->fetch();

        return $skill->getId() > 0 ? $skill : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function listByCode(bool $includeDisabled = true): array
    {
        $query = $this->create()->clearData()->clearQuery();
        if (!$includeDisabled) {
            $query->where(AiSiteSkill::schema_fields_STATUS, AiSiteSkill::STATUS_ACTIVE);
        }
        $rows = $query->order(AiSiteSkill::schema_fields_CODE, 'ASC')->select()->fetchArray();
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

    /**
     * @return array<string, mixed>|null
     */
    public function findArrayByCode(string $code): ?array
    {
        $skill = $this->findByCode($code);
        if (!$skill) {
            return null;
        }

        return $this->modelToArray($skill);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveFromArray(array $data): AiSiteSkill
    {
        $code = $this->normalizer()->normalizeCode((string)($data['code'] ?? ''));
        if ($this->builtinProvider()->getSkill($code) !== null) {
            throw new \InvalidArgumentException('Custom skill code "' . $code . '" conflicts with a builtin skill.');
        }

        $id = (int)($data[AiSiteSkill::schema_fields_ID] ?? $data['id'] ?? 0);
        $existing = $this->findByCode($code);
        if ($existing !== null && ($id <= 0 || $existing->getId() !== $id)) {
            throw new \InvalidArgumentException('Custom skill code "' . $code . '" already exists.');
        }

        $skill = $existing ?? $this->create();
        $now = \date('Y-m-d H:i:s');
        if ($skill->getId() <= 0) {
            $skill->setData(AiSiteSkill::schema_fields_CREATED_AT, $now);
        }

        $status = \trim((string)($data['status'] ?? AiSiteSkill::STATUS_ACTIVE));
        if (!\in_array($status, [AiSiteSkill::STATUS_ACTIVE, AiSiteSkill::STATUS_DISABLED], true)) {
            throw new \InvalidArgumentException('Skill status must be active or disabled.');
        }

        $skill->setData(AiSiteSkill::schema_fields_CODE, $code);
        $skill->setData(AiSiteSkill::schema_fields_NAME, \trim((string)($data['name'] ?? $code)));
        $skill->setData(AiSiteSkill::schema_fields_DESCRIPTION, \trim((string)($data['description'] ?? '')));
        $skill->setData(AiSiteSkill::schema_fields_BODY, $this->normalizer()->normalizeBody((string)($data['body'] ?? '')));
        $skill->setData(AiSiteSkill::schema_fields_STATUS, $status);
        $skill->setData(AiSiteSkill::schema_fields_SOURCE, AiSiteSkill::SOURCE_CUSTOM_DB);
        $skill->setData(AiSiteSkill::schema_fields_UPDATED_AT, $now);
        $skill->save();

        return $skill;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function rowToArray(array $row): array
    {
        $body = (string)($row[AiSiteSkill::schema_fields_BODY] ?? '');
        $normalizedBody = $this->normalizer()->normalizeBody($body);

        return [
            'id' => (int)($row[AiSiteSkill::schema_fields_ID] ?? 0),
            'code' => (string)($row[AiSiteSkill::schema_fields_CODE] ?? ''),
            'name' => (string)($row[AiSiteSkill::schema_fields_NAME] ?? ''),
            'description' => (string)($row[AiSiteSkill::schema_fields_DESCRIPTION] ?? ''),
            'body' => $body,
            'normalized_body' => $normalizedBody,
            'body_hash' => $this->normalizer()->hashBody($normalizedBody),
            'status' => (string)($row[AiSiteSkill::schema_fields_STATUS] ?? AiSiteSkill::STATUS_ACTIVE),
            'source' => AiSiteSkill::SOURCE_CUSTOM_DB,
            'local_path' => '',
            'abs_path' => '',
            'exists' => true,
            'created_at' => (string)($row[AiSiteSkill::schema_fields_CREATED_AT] ?? ''),
            'updated_at' => (string)($row[AiSiteSkill::schema_fields_UPDATED_AT] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function modelToArray(AiSiteSkill $skill): array
    {
        return $this->rowToArray([
            AiSiteSkill::schema_fields_ID => $skill->getData(AiSiteSkill::schema_fields_ID),
            AiSiteSkill::schema_fields_CODE => $skill->getData(AiSiteSkill::schema_fields_CODE),
            AiSiteSkill::schema_fields_NAME => $skill->getData(AiSiteSkill::schema_fields_NAME),
            AiSiteSkill::schema_fields_DESCRIPTION => $skill->getData(AiSiteSkill::schema_fields_DESCRIPTION),
            AiSiteSkill::schema_fields_BODY => $skill->getData(AiSiteSkill::schema_fields_BODY),
            AiSiteSkill::schema_fields_STATUS => $skill->getData(AiSiteSkill::schema_fields_STATUS),
            AiSiteSkill::schema_fields_CREATED_AT => $skill->getData(AiSiteSkill::schema_fields_CREATED_AT),
            AiSiteSkill::schema_fields_UPDATED_AT => $skill->getData(AiSiteSkill::schema_fields_UPDATED_AT),
        ]);
    }

    private function baseModel(): AiSiteSkill
    {
        return $this->skillModel ?? ObjectManager::getInstance(AiSiteSkill::class);
    }

    private function builtinProvider(): BuiltinSkillProvider
    {
        return $this->builtinProvider ?? new BuiltinSkillProvider($this->normalizer());
    }

    private function normalizer(): SkillNormalizer
    {
        return $this->normalizer ?? new SkillNormalizer();
    }
}
