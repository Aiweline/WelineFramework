<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Localization;

use Weline\Framework\App\Localization\LocalizationProviderInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Locals;

final class LocalizationProvider implements LocalizationProviderInterface
{
    public function priority(): int
    {
        return 10;
    }

    public function languageCodes(): array
    {
        return $this->codesWhereInstalled(true);
    }

    public function currencyCodes(): array
    {
        return [];
    }

    public function defaultLanguage(): ?string
    {
        return null;
    }

    public function defaultCurrency(): ?string
    {
        return null;
    }

    public function supportsLanguage(string $code): ?bool
    {
        $local = $this->model()->clear()
            ->where(Locals::schema_fields_CODE, $code)
            ->where(Locals::schema_fields_IS_INSTALL, 1)
            ->where(Locals::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();
        return (bool)$local->getId();
    }

    public function supportsCurrency(string $code): ?bool
    {
        return null;
    }

    public function installedLanguageCodes(): ?array
    {
        return $this->codesWhereInstalled(false);
    }

    /** @return list<string> */
    private function codesWhereInstalled(bool $activeOnly): array
    {
        $query = $this->model()->clear()->where(Locals::schema_fields_IS_INSTALL, 1);
        if ($activeOnly) {
            $query->where(Locals::schema_fields_IS_ACTIVE, 1);
        }
        $rows = $query->select()->fetchArray();
        $codes = [];
        foreach ((array)$rows as $row) {
            if (is_array($row) && trim((string)($row[Locals::schema_fields_CODE] ?? '')) !== '') {
                $codes[] = (string)$row[Locals::schema_fields_CODE];
            }
        }

        return $codes;
    }

    private function model(): Locals
    {
        return ObjectManager::getInstance(Locals::class);
    }
}
