<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Localization;

use Weline\Framework\App\Localization\LocaleNameProviderInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Locals;

final class LocaleNameProvider implements LocaleNameProviderInterface
{
    public function resolveName(string $sourceCode, string $targetCode): ?string
    {
        $local = ObjectManager::getInstance(Locals::class)->clear()
            ->where(Locals::schema_fields_CODE, $sourceCode)
            ->where(Locals::schema_fields_TARGET_CODE, $targetCode)
            ->find()
            ->fetch();
        return $local->getId() ? (string)$local->getName() : null;
    }
}
