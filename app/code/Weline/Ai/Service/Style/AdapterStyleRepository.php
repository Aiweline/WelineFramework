<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Style;

use Weline\Ai\Model\AiAdapterStyle;
use Weline\Framework\Manager\ObjectManager;

final class AdapterStyleRepository
{
    public function __construct(
        private readonly ?AiAdapterStyle $bindingModel = null,
        private readonly ?StyleNormalizer $normalizer = null
    ) {
    }

    public function create(): AiAdapterStyle
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
            ->where(AiAdapterStyle::schema_fields_ADAPTER_CODE, $adapterCode)
            ->where(AiAdapterStyle::schema_fields_BIND_TYPE, AiAdapterStyle::BIND_TYPE_MANUAL);
        if ($activeOnly) {
            $query->where(AiAdapterStyle::schema_fields_STATUS, AiAdapterStyle::STATUS_ACTIVE);
        }

        try {
            $rows = $query->order(AiAdapterStyle::schema_fields_STYLE_CODE, 'ASC')->select()->fetchArray();
        } catch (\Throwable $throwable) {
            if (\function_exists('w_log_error')) {
                w_log_error('AI adapter style binding table unavailable: ' . $throwable->getMessage());
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
                'id' => (int)($row[AiAdapterStyle::schema_fields_ID] ?? 0),
                'adapter_code' => (string)($row[AiAdapterStyle::schema_fields_ADAPTER_CODE] ?? ''),
                'style_code' => (string)($row[AiAdapterStyle::schema_fields_STYLE_CODE] ?? ''),
                'bind_type' => (string)($row[AiAdapterStyle::schema_fields_BIND_TYPE] ?? AiAdapterStyle::BIND_TYPE_MANUAL),
                'status' => (string)($row[AiAdapterStyle::schema_fields_STATUS] ?? AiAdapterStyle::STATUS_ACTIVE),
            ];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function listActiveStyleCodes(string $adapterCode): array
    {
        $codes = [];
        foreach ($this->listBindings($adapterCode, true) as $binding) {
            try {
                $code = $this->normalizer()->normalizeCode((string)($binding['style_code'] ?? ''), true);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if (!\in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public function bind(string $adapterCode, string $styleCode, int $createdBy = 0): AiAdapterStyle
    {
        $adapterCode = \trim($adapterCode);
        $styleCode = $this->normalizer()->normalizeCode($styleCode, true);
        if ($adapterCode === '') {
            throw new \InvalidArgumentException('Adapter code is required.');
        }

        $binding = $this->find($adapterCode, $styleCode) ?? $this->create();
        $now = \date('Y-m-d H:i:s');
        if ($binding->getId() <= 0) {
            $binding->setData(AiAdapterStyle::schema_fields_CREATED_AT, $now);
            $binding->setData(AiAdapterStyle::schema_fields_CREATED_BY, $createdBy > 0 ? $createdBy : null);
        }

        $binding->setData(AiAdapterStyle::schema_fields_ADAPTER_CODE, $adapterCode);
        $binding->setData(AiAdapterStyle::schema_fields_STYLE_CODE, $styleCode);
        $binding->setData(AiAdapterStyle::schema_fields_BIND_TYPE, AiAdapterStyle::BIND_TYPE_MANUAL);
        $binding->setData(AiAdapterStyle::schema_fields_STATUS, AiAdapterStyle::STATUS_ACTIVE);
        $binding->setData(AiAdapterStyle::schema_fields_UPDATED_AT, $now);
        $binding->save();

        return $binding;
    }

    public function unbind(string $adapterCode, string $styleCode): bool
    {
        $binding = $this->find(\trim($adapterCode), $this->normalizer()->normalizeCode($styleCode, true));
        if (!$binding) {
            return false;
        }

        $binding->delete();
        return true;
    }

    private function find(string $adapterCode, string $styleCode): ?AiAdapterStyle
    {
        if ($adapterCode === '' || $styleCode === '') {
            return null;
        }
        $binding = $this->create();
        $binding->clearData()->clearQuery()
            ->where(AiAdapterStyle::schema_fields_ADAPTER_CODE, $adapterCode)
            ->where(AiAdapterStyle::schema_fields_STYLE_CODE, $styleCode)
            ->where(AiAdapterStyle::schema_fields_BIND_TYPE, AiAdapterStyle::BIND_TYPE_MANUAL)
            ->find()
            ->fetch();

        return $binding->getId() > 0 ? $binding : null;
    }

    private function baseModel(): AiAdapterStyle
    {
        return $this->bindingModel ?? ObjectManager::getInstance(AiAdapterStyle::class);
    }

    private function normalizer(): StyleNormalizer
    {
        return $this->normalizer ?? new StyleNormalizer();
    }
}
