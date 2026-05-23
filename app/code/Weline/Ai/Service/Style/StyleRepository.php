<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Style;

use Weline\Ai\Model\AiStyle;
use Weline\Framework\Manager\ObjectManager;

final class StyleRepository
{
    public function __construct(
        private readonly ?AiStyle $styleModel = null,
        private readonly ?StyleNormalizer $normalizer = null
    ) {
    }

    public function create(): AiStyle
    {
        return clone $this->baseModel();
    }

    public function findByCode(string $code, int $adminId): ?AiStyle
    {
        $code = $this->normalizer()->normalizeCode($code, false);
        if ($code === '') {
            return null;
        }
        $style = $this->create();
        $style->clearData()->clearQuery()
            ->where(AiStyle::schema_fields_ADMIN_USER_ID, $adminId)
            ->where(AiStyle::schema_fields_CODE, $code)
            ->find()
            ->fetch();

        return $style->getId() > 0 ? $style : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function listByCode(int $adminId, bool $includeInactive = true): array
    {
        if ($adminId <= 0) {
            return [];
        }
        $query = $this->create()->clearData()->clearQuery()
            ->where(AiStyle::schema_fields_ADMIN_USER_ID, $adminId);
        if (!$includeInactive) {
            $query->where(AiStyle::schema_fields_STATUS, AiStyle::STATUS_ACTIVE);
        }

        try {
            $rows = $query->order(AiStyle::schema_fields_CODE, 'ASC')->select()->fetchArray();
        } catch (\Throwable $throwable) {
            if (\function_exists('w_log_error')) {
                w_log_error('AI style table unavailable: ' . $throwable->getMessage());
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
            $style = $this->rowToArray($row);
            $code = (string)$style['code'];
            if ($code !== '') {
                $result[$code] = $style;
            }
        }

        return $result;
    }

    public function findArrayByCode(string $code, int $adminId): ?array
    {
        $style = $this->findByCode($code, $adminId);
        return $style ? $this->modelToArray($style) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveFromArray(array $data, int $adminId, string $defaultSourceType = AiStyle::SOURCE_CUSTOM): AiStyle
    {
        if ($adminId <= 0) {
            throw new \InvalidArgumentException('Admin user is required.');
        }
        $code = $this->normalizer()->normalizeCode((string)($data['code'] ?? ''));
        $name = \trim((string)($data['name'] ?? $code));
        if ($name === '') {
            throw new \InvalidArgumentException('Style name is required.');
        }

        $sourceType = \trim((string)($data['source_type'] ?? $defaultSourceType));
        if (!\in_array($sourceType, [AiStyle::SOURCE_CUSTOM], true)) {
            throw new \InvalidArgumentException('Only custom styles can be saved from this repository.');
        }
        $status = \trim((string)($data['status'] ?? AiStyle::STATUS_ACTIVE));
        if (!\in_array($status, [AiStyle::STATUS_ACTIVE, AiStyle::STATUS_DISABLED], true)) {
            throw new \InvalidArgumentException('Style status must be active or disabled.');
        }

        $normalized = $this->normalizer()->normalizeStylePayload($data);
        $this->normalizer()->assertStructuredPayload($normalized);

        $existing = $this->findByCode($code, $adminId);
        $style = $existing ?? $this->create();
        $now = \date('Y-m-d H:i:s');
        if ($style->getId() <= 0) {
            $style->setData(AiStyle::schema_fields_CREATED_AT, $now);
            $style->setData(AiStyle::schema_fields_VERSION, 1);
        } else {
            $style->setData(
                AiStyle::schema_fields_VERSION,
                \max(1, (int)$style->getData(AiStyle::schema_fields_VERSION)) + 1
            );
        }

        $style->setData(AiStyle::schema_fields_ADMIN_USER_ID, $adminId);
        $style->setData(AiStyle::schema_fields_CODE, $code);
        $style->setData(AiStyle::schema_fields_NAME, $name);
        $style->setData(AiStyle::schema_fields_DESCRIPTION, \trim((string)($data['description'] ?? '')));
        $style->setData(AiStyle::schema_fields_SOURCE_TYPE, AiStyle::SOURCE_CUSTOM);
        $style->setData(AiStyle::schema_fields_SOURCE_MODULE, \trim((string)($data['source_module'] ?? '')));
        foreach (StyleNormalizer::JSON_FIELDS as $field) {
            $style->setData($field, $this->normalizer()->encodeJsonField($normalized[$field] ?? []));
        }
        $style->setData(AiStyle::schema_fields_CTA_STYLE, \trim((string)($data['cta_style'] ?? '')));
        $style->setData(AiStyle::schema_fields_SUPPLEMENTAL_PROMPT, \trim((string)($data['supplemental_prompt'] ?? '')));
        $style->setData(AiStyle::schema_fields_STATUS, $status);
        $style->setData(AiStyle::schema_fields_UPDATED_AT, $now);
        $style->save();

        return $style;
    }

    public function disable(string $code, int $adminId): ?AiStyle
    {
        $style = $this->findByCode($code, $adminId);
        if (!$style) {
            return null;
        }

        $sourceType = (string)$style->getData(AiStyle::schema_fields_SOURCE_TYPE);
        if ($sourceType !== AiStyle::SOURCE_CUSTOM) {
            throw new \InvalidArgumentException('Only custom styles can be disabled from the database.');
        }

        $style->setData(AiStyle::schema_fields_STATUS, AiStyle::STATUS_DISABLED);
        $style->setData(AiStyle::schema_fields_UPDATED_AT, \date('Y-m-d H:i:s'));
        $style->save();

        return $style;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function rowToArray(array $row): array
    {
        $item = [
            'id' => (int)($row[AiStyle::schema_fields_ID] ?? 0),
            'admin_user_id' => (int)($row[AiStyle::schema_fields_ADMIN_USER_ID] ?? 0),
            'code' => (string)($row[AiStyle::schema_fields_CODE] ?? ''),
            'name' => (string)($row[AiStyle::schema_fields_NAME] ?? ''),
            'description' => (string)($row[AiStyle::schema_fields_DESCRIPTION] ?? ''),
            'source_type' => (string)($row[AiStyle::schema_fields_SOURCE_TYPE] ?? AiStyle::SOURCE_CUSTOM),
            'source' => (string)($row[AiStyle::schema_fields_SOURCE_TYPE] ?? AiStyle::SOURCE_CUSTOM),
            'source_module' => (string)($row[AiStyle::schema_fields_SOURCE_MODULE] ?? ''),
            'cta_style' => (string)($row[AiStyle::schema_fields_CTA_STYLE] ?? ''),
            'supplemental_prompt' => (string)($row[AiStyle::schema_fields_SUPPLEMENTAL_PROMPT] ?? ''),
            'version' => (int)($row[AiStyle::schema_fields_VERSION] ?? 1),
            'status' => (string)($row[AiStyle::schema_fields_STATUS] ?? AiStyle::STATUS_ACTIVE),
            'created_at' => (string)($row[AiStyle::schema_fields_CREATED_AT] ?? ''),
            'updated_at' => (string)($row[AiStyle::schema_fields_UPDATED_AT] ?? ''),
            'readonly' => false,
            'selectable' => (string)($row[AiStyle::schema_fields_STATUS] ?? AiStyle::STATUS_ACTIVE) === AiStyle::STATUS_ACTIVE,
            'exists' => true,
        ];
        foreach (StyleNormalizer::JSON_FIELDS as $field) {
            $item[$field] = $this->normalizer()->decodeJsonField($row[$field] ?? null);
        }

        return $item;
    }

    public function modelToArray(AiStyle $style): array
    {
        return $this->rowToArray([
            AiStyle::schema_fields_ID => $style->getData(AiStyle::schema_fields_ID),
            AiStyle::schema_fields_ADMIN_USER_ID => $style->getData(AiStyle::schema_fields_ADMIN_USER_ID),
            AiStyle::schema_fields_CODE => $style->getData(AiStyle::schema_fields_CODE),
            AiStyle::schema_fields_NAME => $style->getData(AiStyle::schema_fields_NAME),
            AiStyle::schema_fields_DESCRIPTION => $style->getData(AiStyle::schema_fields_DESCRIPTION),
            AiStyle::schema_fields_SOURCE_TYPE => $style->getData(AiStyle::schema_fields_SOURCE_TYPE),
            AiStyle::schema_fields_SOURCE_MODULE => $style->getData(AiStyle::schema_fields_SOURCE_MODULE),
            AiStyle::schema_fields_CTA_STYLE => $style->getData(AiStyle::schema_fields_CTA_STYLE),
            AiStyle::schema_fields_SUPPLEMENTAL_PROMPT => $style->getData(AiStyle::schema_fields_SUPPLEMENTAL_PROMPT),
            AiStyle::schema_fields_VERSION => $style->getData(AiStyle::schema_fields_VERSION),
            AiStyle::schema_fields_STATUS => $style->getData(AiStyle::schema_fields_STATUS),
            AiStyle::schema_fields_CREATED_AT => $style->getData(AiStyle::schema_fields_CREATED_AT),
            AiStyle::schema_fields_UPDATED_AT => $style->getData(AiStyle::schema_fields_UPDATED_AT),
            AiStyle::schema_fields_INDUSTRY_TAGS => $style->getData(AiStyle::schema_fields_INDUSTRY_TAGS),
            AiStyle::schema_fields_MATCH_KEYWORDS => $style->getData(AiStyle::schema_fields_MATCH_KEYWORDS),
            AiStyle::schema_fields_VISUAL_KEYWORDS => $style->getData(AiStyle::schema_fields_VISUAL_KEYWORDS),
            AiStyle::schema_fields_COLOR_SYSTEM => $style->getData(AiStyle::schema_fields_COLOR_SYSTEM),
            AiStyle::schema_fields_LAYOUT_PATTERNS => $style->getData(AiStyle::schema_fields_LAYOUT_PATTERNS),
            AiStyle::schema_fields_IMAGE_STRATEGY => $style->getData(AiStyle::schema_fields_IMAGE_STRATEGY),
            AiStyle::schema_fields_FORBIDDEN_PATTERNS => $style->getData(AiStyle::schema_fields_FORBIDDEN_PATTERNS),
            AiStyle::schema_fields_BLOCK_RULES => $style->getData(AiStyle::schema_fields_BLOCK_RULES),
            AiStyle::schema_fields_QA_RULES => $style->getData(AiStyle::schema_fields_QA_RULES),
            AiStyle::schema_fields_EXAMPLE_REFS => $style->getData(AiStyle::schema_fields_EXAMPLE_REFS),
        ]);
    }

    private function baseModel(): AiStyle
    {
        return $this->styleModel ?? ObjectManager::getInstance(AiStyle::class);
    }

    private function normalizer(): StyleNormalizer
    {
        return $this->normalizer ?? new StyleNormalizer();
    }
}
