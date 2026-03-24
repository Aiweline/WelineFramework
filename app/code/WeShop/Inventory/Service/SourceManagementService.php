<?php

declare(strict_types=1);

namespace WeShop\Inventory\Service;

use WeShop\Inventory\Model\Source;

class SourceManagementService
{
    public function __construct(
        private readonly Source $source
    ) {
    }

    public function getSourceList(int $page = 1, int $pageSize = 20): array
    {
        $source = $this->source->reset()
            ->order(Source::schema_fields_PRIORITY, 'ASC')
            ->pagination($page, $pageSize);

        return [
            'items' => $source->select()->fetchArray(),
            'pagination' => $source->getPagination(),
            'total' => $source->getTotalCount(),
        ];
    }

    public function getSourceById(int $sourceId): ?Source
    {
        $source = $this->source->reset()->load($sourceId);
        return $source->getId() ? $source : null;
    }

    public function saveSource(array $payload, int $sourceId = 0): Source
    {
        $normalized = $this->normalizeSourcePayload($payload);

        $source = $this->source->reset();
        if ($sourceId > 0) {
            $source->load($sourceId);
            if (!$source->getId()) {
                throw new \InvalidArgumentException((string) __('Inventory source does not exist.'));
            }
        } else {
            $source->clearData();
        }

        $source->setData($normalized)->save();
        return $source;
    }

    public function deleteSource(int $sourceId): void
    {
        if ($sourceId <= 0) {
            throw new \InvalidArgumentException((string) __('Invalid source id.'));
        }

        $source = $this->source->reset()->load($sourceId);
        if (!$source->getId()) {
            throw new \InvalidArgumentException((string) __('Inventory source does not exist.'));
        }

        if ($source->getCode() === 'default') {
            throw new \InvalidArgumentException((string) __('The default source cannot be deleted.'));
        }

        $source->delete()->fetch();
    }

    public function getEnabledSources(): array
    {
        return $this->source->reset()->getEnabledSources();
    }

    public function getEmptySourceData(): array
    {
        return [
            'source_id' => 0,
            'code' => '',
            'name' => '',
            'description' => '',
            'country' => '',
            'region' => '',
            'city' => '',
            'address' => '',
            'postcode' => '',
            'phone' => '',
            'email' => '',
            'contact_name' => '',
            'is_enabled' => 1,
            'priority' => 0,
            'use_default_carrier' => 1,
        ];
    }

    private function normalizeSourcePayload(array $payload): array
    {
        $code = strtolower(trim((string) ($payload['code'] ?? '')));
        $name = trim((string) ($payload['name'] ?? ''));
        if ($code === '') {
            throw new \InvalidArgumentException((string) __('Source code is required.'));
        }

        if (!preg_match('/^[a-z0-9_-]+$/', $code)) {
            throw new \InvalidArgumentException((string) __('Source code only supports lowercase letters, numbers, underscore, and dash.'));
        }

        if ($name === '') {
            throw new \InvalidArgumentException((string) __('Source name is required.'));
        }

        return [
            Source::schema_fields_CODE => $code,
            Source::schema_fields_NAME => $name,
            Source::schema_fields_DESCRIPTION => trim((string) ($payload['description'] ?? '')),
            Source::schema_fields_COUNTRY => trim((string) ($payload['country'] ?? '')),
            Source::schema_fields_REGION => trim((string) ($payload['region'] ?? '')),
            Source::schema_fields_CITY => trim((string) ($payload['city'] ?? '')),
            Source::schema_fields_ADDRESS => trim((string) ($payload['address'] ?? '')),
            Source::schema_fields_POSTCODE => trim((string) ($payload['postcode'] ?? '')),
            Source::schema_fields_PHONE => trim((string) ($payload['phone'] ?? '')),
            Source::schema_fields_EMAIL => trim((string) ($payload['email'] ?? '')),
            Source::schema_fields_CONTACT_NAME => trim((string) ($payload['contact_name'] ?? '')),
            Source::schema_fields_IS_ENABLED => $this->toBoolInt($payload['is_enabled'] ?? 1),
            Source::schema_fields_PRIORITY => max(0, (int) ($payload['priority'] ?? 0)),
            Source::schema_fields_USE_DEFAULT_CARRIER => $this->toBoolInt($payload['use_default_carrier'] ?? 1),
        ];
    }

    private function toBoolInt(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_numeric($value)) {
            return ((int) $value) > 0 ? 1 : 0;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === 'on' || $normalized === 'true' || $normalized === 'yes') {
            return 1;
        }

        return 0;
    }
}

