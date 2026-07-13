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
        $rows = $this->model()->clear()
            ->where(Locals::schema_fields_IS_INSTALL, 1)
            ->where(Locals::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetchArray();
        $codes = [];
        foreach ((array)$rows as $row) {
            if (is_array($row) && trim((string)($row[Locals::schema_fields_CODE] ?? '')) !== '') {
                $codes[] = (string)$row[Locals::schema_fields_CODE];
            }
        }
        return $codes;
    }

    public function currencyCodes(): array
    {
        return [];
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

    private function model(): Locals
    {
        return ObjectManager::getInstance(Locals::class);
    }
}
